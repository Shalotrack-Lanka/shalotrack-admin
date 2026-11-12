<?php

namespace Tests\Pgsql;

use App\Enums\DeviceStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\DeviceType;
use App\Models\Product;
use App\Models\SetupShalotrackDevice;
use App\Models\Sim;
use App\Models\Supplier;
use App\Models\SupplierDelivery;
use App\Models\SupplierDeliveryUnit;
use App\Models\SupplierInvoice;
use App\Services\Delivery\ScanBatch;
use App\Services\Delivery\SupplierDeliveryService;
use App\Services\Supplier\PurchaseOrders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsSupplierData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * RECEIVING A DELIVERY FROM A SUPPLIER BY SCANNING, on real PostgreSQL (autotest schema).
 * (The big limits are in SupplierReceivingLimitsTest; this file is the detail around them.)
 *
 *   GET    admin.suppliers.deliveries        the receive page (this supplier's types, open orders, last deliveries)
 *   POST   admin.suppliers.deliveries.store  save a delivery of IMEIs (devices) or ICCID + SIM number pairs (SIMs)
 *   DELETE admin.suppliers.deliveries.void   cancel a delivery made by mistake
 *
 * Rules in simple words:
 *   - scans are cleaned (stray spaces), checked (IMEI check digit, 19/20 digit ICCID, 10 digit SIM number), and a bad
 *     or repeated one is rejected with a reason while the good ones are still saved,
 *   - a unit already received, already registered, or already in the SIM list is never taken twice,
 *   - the delivery is saved against a purchase order of THIS supplier that is not cancelled and contains that goods,
 *   - the order is the limit, and each device type / the SIMs have their own limit,
 *   - received SIMs go into the SIM list as "Not Activated"; a delivery can be cancelled only while nothing was done
 *     with its units, and never after it was added to stock,
 *   - everything is written to the audit log, and the whole feature can be switched off (404),
 *   - only the company ADMIN may use it, and saving and cancelling are limited to 20 a minute.
 *
 * Everything made here is rolled back after each test. One login per test (data is made through the services).
 */
class SupplierDeliveryFlowPgTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsSupplierData;

    private ?Admin $admin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->quietApi();
        config(['supplier_deliveries.enabled' => true, 'supplier_deliveries.allow_typed_stock' => false, 'supplier_deliveries.require_purchase_order' => true]);
    }

    private function admin(): Admin
    {
        return $this->admin ??= $this->makeAdmin('ADMIN');
    }

    private function service(): SupplierDeliveryService
    {
        return app(SupplierDeliveryService::class);
    }

    /** A supplier that supplies one device type, with an open order for $qty of it. */
    private function deviceSetup(int $qty = 5): array
    {
        $supplier = $this->makeSupplier();
        $type = $this->makeDeviceType();
        $this->supplies($supplier, $type);
        $order = $this->orderFor($supplier, Product::where('device_type_id', $type->id)->firstOrFail(), $qty, 'Device');

        return [$supplier, $type, $order];
    }

    /** A supplier that supplies SIM cards, with an open order for $qty SIMs. */
    private function simSetup(int $qty = 5): array
    {
        $supplier = $this->makeSupplier();
        $product = $this->makePlainProduct('Autotest Mobitel SIM card');
        $this->offer($supplier, $product, 10, 0);

        return [$supplier, $this->orderFor($supplier, $product, $qty, 'SIM')];
    }

    private function orderFor(Supplier $supplier, Product $product, int $qty, string $type): SupplierInvoice
    {
        return app(PurchaseOrders::class)->create($supplier->id, [
            ['product_id' => $product->id, 'type' => $type, 'order_qty' => $qty, 'unit_price' => 100, 'discount' => 0, 'face_value' => 0],
        ], null);
    }

    private function imeis(int $n): array
    {
        return array_map(fn () => $this->validImei(), range(1, $n));
    }

    /** Receive devices through the service (no HTTP, no login used up). */
    private function received(Supplier $s, DeviceType $t, SupplierInvoice $o, array $imeis, ?string $reference = null): SupplierDelivery
    {
        return $this->service()->receive('DEVICE', $s->id, $t->id, $imeis, $reference, null, null, $o->id)['delivery'];
    }

    private function receivedSims(Supplier $s, SupplierInvoice $o, int $n = 1): SupplierDelivery
    {
        $scans = [];
        for ($i = 0; $i < $n; $i++) {
            $id = $this->simIdentity();
            $scans[] = ['iccid' => $id['iccid'], 'sim_number' => $id['sim_number']];
        }

        return $this->service()->receive('SIM', $s->id, null, $scans, null, null, null, $o->id, 'mobitel')['delivery'];
    }

    private function postDevices(Supplier $s, DeviceType $t, ?SupplierInvoice $o, array $scans, array $extra = [])
    {
        return $this->actingAs($this->admin())->postJson(route('admin.suppliers.deliveries.store', $s->id), array_merge(array_filter([
            'kind' => 'DEVICE', 'device_type_id' => $t->id, 'purchase_order_id' => $o?->id, 'scans' => $scans,
        ], fn ($v) => $v !== null), $extra));
    }

    private function postSims(Supplier $s, ?SupplierInvoice $o, array $scans, array $extra = [])
    {
        return $this->actingAs($this->admin())->postJson(route('admin.suppliers.deliveries.store', $s->id), array_merge(array_filter([
            'kind' => 'SIM', 'sim_type' => 'mobitel', 'purchase_order_id' => $o?->id, 'scans' => $scans,
        ], fn ($v) => $v !== null), $extra));
    }

    private function pair(): array
    {
        $id = $this->simIdentity();

        return ['iccid' => $id['iccid'], 'sim_number' => $id['sim_number']];
    }

    /** Add a delivery to stock the way the admin does (Manage Stock), so it gets its stock link. */
    private function stockIn(Supplier $s, DeviceType $t, SupplierDelivery $d): void
    {
        $this->actingAs($this->admin())->post(route('admin.stock.store'), ['supplier_id' => $s->id, 'device_type_id' => $t->id, 'delivery_id' => $d->id, 'stock_in' => 1])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($d->fresh()->stocked_in_at);
    }

    private function void(Supplier $s, $deliveryId, string $reason = 'scanned by mistake')
    {
        return $this->actingAs($this->admin())->delete(route('admin.suppliers.deliveries.void', [$s->id, $deliveryId]), ['reason' => $reason]);
    }

    // ================================================================== saving a device delivery

    public function test_a_device_delivery_is_saved_with_its_units_and_details(): void
    {
        [$s, $t, $o] = $this->deviceSetup();
        [$a, $b] = $this->imeis(2);

        $this->postDevices($s, $t, $o, [$a, $b], ['reference' => '  GRN-100  ', 'note' => 'two boxes'])
            ->assertOk()->assertJsonPath('accepted', 2)->assertJsonPath('rejected', []);

        $d = SupplierDelivery::where('supplier_id', $s->id)->firstOrFail();
        $this->assertSame('DEVICE', $d->kind);
        $this->assertSame($t->id, (int) $d->device_type_id);
        $this->assertSame($o->id, (int) $d->supplier_invoice_id);
        $this->assertSame('GRN-100', $d->reference);
        $this->assertSame('two boxes', $d->note);
        $this->assertSame(now()->toDateString(), $d->received_on->toDateString());
        $this->assertSame((string) $this->admin()->admin_id, (string) $d->received_by);
        $this->assertNull($d->stocked_in_at);
        $this->assertEqualsCanonicalizing([$a, $b], $d->units()->pluck('identifier')->all());
    }

    public function test_receiving_devices_does_not_change_stock_by_itself(): void
    {
        [$s, $t, $o] = $this->deviceSetup();
        $this->stockFor($t, 7);

        $this->postDevices($s, $t, $o, $this->imeis(2))->assertOk();

        $this->assertSame(7, (int) \App\Models\Stock::where('device_type_id', $t->id)->value('company_available_stock'));
    }

    public function test_stray_spaces_in_a_scan_are_removed(): void
    {
        [$s, $t, $o] = $this->deviceSetup();
        $imei = $this->validImei();
        $spaced = ' ' . substr($imei, 0, 4) . ' ' . substr($imei, 4, 6) . "\t" . substr($imei, 10) . ' ';

        $this->postDevices($s, $t, $o, [$spaced])->assertOk()->assertJsonPath('accepted', 1);

        $this->assertSame([$imei], SupplierDeliveryUnit::whereHas('delivery', fn ($q) => $q->where('supplier_id', $s->id))->pluck('identifier')->all());
    }

    public function test_a_wrong_imei_is_rejected_with_a_reason_and_the_good_ones_are_still_saved(): void
    {
        [$s, $t, $o] = $this->deviceSetup();
        $good = $this->validImei();
        $wrongDigit = substr($good, 0, 14) . (((int) $good[14] + 1) % 10);

        $r = $this->postDevices($s, $t, $o, [$good, $wrongDigit, 'ABC123', '12345'])->assertOk();

        $r->assertJsonPath('accepted', 1);
        $this->assertCount(3, $r->json('rejected'));
        $this->assertStringContainsString('Not a valid IMEI', $r->json('rejected.0.reason'));
        $this->assertSame([$good], SupplierDeliveryUnit::whereHas('delivery', fn ($q) => $q->where('supplier_id', $s->id))->pluck('identifier')->all());
    }

    public function test_when_every_scan_is_bad_nothing_is_saved_and_no_delivery_is_made(): void
    {
        [$s, $t, $o] = $this->deviceSetup();

        $r = $this->postDevices($s, $t, $o, ['ABC', '123'])->assertOk();

        $r->assertJsonPath('accepted', 0)->assertJsonPath('delivery_id', null);
        $this->assertSame(0, SupplierDelivery::where('supplier_id', $s->id)->count());
    }

    public function test_a_unit_scanned_twice_in_one_delivery_is_taken_once(): void
    {
        [$s, $t, $o] = $this->deviceSetup();
        $imei = $this->validImei();

        $r = $this->postDevices($s, $t, $o, [$imei, $imei])->assertOk();

        $r->assertJsonPath('accepted', 1);
        $this->assertSame('Scanned twice in this delivery.', $r->json('rejected.0.reason'));
    }

    public function test_a_unit_already_received_on_another_delivery_is_refused_and_names_that_delivery(): void
    {
        [$s, $t, $o] = $this->deviceSetup();
        $imei = $this->validImei();
        $first = $this->received($s, $t, $o, [$imei]);

        $r = $this->postDevices($s, $t, $o, [$imei, $this->validImei()])->assertOk();

        $r->assertJsonPath('accepted', 1);
        $this->assertStringContainsString("Already received on delivery #{$first->id} from {$s->name}", $r->json('rejected.0.reason'));
        $this->assertSame(1, SupplierDeliveryUnit::where('identifier', $imei)->count());
    }

    public function test_a_unit_that_is_already_a_registered_device_is_not_new_stock(): void
    {
        [$s, $t, $o] = $this->deviceSetup();
        $imei = $this->validImei();
        SetupShalotrackDevice::create([
            'device_category' => $t->device_category, 'imei_number' => $imei, 'sim_number' => '0700000999',
            'iccid' => '8994900000000000999', 'status' => DeviceStatus::Activated->value,
        ]);

        $r = $this->postDevices($s, $t, $o, [$imei])->assertOk();

        $r->assertJsonPath('accepted', 0);
        $this->assertSame('Already registered as a device in the system.', $r->json('rejected.0.reason'));
        $this->assertSame(0, SupplierDelivery::where('supplier_id', $s->id)->count());
    }

    public function test_a_delivery_of_a_different_supplier_cannot_take_the_same_unit(): void
    {
        [$s, $t, $o] = $this->deviceSetup();
        [$s2, $t2, $o2] = $this->deviceSetup();
        $imei = $this->validImei();
        $this->received($s, $t, $o, [$imei]);

        $r = $this->postDevices($s2, $t2, $o2, [$imei])->assertOk();

        $r->assertJsonPath('accepted', 0);
        $this->assertStringContainsString($s->name, $r->json('rejected.0.reason'));
    }

    // ================================================================== the order and the device type

    public function test_a_purchase_order_is_required_and_the_message_says_so(): void
    {
        [$s, $t] = $this->deviceSetup();

        $this->postDevices($s, $t, null, [$this->validImei()])->assertStatus(422)->assertJsonValidationErrors('purchase_order_id');
        $this->assertSame(0, SupplierDelivery::where('supplier_id', $s->id)->count());
    }

    public function test_even_with_the_order_rule_switched_off_a_delivery_still_needs_an_order(): void
    {
        config(['supplier_deliveries.require_purchase_order' => false]);
        [$s, $t] = $this->deviceSetup();

        $r = $this->postDevices($s, $t, null, [$this->validImei()])->assertStatus(422);

        $r->assertJsonValidationErrors('purchase_order_id');
        $this->assertStringContainsString('place a purchase order for it first', $r->json('errors.purchase_order_id.0'));
    }

    public function test_an_order_of_another_supplier_is_refused(): void
    {
        [$s, $t] = $this->deviceSetup();
        [, , $theirs] = $this->deviceSetup();

        $this->postDevices($s, $t, $theirs, [$this->validImei()])
            ->assertStatus(422)->assertJsonPath('errors.purchase_order_id.0', 'That purchase order is not from this supplier.');
    }

    public function test_an_order_that_does_not_exist_is_refused(): void
    {
        [$s, $t] = $this->deviceSetup();

        $this->actingAs($this->admin())->postJson(route('admin.suppliers.deliveries.store', $s->id), [
            'kind' => 'DEVICE', 'device_type_id' => $t->id, 'purchase_order_id' => 999999999, 'scans' => [$this->validImei()],
        ])->assertStatus(422)->assertJsonValidationErrors('purchase_order_id');
    }

    public function test_a_cancelled_order_is_refused(): void
    {
        [$s, $t, $o] = $this->deviceSetup();
        app(PurchaseOrders::class)->cancel($o, 'ordered by mistake', null);

        $this->postDevices($s, $t, $o, [$this->validImei()])
            ->assertStatus(422)->assertJsonPath('errors.purchase_order_id.0', "Purchase order {$o->invoice_number} is cancelled.");
    }

    public function test_an_order_that_does_not_include_this_device_type_is_refused(): void
    {
        [$s, , $o] = $this->deviceSetup();
        $other = $this->makeDeviceType();
        $this->supplies($s, $other);

        $this->postDevices($s, $other, $o, [$this->validImei()])
            ->assertStatus(422)->assertJsonPath('errors.purchase_order_id.0', "Purchase order {$o->invoice_number} does not include this device type.");
    }

    public function test_a_device_type_the_supplier_does_not_supply_is_refused(): void
    {
        [$s, , $o] = $this->deviceSetup();
        $stranger = $this->makeDeviceType();

        $this->postDevices($s, $stranger, $o, [$this->validImei()])->assertStatus(422)->assertJsonValidationErrors('device_type_id');
    }

    public function test_the_device_type_is_required_and_must_exist(): void
    {
        [$s, , $o] = $this->deviceSetup();

        $this->actingAs($this->admin())->postJson(route('admin.suppliers.deliveries.store', $s->id), [
            'kind' => 'DEVICE', 'purchase_order_id' => $o->id, 'scans' => [$this->validImei()],
        ])->assertStatus(422)->assertJsonValidationErrors('device_type_id');
        $this->postJson(route('admin.suppliers.deliveries.store', $s->id), [
            'kind' => 'DEVICE', 'device_type_id' => 999999999, 'purchase_order_id' => $o->id, 'scans' => [$this->validImei()],
        ])->assertStatus(422)->assertJsonValidationErrors('device_type_id');
    }

    public function test_each_device_type_on_an_order_has_its_own_limit(): void
    {
        $s = $this->makeSupplier();
        [$tA, $tB] = [$this->makeDeviceType(), $this->makeDeviceType()];
        $this->supplies($s, $tA);
        $this->supplies($s, $tB);
        $o = app(PurchaseOrders::class)->create($s->id, [
            ['product_id' => Product::where('device_type_id', $tA->id)->value('id'), 'type' => 'Device', 'order_qty' => 1, 'unit_price' => 100, 'discount' => 0, 'face_value' => 0],
            ['product_id' => Product::where('device_type_id', $tB->id)->value('id'), 'type' => 'Device', 'order_qty' => 2, 'unit_price' => 100, 'discount' => 0, 'face_value' => 0],
        ], null);

        $this->postDevices($s, $tA, $o, $this->imeis(1))->assertOk();
        $this->postDevices($s, $tA, $o, $this->imeis(1))->assertStatus(422)->assertJsonValidationErrors('scans');
        $this->postDevices($s, $tB, $o, $this->imeis(2))->assertOk()->assertJsonPath('accepted', 2);
    }

    public function test_the_too_many_message_says_how_many_are_left_and_how_many_to_remove(): void
    {
        [$s, $t, $o] = $this->deviceSetup(3);
        $this->postDevices($s, $t, $o, $this->imeis(1))->assertOk();

        $msg = $this->postDevices($s, $t, $o, $this->imeis(4))->assertStatus(422)->json('errors.scans.0');

        $this->assertStringContainsString('has only 2', $msg);
        $this->assertStringContainsString('(3 ordered, 1 received)', $msg);
        $this->assertStringContainsString('Remove 2 and save again. Nothing was saved.', $msg);
        $this->assertSame(1, SupplierDeliveryUnit::whereHas('delivery', fn ($q) => $q->where('supplier_id', $s->id))->count());
    }

    public function test_a_fully_received_order_says_so(): void
    {
        [$s, $t, $o] = $this->deviceSetup(1);
        $this->postDevices($s, $t, $o, $this->imeis(1))->assertOk();

        $msg = $this->postDevices($s, $t, $o, $this->imeis(1))->assertStatus(422)->json('errors.scans.0');

        $this->assertStringContainsString('already fully received', $msg);
    }

    public function test_an_inactive_supplier_cannot_receive_a_delivery(): void
    {
        [$s, $t, $o] = $this->deviceSetup();
        $s->update(['status' => 'Inactive']);

        $this->postDevices($s, $t, $o, [$this->validImei()])->assertStatus(422)->assertJsonValidationErrors('supplier_id');
    }

    public function test_a_supplier_that_does_not_exist_cannot_receive_a_delivery(): void
    {
        [, $t, $o] = $this->deviceSetup();

        $this->actingAs($this->admin())->postJson(route('admin.suppliers.deliveries.store', 999999999), [
            'kind' => 'DEVICE', 'device_type_id' => $t->id, 'purchase_order_id' => $o->id, 'scans' => [$this->validImei()],
        ])->assertStatus(422)->assertJsonValidationErrors('supplier_id');
    }

    // ================================================================== the request is checked first

    public static function badRequests(): array
    {
        $imei = '490154203237518';

        return [
            'no kind'                  => [['kind' => null], 'kind'],
            'unknown kind'             => [['kind' => 'BOX'], 'kind'],
            'no scans'                 => [['scans' => null], 'scans'],
            'empty scans'              => [['scans' => []], 'scans'],
            'scans not a list'         => [['scans' => 'text'], 'scans'],
            'too many scans'           => [['scans' => array_fill(0, ScanBatch::MAX + 1, $imei)], 'scans'],
            'a scan that is too long'  => [['scans' => [str_repeat('1', 33)]], 'scans.0'],
            'a scan that is a list'    => [['scans' => [[$imei]]], 'scans.0'],
            'a scan that is empty'     => [['scans' => ['']], 'scans.0'],
            'text device type id'      => [['device_type_id' => 'abc'], 'device_type_id'],
            'zero device type id'      => [['device_type_id' => 0], 'device_type_id'],
            'text order id'            => [['purchase_order_id' => 'abc'], 'purchase_order_id'],
            'negative order id'        => [['purchase_order_id' => -1], 'purchase_order_id'],
            'reference too long'       => [['reference' => str_repeat('r', 101)], 'reference'],
            'note too long'            => [['note' => str_repeat('n', 501)], 'note'],
        ];
    }

    #[DataProvider('badRequests')]
    public function test_a_bad_request_is_refused_and_nothing_is_saved(array $change, string $field): void
    {
        [$s, $t, $o] = $this->deviceSetup();
        $good = ['kind' => 'DEVICE', 'device_type_id' => $t->id, 'purchase_order_id' => $o->id, 'scans' => [$this->validImei()]];

        $this->actingAs($this->admin())->postJson(route('admin.suppliers.deliveries.store', $s->id), array_filter(array_merge($good, $change), fn ($v) => $v !== null))
            ->assertStatus(422)->assertJsonValidationErrors($field);

        $this->assertSame(0, SupplierDelivery::where('supplier_id', $s->id)->count());
    }

    public function test_exactly_500_scans_are_accepted_by_the_request_check(): void
    {
        [$s, $t, $o] = $this->deviceSetup(500);

        $this->postDevices($s, $t, $o, $this->imeis(ScanBatch::MAX))->assertOk()->assertJsonPath('accepted', ScanBatch::MAX);
    }

    public function test_the_audit_log_records_a_received_delivery(): void
    {
        [$s, $t, $o] = $this->deviceSetup();
        [$a, $b] = $this->imeis(2);
        $id = $this->postDevices($s, $t, $o, [$a, $b], ['reference' => 'GRN-AUDIT'])->assertOk()->json('delivery_id');

        $log = AuditLog::where('action', 'supplier_delivery.received')->where('subject_id', (string) $id)->firstOrFail();

        $this->assertSame($s->name . ' (devices)', $log->subject_label);
        $this->assertSame(2, (int) $log->meta['count']);
        $this->assertSame('GRN-AUDIT', $log->meta['reference']);
        $this->assertSame($o->id, (int) $log->meta['purchase_order']);
        $this->assertEqualsCanonicalizing([$a, $b], $log->meta['identifiers']);
        $this->assertSame((string) $this->admin()->admin_id, (string) $log->actor_id);
    }

    public function test_a_refused_delivery_leaves_no_audit_entry(): void
    {
        [$s, $t, $o] = $this->deviceSetup(1);
        $before = AuditLog::where('action', 'supplier_delivery.received')->count();

        $this->postDevices($s, $t, $o, $this->imeis(3))->assertStatus(422);
        $this->postDevices($s, $t, $o, ['ABC'])->assertOk();

        $this->assertSame($before, AuditLog::where('action', 'supplier_delivery.received')->count());
    }

    // ================================================================== SIM deliveries

    public function test_a_sim_delivery_saves_each_sim_as_not_activated_with_the_chosen_type(): void
    {
        [$s, $o] = $this->simSetup();
        [$p1, $p2] = [$this->pair(), $this->pair()];

        $this->postSims($s, $o, [$p1, $p2], ['sim_type' => ' Dialog '])->assertOk()->assertJsonPath('accepted', 2);

        foreach ([$p1, $p2] as $p) {
            $sim = Sim::where('iccid', $p['iccid'])->firstOrFail();
            $this->assertSame($p['sim_number'], $sim->sim_number);
            $this->assertSame('dialog', $sim->sim_type);
            $this->assertSame('Not Activated', $sim->sim_status);
            $this->assertNull($sim->imsi);
            $unit = SupplierDeliveryUnit::where('identifier', $p['iccid'])->firstOrFail();
            $this->assertSame($p['sim_number'], $unit->sim_number);
        }
    }

    public function test_the_sim_type_is_required_and_must_be_dialog_or_mobitel(): void
    {
        [$s, $o] = $this->simSetup();

        foreach ([null, '', 'airtel', 'hutch'] as $bad) {
            $this->actingAs($this->admin())->postJson(route('admin.suppliers.deliveries.store', $s->id), array_filter([
                'kind' => 'SIM', 'sim_type' => $bad, 'purchase_order_id' => $o->id, 'scans' => [$this->pair()],
            ], fn ($v) => $v !== null))->assertStatus(422)->assertJsonValidationErrors('sim_type');
        }
        $this->assertSame(0, SupplierDelivery::where('supplier_id', $s->id)->count());
    }

    public function test_a_supplier_without_a_sim_product_cannot_deliver_sims(): void
    {
        [$s, $t, $o] = $this->deviceSetup();

        $this->postSims($s, $o, [$this->pair()])->assertStatus(422)->assertJsonValidationErrors('kind');
    }

    public function test_an_order_with_no_sim_line_does_not_accept_sims(): void
    {
        [$s, ,] = $this->deviceSetup();
        $sim = $this->makePlainProduct('Autotest Dialog SIM');
        $this->offer($s, $sim, 10, 0);
        [, , $deviceOrder] = [null, null, SupplierInvoice::where('supplier_id', $s->id)->firstOrFail()];

        $this->postSims($s, $deviceOrder, [$this->pair()])
            ->assertStatus(422)->assertJsonPath('errors.purchase_order_id.0', "Purchase order {$deviceOrder->invoice_number} does not include SIM cards.");
    }

    public function test_sim_scans_are_checked_one_by_one(): void
    {
        [$s, $o] = $this->simSetup(10);
        $good = $this->pair();
        $shortIccid = ['iccid' => '12345', 'sim_number' => '0701234567'];
        $badNumber = ['iccid' => $this->simIdentity()['iccid'], 'sim_number' => '12345'];
        $letters = ['iccid' => $this->simIdentity()['iccid'], 'sim_number' => '07AB234567'];

        $r = $this->postSims($s, $o, [$good, $shortIccid, $badNumber, $letters])->assertOk();

        $r->assertJsonPath('accepted', 1);
        $reasons = array_column($r->json('rejected'), 'reason');
        $this->assertStringContainsString('Not a valid SIM ICCID', $reasons[0]);
        $this->assertSame('The SIM number must be exactly 10 digits.', $reasons[1]);
        $this->assertSame('The SIM number must be exactly 10 digits.', $reasons[2]);
    }

    public function test_the_same_iccid_or_sim_number_twice_in_one_delivery_is_taken_once(): void
    {
        [$s, $o] = $this->simSetup(10);
        $a = $this->pair();
        $sameIccid = ['iccid' => $a['iccid'], 'sim_number' => $this->simIdentity()['sim_number']];
        $sameNumber = ['iccid' => $this->simIdentity()['iccid'], 'sim_number' => $a['sim_number']];

        $r = $this->postSims($s, $o, [$a, $sameIccid, $sameNumber])->assertOk();

        $r->assertJsonPath('accepted', 1);
        $this->assertSame('Scanned twice in this delivery.', $r->json('rejected.0.reason'));
        $this->assertStringContainsString('is on another SIM in this delivery', $r->json('rejected.1.reason'));
    }

    public function test_a_sim_already_in_the_sim_list_or_with_a_used_number_is_refused(): void
    {
        [$s, $o] = $this->simSetup(10);
        $existing = $this->makeSim('Not Activated');
        $clashNumber = ['iccid' => $this->simIdentity()['iccid'], 'sim_number' => $existing->sim_number];

        $r = $this->postSims($s, $o, [['iccid' => $existing->iccid, 'sim_number' => $this->simIdentity()['sim_number']], $clashNumber])->assertOk();

        $r->assertJsonPath('accepted', 0);
        $this->assertSame('Already in the SIM list.', $r->json('rejected.0.reason'));
        $this->assertSame("SIM number {$existing->sim_number} is already registered to another SIM.", $r->json('rejected.1.reason'));
    }

    public function test_a_sim_attached_to_a_registered_device_is_refused(): void
    {
        [$s, $o] = $this->simSetup();
        $p = $this->pair();
        SetupShalotrackDevice::create([
            'device_category' => 'AUTOTEST-SIM-ON-DEVICE', 'imei_number' => $this->validImei(), 'sim_number' => $p['sim_number'],
            'iccid' => $p['iccid'], 'status' => DeviceStatus::Activated->value,
        ]);

        $r = $this->postSims($s, $o, [$p])->assertOk();

        $r->assertJsonPath('accepted', 0);
        $this->assertSame('Already attached to a registered device.', $r->json('rejected.0.reason'));
    }

    public function test_a_sim_scan_must_have_exactly_an_iccid_and_a_sim_number(): void
    {
        [$s, $o] = $this->simSetup();
        $p = $this->pair();

        foreach ([[$p['iccid']], [['iccid' => $p['iccid']]], [['sim_number' => $p['sim_number']]], [$p + ['imsi' => '123']]] as $scans) {
            $this->postSims($s, $o, $scans)->assertStatus(422);
        }
        $this->assertSame(0, Sim::where('iccid', $p['iccid'])->count());
    }

    public function test_the_sim_limit_is_the_number_of_sims_ordered(): void
    {
        [$s, $o] = $this->simSetup(2);

        $this->postSims($s, $o, [$this->pair(), $this->pair(), $this->pair()])->assertStatus(422)->assertJsonValidationErrors('scans');
        $this->assertSame(0, SupplierDelivery::where('supplier_id', $s->id)->count());
        $this->postSims($s, $o, [$this->pair(), $this->pair()])->assertOk()->assertJsonPath('accepted', 2);
    }

    // ================================================================== cancelling a delivery

    public function test_a_cancelled_device_delivery_is_removed_so_its_units_can_be_scanned_again(): void
    {
        [$s, $t, $o] = $this->deviceSetup();
        $imeis = $this->imeis(2);
        $d = $this->received($s, $t, $o, $imeis);

        $this->void($s, $d->id)->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.suppliers.deliveries', $s->id))
            ->assertSessionHas('success', "Delivery #{$d->id} was cancelled. Its units can be scanned again.");

        $this->assertNull(SupplierDelivery::find($d->id));
        $this->assertSame(0, SupplierDeliveryUnit::whereIn('identifier', $imeis)->count());
        $this->postDevices($s, $t, $o, $imeis)->assertOk()->assertJsonPath('accepted', 2);
    }

    public function test_a_delivery_already_in_stock_cannot_be_cancelled(): void
    {
        [$s, $t, $o] = $this->deviceSetup();
        $d = $this->received($s, $t, $o, $this->imeis(1));
        $this->stockIn($s, $t, $d);

        $this->void($s, $d->id)->assertSessionHasErrors(['delivery_id' => 'This delivery is already in stock and cannot be cancelled.']);

        $this->assertNotNull(SupplierDelivery::find($d->id));
    }

    public function test_a_delivery_with_a_registered_unit_cannot_be_cancelled(): void
    {
        [$s, $t, $o] = $this->deviceSetup();
        $d = $this->received($s, $t, $o, $this->imeis(2));
        $d->units()->first()->forceFill(['registered_at' => now()])->save();

        $this->void($s, $d->id)->assertSessionHasErrors(['delivery_id' => 'Some units of this delivery are already registered and cannot be cancelled.']);

        $this->assertSame(2, SupplierDeliveryUnit::where('supplier_delivery_id', $d->id)->count());
    }

    public function test_a_cancelled_sim_delivery_takes_its_sims_out_of_the_sim_list(): void
    {
        [$s, $o] = $this->simSetup();
        $d = $this->receivedSims($s, $o, 2);
        $iccids = $d->units()->pluck('identifier')->all();
        $this->assertSame(2, Sim::whereIn('iccid', $iccids)->count());

        $this->void($s, $d->id)->assertSessionHasNoErrors();

        $this->assertSame(0, Sim::whereIn('iccid', $iccids)->count());
        $this->assertNull(SupplierDelivery::find($d->id));
    }

    public function test_a_sim_delivery_cannot_be_cancelled_once_a_sim_was_activated(): void
    {
        [$s, $o] = $this->simSetup();
        $d = $this->receivedSims($s, $o, 2);
        Sim::where('iccid', $d->units()->first()->identifier)->update(['sim_status' => 'Activated']);

        $this->void($s, $d->id)->assertSessionHasErrors('delivery_id');

        $this->assertSame(2, Sim::whereIn('iccid', $d->units()->pluck('identifier'))->count(), 'no SIM is removed when one is in use');
        $this->assertNotNull(SupplierDelivery::find($d->id));
    }

    public function test_a_sim_delivery_cannot_be_cancelled_once_a_sim_is_on_a_device(): void
    {
        [$s, $o] = $this->simSetup();
        $d = $this->receivedSims($s, $o, 1);
        $unit = $d->units()->firstOrFail();
        SetupShalotrackDevice::create([
            'device_category' => 'AUTOTEST-VOID-SIM', 'imei_number' => $this->validImei(), 'sim_number' => $unit->sim_number,
            'iccid' => $unit->identifier, 'status' => DeviceStatus::Activated->value,
        ]);

        $this->void($s, $d->id)->assertSessionHasErrors('delivery_id');

        $this->assertNotNull(SupplierDelivery::find($d->id));
    }

    public function test_a_delivery_of_another_supplier_cannot_be_cancelled_through_this_one(): void
    {
        [$s, $t, $o] = $this->deviceSetup();
        [$other] = $this->deviceSetup();
        $d = $this->received($s, $t, $o, $this->imeis(1));

        $this->void($other, $d->id)->assertSessionHasErrors(['delivery_id' => 'Delivery not found.']);

        $this->assertNotNull(SupplierDelivery::find($d->id));
    }

    public function test_a_delivery_that_does_not_exist_or_a_text_id_is_a_clean_error(): void
    {
        [$s] = $this->deviceSetup();

        $this->void($s, 999999999)->assertSessionHasErrors(['delivery_id' => 'Delivery not found.']);
        $this->delete('/admin/supplier/' . $s->id . '/deliveries/abc', ['reason' => 'scanned by mistake'])->assertNotFound();
    }

    public function test_the_cancel_reason_must_be_between_3_and_200_letters(): void
    {
        [$s, $t, $o] = $this->deviceSetup();
        $d = $this->received($s, $t, $o, $this->imeis(1));

        foreach ([null, '', 'ab', str_repeat('x', 201)] as $bad) {
            $this->actingAs($this->admin())->delete(route('admin.suppliers.deliveries.void', [$s->id, $d->id]), ['reason' => $bad])->assertSessionHasErrors('reason');
        }
        $this->assertNotNull(SupplierDelivery::find($d->id));

        $this->void($s, $d->id, str_repeat('x', 200))->assertSessionHasNoErrors();
        $this->assertNull(SupplierDelivery::find($d->id));
    }

    public function test_a_cancel_is_written_to_the_audit_log_with_the_reason_and_the_units(): void
    {
        [$s, $t, $o] = $this->deviceSetup();
        $imeis = $this->imeis(2);
        $d = $this->received($s, $t, $o, $imeis);

        $this->void($s, $d->id, '  wrong box  ')->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'supplier_delivery.voided')->where('subject_id', (string) $d->id)->firstOrFail();
        $this->assertSame('wrong box', $log->meta['reason']);
        $this->assertSame('DEVICE', $log->meta['kind']);
        $this->assertSame(2, (int) $log->meta['count']);
        $this->assertEqualsCanonicalizing($imeis, $log->meta['identifiers']);
        $this->assertSame((string) $this->admin()->admin_id, (string) $log->actor_id);
    }

    public function test_a_refused_cancel_leaves_no_audit_entry(): void
    {
        [$s, $t, $o] = $this->deviceSetup();
        $d = $this->received($s, $t, $o, $this->imeis(1));
        $this->stockIn($s, $t, $d);
        $before = AuditLog::where('action', 'supplier_delivery.voided')->count();

        $this->void($s, $d->id)->assertSessionHasErrors('delivery_id');

        $this->assertSame($before, AuditLog::where('action', 'supplier_delivery.voided')->count());
    }

    // ================================================================== the receive page

    public function test_the_receive_page_offers_this_suppliers_types_orders_and_last_deliveries_only(): void
    {
        [$s, $t, $o] = $this->deviceSetup();
        [$other, $ot, $oo] = $this->deviceSetup();
        $this->received($s, $t, $o, $this->imeis(1), 'REF-MINE-AUTOTEST');
        $this->received($other, $ot, $oo, $this->imeis(1), 'REF-THEIRS-AUTOTEST');

        $html = $this->actingAs($this->admin())->get(route('admin.suppliers.deliveries', $s->id))->assertOk()->getContent();

        $this->assertStringContainsString($s->name, $html);
        $this->assertStringContainsString($t->device_category, $html);
        $this->assertStringNotContainsString($ot->device_category, $html);
        $this->assertStringContainsString($o->invoice_number, $html);
        $this->assertStringNotContainsString($oo->invoice_number, $html);
        $this->assertStringContainsString('REF-MINE-AUTOTEST', $html);
        $this->assertStringNotContainsString('REF-THEIRS-AUTOTEST', $html);
    }

    public function test_the_receive_page_says_when_nothing_was_received_yet(): void
    {
        [$s] = $this->deviceSetup();

        $this->actingAs($this->admin())->get(route('admin.suppliers.deliveries', $s->id))->assertOk()->assertSee('No deliveries recorded yet.');
    }

    public function test_the_receive_page_shows_at_most_the_last_15_deliveries(): void
    {
        [$s, $t, $o] = $this->deviceSetup(20);
        for ($i = 1; $i <= 16; $i++) {
            $this->received($s, $t, $o, $this->imeis(1), sprintf('REF-SEQ-%02d-AUTOTEST', $i));
        }

        $html = $this->actingAs($this->admin())->get(route('admin.suppliers.deliveries', $s->id))->assertOk()->getContent();

        $this->assertStringContainsString('REF-SEQ-16-AUTOTEST', $html);
        $this->assertStringContainsString('REF-SEQ-02-AUTOTEST', $html);
        $this->assertStringNotContainsString('REF-SEQ-01-AUTOTEST', $html, 'the oldest one is not on the page');
    }

    public function test_the_receive_page_of_an_inactive_supplier_sends_the_admin_back_with_a_reason(): void
    {
        [$s] = $this->deviceSetup();
        $s->update(['status' => 'Inactive']);

        $this->actingAs($this->admin())->get(route('admin.suppliers.deliveries', $s->id))
            ->assertRedirect(route('admin.suppliers'))
            ->assertSessionHasErrors('status');
    }

    public function test_a_supplier_that_does_not_exist_or_a_text_id_gives_a_404_on_the_receive_page(): void
    {
        $this->actingAs($this->admin());

        $this->get('/admin/supplier/999999999/deliveries')->assertNotFound();
        $this->get('/admin/supplier/abc/deliveries')->assertNotFound();
    }

    // ================================================================== the switch and the limits

    public function test_when_the_feature_is_switched_off_every_delivery_page_answers_404(): void
    {
        [$s, $t, $o] = $this->deviceSetup();
        $d = $this->received($s, $t, $o, $this->imeis(1));
        config(['supplier_deliveries.enabled' => false]);
        $this->actingAs($this->admin());

        $this->get(route('admin.suppliers.deliveries', $s->id))->assertNotFound();
        $this->postJson(route('admin.suppliers.deliveries.store', $s->id), ['kind' => 'DEVICE'])->assertNotFound();
        $this->delete(route('admin.suppliers.deliveries.void', [$s->id, $d->id]), ['reason' => 'scanned by mistake'])->assertNotFound();
        $this->assertNotNull(SupplierDelivery::find($d->id), 'recorded deliveries stay in the database');
    }

    public function test_saving_a_delivery_is_limited_to_20_tries_a_minute(): void
    {
        [$s, $t, $o] = $this->deviceSetup();
        $this->actingAs($this->admin());

        for ($i = 0; $i < 20; $i++) {
            $this->postJson(route('admin.suppliers.deliveries.store', $s->id), ['kind' => 'DEVICE'])->assertStatus(422);
        }

        $this->postJson(route('admin.suppliers.deliveries.store', $s->id), ['kind' => 'DEVICE', 'device_type_id' => $t->id, 'purchase_order_id' => $o->id, 'scans' => $this->imeis(1)])->assertStatus(429);
        $this->assertSame(0, SupplierDelivery::where('supplier_id', $s->id)->count());
    }

    public function test_cancelling_a_delivery_is_limited_to_20_tries_a_minute(): void
    {
        [$s] = $this->deviceSetup();
        $this->actingAs($this->admin());

        for ($i = 0; $i < 20; $i++) {
            $this->delete(route('admin.suppliers.deliveries.void', [$s->id, 1]), ['reason' => ''])->assertSessionHasErrors('reason');
        }

        $this->delete(route('admin.suppliers.deliveries.void', [$s->id, 1]), ['reason' => 'scanned by mistake'])->assertStatus(429);
    }

    // ================================================================== who may use it

    public static function otherRoles(): array
    {
        return ['dealer' => ['DEALER'], 'distributor' => ['DISTRIBUTOR'], 'finance' => ['FINANCE'], 'technician' => ['TECHNICIAN'], 'supplier' => ['SUPPLIER']];
    }

    #[DataProvider('otherRoles')]
    public function test_only_the_company_admin_can_receive_or_cancel_a_delivery(string $role): void
    {
        [$s, $t, $o] = $this->deviceSetup();
        $d = $this->received($s, $t, $o, $this->imeis(1));
        $user = $this->makeAdmin($role);

        $this->actingAs($user)->get(route('admin.suppliers.deliveries', $s->id))->assertForbidden();
        $this->actingAs($user)->postJson(route('admin.suppliers.deliveries.store', $s->id), [
            'kind' => 'DEVICE', 'device_type_id' => $t->id, 'purchase_order_id' => $o->id, 'scans' => $this->imeis(1),
        ])->assertForbidden();
        $this->actingAs($user)->delete(route('admin.suppliers.deliveries.void', [$s->id, $d->id]), ['reason' => 'scanned by mistake'])->assertForbidden();

        $this->assertSame(1, SupplierDelivery::where('supplier_id', $s->id)->count());
    }

    public function test_a_visitor_who_is_not_signed_in_is_sent_to_login(): void
    {
        $this->get('/admin/supplier/1/deliveries')->assertRedirect();
        $this->post('/admin/supplier/1/deliveries', [])->assertRedirect();
        $this->delete('/admin/supplier/1/deliveries/1', [])->assertRedirect();
    }
}