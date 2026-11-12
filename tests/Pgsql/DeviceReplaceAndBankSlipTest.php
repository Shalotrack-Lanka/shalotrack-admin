<?php

namespace Tests\Pgsql;

use App\Models\ActivatedDevice;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Dealer;
use App\Models\SetupShalotrackDevice;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsReportData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * DEVICE REPLACEMENT and BANK SLIP VIEWING (Customer Device Management), on real PostgreSQL (autotest schema).
 *
 *   POST admin.customer-device-management.replace    ADMIN: swap a faulty device on a customer's vehicle for another one
 *   GET  admin.customer-device-management.bank-slip  ADMIN and FINANCE: open the bank slip of a payment
 *
 * What the tests prove (simple words):
 *   - the paid service stays exactly as it was: package, dates and payment status do NOT move to a "new" period,
 *     only the physical device changes,
 *   - the faulty device becomes "Broken Device" and the new one becomes "Activated", the vehicle now shows the new
 *     IMEI, SIM and category, and nothing else on the customer's row changes,
 *   - only a device that is free to bind can be used: activated company stock, or one assigned to a customer. A device
 *     already on a vehicle, one sitting in a dealer's unsold stock, one not activated yet, a broken one, an unknown
 *     number or the SAME device is refused and NOTHING changes,
 *   - the reason is required; the install date cannot be in the future; the installer name has a length limit,
 *   - the app server is told (both devices, the move, the subscription status); when it cannot be reached the
 *     replacement is still saved and the person is told what to run,
 *   - every replacement is audited with who, old and new IMEI and the reason,
 *   - the bank slip opens only for ADMIN and FINANCE, is never public, and every viewing is audited; a missing slip
 *     or file is a clean 404,
 *   - everybody else (dealer, distributor, technician, supplier, visitors) is stopped.
 *
 * The app server is FAKED (no real network). Files go to a fake disk. Everything is rolled back after each test.
 */
class DeviceReplaceAndBankSlipTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsReportData;

    private const API = 'https://api.test';

    private ?Admin $admin = null;
    /** null = the app server says yes; 'down' = cannot connect; 500 = error; or ['replacement' => 409] to fail one step. */
    private int|string|array|null $apiMode = null;
    /** @var array<int,ClientRequest> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
        config(['services.shalotrack_api.base_url' => self::API, 'services.shalotrack_api.sync_key' => 'autotest-sync-key']);

        Http::fake(function (ClientRequest $request) {
            $this->sent[] = $request;
            if ($this->apiMode === 'down') {
                throw new ConnectionException('cURL error 7: could not connect');
            }
            if ($this->apiMode === 500) {
                return Http::response(['message' => 'boom'], 500);
            }
            if (is_array($this->apiMode)) {
                foreach ($this->apiMode as $part => $status) {
                    if (str_contains($request->url(), $part)) {
                        return Http::response(['message' => 'refused'], $status);
                    }
                }
            }

            return Http::response(['data' => []], 200);
        });
    }

    private function admin(): Admin
    {
        return $this->admin ??= $this->makeAdmin('ADMIN');
    }

    /** A device that is free to bind: activated, company stock, not on any vehicle. */
    private function freeDevice(array $over = []): SetupShalotrackDevice
    {
        $n = $this->nextIntakeNumber();

        return SetupShalotrackDevice::create(array_merge([
            'device_category' => 'AUTOTEST-NEW-CAT',
            'imei_number'     => $this->validImei(),
            'sim_number'      => '07' . str_pad((string) $n, 9, '1', STR_PAD_LEFT),
            'status'          => 'Activated',
        ], $over));
    }

    /** A vehicle with a PAID device on it, and the setup row of that device. */
    private function paidVehicle(array $over = []): array
    {
        $bound = $this->makeBound(array_merge([
            'payment_status'          => 'Paid',
            'subscription_model'      => '1 Year',
            'subscription_start_date' => '2026-09-01 00:00:00',
            'subscription_end_date'   => '2027-09-01 00:00:00',
            'bank_invoice'            => 'INV-AUTOTEST-' . $this->nextIntakeNumber(),
            'installed_on'            => '2026-09-02',
            'installed_by'            => 'Old Installer',
        ], $over));
        $setup = SetupShalotrackDevice::create([
            'device_category' => 'AUTOTEST-CAT', 'imei_number' => $bound->imei_number,
            'sim_number' => '07' . str_pad((string) $this->nextIntakeNumber(), 9, '2', STR_PAD_LEFT), 'status' => 'Activated',
        ]);

        return [$bound, $setup];
    }

    private function replace(ActivatedDevice $bound, array $body)
    {
        return $this->actingAs($this->admin())->from(route('admin.customer-device-management'))
            ->post(route('admin.customer-device-management.replace', $bound->getRouteKey()), $body);
    }

    private function good(SetupShalotrackDevice $new, array $over = []): array
    {
        return array_merge(['new_imei_number' => $new->imei_number, 'reason' => 'Autotest: water damage'], $over);
    }

    private function audits(string $action, ?ActivatedDevice $bound = null): \Illuminate\Support\Collection
    {
        $q = AuditLog::where('action', $action);
        if ($bound) {
            $q->where('subject_id', $bound->vehicle_id);
        }

        return $q->get();
    }

    private function urls(): array
    {
        return array_map(fn ($r) => $r->url(), $this->sent);
    }

    // ================================================================== a good replacement

    public function test_a_replacement_swaps_the_device_and_keeps_the_paid_service_exactly_as_it_was(): void
    {
        [$bound, $oldSetup] = $this->paidVehicle();
        $new = $this->freeDevice();
        $before = $bound->only(['payment_status', 'subscription_model', 'bank_invoice', 'customer_id', 'customer_name', 'vehicle_id', 'vehicle_number']);
        $start = $bound->subscription_start_date->toDateTimeString();
        $end = $bound->subscription_end_date->toDateTimeString();

        $this->replace($bound, $this->good($new, ['installed_on' => '2026-11-05', 'installed_by' => 'New Installer']))
            ->assertRedirect(route('admin.customer-device-management'))->assertSessionHasNoErrors();

        $row = $bound->fresh();
        $this->assertSame($new->imei_number, $row->imei_number);
        $this->assertSame($new->sim_number, $row->sim_number);
        $this->assertSame('AUTOTEST-NEW-CAT', $row->device_category);
        $this->assertSame($before, $row->only(array_keys($before)));
        $this->assertSame($start, $row->subscription_start_date->toDateTimeString());
        $this->assertSame($end, $row->subscription_end_date->toDateTimeString());
        $this->assertSame('2026-11-05', $row->installed_on->format('Y-m-d'));
        $this->assertSame('New Installer', $row->installed_by);
        $this->assertNull($row->install_notes);
        $this->assertSame(1, ActivatedDevice::where('vehicle_id', $bound->vehicle_id)->count());
    }

    public function test_the_old_device_becomes_broken_and_the_new_one_becomes_activated(): void
    {
        [$bound, $oldSetup] = $this->paidVehicle();
        $new = $this->freeDevice(['status' => 'Assigned to Customer']);

        $this->replace($bound, $this->good($new))->assertSessionHasNoErrors();

        $this->assertSame('Broken Device', $oldSetup->fresh()->status);
        $this->assertSame('Activated', $new->fresh()->status);
    }

    public function test_the_success_message_names_both_devices_and_the_vehicle(): void
    {
        [$bound, $oldSetup] = $this->paidVehicle();
        $new = $this->freeDevice();

        $this->replace($bound, $this->good($new))
            ->assertSessionHas('success', "Device replaced for {$bound->vehicle_number}: {$oldSetup->imei_number} is now Broken Device and {$new->imei_number} took over. The subscription carried over.");
    }

    public function test_the_install_date_defaults_to_today_and_the_installer_can_be_left_empty(): void
    {
        [$bound] = $this->paidVehicle();
        $new = $this->freeDevice();

        $this->replace($bound, $this->good($new))->assertSessionHasNoErrors();

        $row = $bound->fresh();
        $this->assertSame(now()->local()->toDateString(), $row->installed_on->format('Y-m-d'));
        $this->assertNull($row->installed_by);
    }

    public function test_a_device_with_no_setup_row_can_still_be_replaced(): void
    {
        $bound = $this->makeBound(['payment_status' => 'not-Paid']);          // no setup row for the old IMEI
        $new = $this->freeDevice();

        $this->replace($bound, $this->good($new))->assertSessionHasNoErrors();

        $this->assertSame($new->imei_number, $bound->fresh()->imei_number);
        $this->assertSame('Activated', $new->fresh()->status);
    }

    public function test_an_unpaid_vehicle_stays_unpaid_after_a_replacement(): void
    {
        $bound = $this->makeBound(['payment_status' => 'not-Paid']);
        $new = $this->freeDevice();

        $this->replace($bound, $this->good($new))->assertSessionHasNoErrors();

        $row = $bound->fresh();
        $this->assertSame('not-Paid', $row->payment_status);
        $this->assertNull($row->subscription_end_date);
    }

    public function test_the_replaced_device_can_be_replaced_again_by_a_third_one(): void
    {
        [$bound] = $this->paidVehicle();
        $second = $this->freeDevice();
        $third = $this->freeDevice();

        $this->replace($bound, $this->good($second))->assertSessionHasNoErrors();
        $this->replace($bound->fresh(), $this->good($third, ['reason' => 'Autotest: failed again']))->assertSessionHasNoErrors();

        $this->assertSame($third->imei_number, $bound->fresh()->imei_number);
        $this->assertSame('Broken Device', $second->fresh()->status);
        $this->assertSame('Activated', $third->fresh()->status);
    }

    public function test_a_broken_device_can_never_be_used_again_as_the_replacement(): void
    {
        [$bound, $oldSetup] = $this->paidVehicle();
        $new = $this->freeDevice();
        $this->replace($bound, $this->good($new))->assertSessionHasNoErrors();
        [$other] = $this->paidVehicle();

        $this->replace($other, $this->good($oldSetup->fresh()))->assertSessionHasErrors('new_imei_number');

        $this->assertNotSame($oldSetup->imei_number, $other->fresh()->imei_number);
    }

    // ================================================================== devices that may NOT be used

    public static function unusable(): array
    {
        return [
            'not activated yet'         => ['Not Activated', null, false],
            'broken'                    => ['Broken Device', null, false],
            'temporarily stopped'       => ['Temporarily Stopped', null, false],
            'in a dealer\'s unsold stock' => ['Activated', 'dealer', false],
            'already on another vehicle' => ['Activated', null, true],
        ];
    }

    #[DataProvider('unusable')]
    public function test_a_device_that_is_not_free_to_bind_is_refused_and_nothing_changes(string $status, ?string $holder, bool $onVehicle): void
    {
        [$bound, $oldSetup] = $this->paidVehicle();
        $dealer = $holder ? Dealer::create(['full_name' => 'Autotest Holder', 'dealer_status' => 'retailer', 'region' => 'colombo', 'country' => 'Sri Lanka', 'contact_email' => 'autotest+holder@example.test', 'status' => 'active', 'created_by' => 'autotest']) : null;
        $bad = $this->freeDevice(['status' => $status, 'dealer_id' => $dealer?->id]);
        if ($onVehicle) {
            $this->makeBound(['imei_number' => $bad->imei_number]);
        }
        $oldImei = $bound->imei_number;

        $this->replace($bound, $this->good($bad))->assertSessionHasErrors('new_imei_number');

        $this->assertSame($oldImei, $bound->fresh()->imei_number);
        $this->assertSame('Activated', $oldSetup->fresh()->status);
        $this->assertSame($status, $bad->fresh()->status);
        $this->assertCount(0, $this->audits('device.replaced', $bound));
        $this->assertSame([], $this->sent);
    }

    public function test_the_same_device_cannot_replace_itself(): void
    {
        [$bound, $oldSetup] = $this->paidVehicle();

        $this->replace($bound, $this->good($oldSetup))->assertSessionHasErrors('new_imei_number');

        $this->assertSame($oldSetup->imei_number, $bound->fresh()->imei_number);
        $this->assertSame('Activated', $oldSetup->fresh()->status);
    }

    public static function strangeImeis(): array
    {
        return [
            'unknown number'  => ['999999999999999'],
            'empty'           => [''],
            'letters'         => ['abcdefghijklmno'],
            'sql'             => ["1' or '1'='1"],
            'a script'        => ['<script>alert(1)</script>'],
            'very long'       => [str_repeat('9', 400)],
            'an array'        => [['864876170981409']],
        ];
    }

    #[DataProvider('strangeImeis')]
    public function test_an_unknown_or_strange_imei_is_refused_and_nothing_changes(mixed $imei): void
    {
        [$bound, $oldSetup] = $this->paidVehicle();
        $oldImei = $bound->imei_number;

        $this->replace($bound, ['new_imei_number' => $imei, 'reason' => 'Autotest'])->assertSessionHasErrors('new_imei_number');

        $this->assertSame($oldImei, $bound->fresh()->imei_number);
        $this->assertSame('Activated', $oldSetup->fresh()->status);
    }

    public function test_the_imei_field_missing_is_refused(): void
    {
        [$bound] = $this->paidVehicle();

        $this->replace($bound, ['reason' => 'Autotest'])->assertSessionHasErrors('new_imei_number');
    }

    // ================================================================== the other fields

    public function test_the_reason_is_required_and_has_a_length_limit(): void
    {
        [$bound] = $this->paidVehicle();
        $new = $this->freeDevice();
        $oldImei = $bound->imei_number;

        $this->replace($bound, ['new_imei_number' => $new->imei_number])->assertSessionHasErrors('reason');
        $this->replace($bound, $this->good($new, ['reason' => '']))->assertSessionHasErrors('reason');
        $this->replace($bound, $this->good($new, ['reason' => str_repeat('a', 256)]))->assertSessionHasErrors('reason');

        $this->assertSame($oldImei, $bound->fresh()->imei_number);
        $this->assertSame('Activated', $new->fresh()->status);
    }

    public function test_a_reason_of_exactly_255_characters_is_accepted(): void
    {
        [$bound] = $this->paidVehicle();
        $new = $this->freeDevice();

        $this->replace($bound, $this->good($new, ['reason' => str_repeat('a', 255)]))->assertSessionHasNoErrors();

        $this->assertSame($new->imei_number, $bound->fresh()->imei_number);
    }

    public function test_an_install_date_in_the_future_is_refused_but_today_is_fine(): void
    {
        [$bound] = $this->paidVehicle();
        $new = $this->freeDevice();

        $this->replace($bound, $this->good($new, ['installed_on' => now()->local()->addDay()->toDateString()]))->assertSessionHasErrors('installed_on');
        $this->assertSame('Activated', $new->fresh()->status);

        $this->replace($bound, $this->good($new, ['installed_on' => now()->local()->toDateString()]))->assertSessionHasNoErrors();
        $this->assertSame($new->imei_number, $bound->fresh()->imei_number);
    }

    public function test_an_install_date_that_is_not_a_date_is_refused(): void
    {
        [$bound] = $this->paidVehicle();
        $new = $this->freeDevice();

        $this->replace($bound, $this->good($new, ['installed_on' => 'soon']))->assertSessionHasErrors('installed_on');
    }

    public function test_the_installer_name_has_a_length_limit(): void
    {
        [$bound] = $this->paidVehicle();
        $new = $this->freeDevice();

        $this->replace($bound, $this->good($new, ['installed_by' => str_repeat('n', 101)]))->assertSessionHasErrors('installed_by');
        $this->replace($bound, $this->good($new, ['installed_by' => str_repeat('n', 100)]))->assertSessionHasNoErrors();
    }

    public function test_a_script_in_the_reason_is_stored_as_plain_text_and_shown_safely(): void
    {
        [$bound] = $this->paidVehicle();
        $new = $this->freeDevice();

        $this->replace($bound, $this->good($new, ['reason' => '<script>alert(1)</script>']))->assertSessionHasNoErrors();

        $audit = $this->audits('device.replaced', $bound)->first();
        $this->assertSame('<script>alert(1)</script>', $audit->meta['reason']);
        $this->actingAs($this->admin())->get(route('admin.audit-log'))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_a_vehicle_that_does_not_exist_is_a_404(): void
    {
        $new = $this->freeDevice();

        $this->actingAs($this->admin())->post(route('admin.customer-device-management.replace', 'ffffffff-ffff-4fff-8fff-ffffffffffff'), $this->good($new))->assertNotFound();

        $this->assertSame('Activated', $new->fresh()->status);
    }

    public function test_the_replace_address_does_not_accept_get(): void
    {
        [$bound] = $this->paidVehicle();

        $this->actingAs($this->admin())->get(route('admin.customer-device-management.replace', $bound->getRouteKey()))->assertStatus(405);
    }

    // ================================================================== audit

    public function test_every_replacement_is_audited_with_old_and_new_imei_and_the_reason(): void
    {
        [$bound, $oldSetup] = $this->paidVehicle();
        $new = $this->freeDevice();

        $this->replace($bound, $this->good($new, ['installed_on' => '2026-11-05', 'installed_by' => 'New Installer']))->assertSessionHasNoErrors();

        $audit = $this->audits('device.replaced', $bound);
        $this->assertCount(1, $audit);
        $a = $audit->first();
        $this->assertSame($this->admin()->admin_id, $a->actor_id);
        $this->assertSame($bound->vehicle_number, $a->subject_label);
        $this->assertEquals(['from' => $oldSetup->imei_number, 'to' => $new->imei_number], $a->changes['imei']);
        $this->assertEquals(['from' => '2026-09-02', 'to' => '2026-11-05'], $a->changes['installed_on']);
        $this->assertEquals(['from' => 'Old Installer', 'to' => 'New Installer'], $a->changes['installed_by']);
        $this->assertSame('Autotest: water damage', $a->meta['reason']);
        $this->assertSame('Paid', $a->meta['payment_status']);
    }

    public function test_a_refused_replacement_writes_no_audit_line(): void
    {
        [$bound] = $this->paidVehicle();
        $before = AuditLog::where('action', 'device.replaced')->count();

        $this->replace($bound, ['new_imei_number' => '999999999999999', 'reason' => 'x'])->assertSessionHasErrors();

        $this->assertSame($before, AuditLog::where('action', 'device.replaced')->count());
    }

    // ================================================================== telling the app server

    public function test_the_app_server_is_told_about_both_devices_the_move_and_the_subscription_with_the_secret_key(): void
    {
        [$bound, $oldSetup] = $this->paidVehicle();
        $new = $this->freeDevice();

        $this->replace($bound, $this->good($new))->assertSessionHasNoErrors();

        $urls = $this->urls();
        $this->assertGreaterThanOrEqual(2, count(array_filter($urls, fn ($u) => str_contains($u, '/api/internal/setup-devices-sync'))));
        $this->assertCount(1, array_filter($urls, fn ($u) => str_contains($u, '/api/internal/device-replacement-sync')));
        $this->assertCount(1, array_filter($urls, fn ($u) => str_contains($u, '/api/internal/subscription-status-sync')));
        foreach ($this->sent as $r) {
            $this->assertSame('autotest-sync-key', $r->header('X-Admin-Sync-Key')[0] ?? null);
        }

        $move = collect($this->sent)->first(fn ($r) => str_contains($r->url(), 'device-replacement-sync'));
        $this->assertSame(['oldImei' => $oldSetup->imei_number, 'newImei' => $new->imei_number], $move->data());

        $sub = collect($this->sent)->first(fn ($r) => str_contains($r->url(), 'subscription-status-sync'))->data()['devices'];
        $this->assertSame(['imei' => $oldSetup->imei_number, 'isActive' => false, 'expiresAt' => null], $sub[0]);
        $this->assertSame($new->imei_number, $sub[1]['imei']);
        $this->assertTrue($sub[1]['isActive']);
        $this->assertStringStartsWith('2027-09-01', $sub[1]['expiresAt']);
    }

    public function test_the_move_is_asked_for_only_after_the_new_device_was_registered_there(): void
    {
        [$bound] = $this->paidVehicle();
        $new = $this->freeDevice();

        $this->replace($bound, $this->good($new))->assertSessionHasNoErrors();

        $urls = $this->urls();
        $lastRegister = max(array_keys(array_filter($urls, fn ($u) => str_contains($u, 'setup-devices-sync'))));
        $move = array_keys(array_filter($urls, fn ($u) => str_contains($u, 'device-replacement-sync')))[0];
        $this->assertLessThan($move, $lastRegister);
    }

    public static function apiProblems(): array
    {
        return [
            'cannot connect'       => ['down'],
            'server error'         => [500],
            'move refused'         => [['device-replacement-sync' => 409]],
            'subscription refused' => [['subscription-status-sync' => 500]],
            'register refused'     => [['setup-devices-sync' => 500]],
        ];
    }

    #[DataProvider('apiProblems')]
    public function test_when_the_app_server_cannot_be_fully_updated_the_replacement_is_still_saved_and_the_person_is_told(int|string|array $mode): void
    {
        [$bound, $oldSetup] = $this->paidVehicle();
        $new = $this->freeDevice();
        $this->apiMode = $mode;

        $res = $this->replace($bound, $this->good($new))->assertRedirect(route('admin.customer-device-management'))->assertSessionHasNoErrors();

        $this->assertSame($new->imei_number, $bound->fresh()->imei_number);
        $this->assertSame('Broken Device', $oldSetup->fresh()->status);
        $msg = session('success');
        $this->assertStringContainsString('The mobile API could not be fully updated', $msg);
        $this->assertStringContainsString("php artisan devices:push-replacement {$oldSetup->imei_number} {$new->imei_number}", $msg);
        $this->assertCount(1, $this->audits('device.replaced', $bound));
    }

    public function test_the_app_server_is_not_asked_anything_when_the_replacement_is_refused(): void
    {
        [$bound] = $this->paidVehicle();

        $this->replace($bound, $this->good($this->freeDevice(['status' => 'Not Activated'])))->assertSessionHasErrors('new_imei_number');

        $this->assertSame([], $this->sent);
    }

    // ================================================================== who may replace a device

    public static function otherRoles(): array
    {
        return ['dealer' => ['DEALER'], 'distributor' => ['DISTRIBUTOR'], 'finance' => ['FINANCE'], 'technician' => ['TECHNICIAN'], 'supplier' => ['SUPPLIER']];
    }

    #[DataProvider('otherRoles')]
    public function test_only_the_company_admin_may_replace_a_device(string $role): void
    {
        [$bound, $oldSetup] = $this->paidVehicle();
        $new = $this->freeDevice();
        $oldImei = $bound->imei_number;

        $this->actingAs($this->makeAdmin($role))->post(route('admin.customer-device-management.replace', $bound->getRouteKey()), $this->good($new))->assertForbidden();

        $this->assertSame($oldImei, $bound->fresh()->imei_number);
        $this->assertSame('Activated', $new->fresh()->status);
        $this->assertSame([], $this->sent);
    }

    public function test_a_visitor_who_is_not_signed_in_cannot_replace_a_device(): void
    {
        [$bound] = $this->paidVehicle();
        $new = $this->freeDevice();
        $oldImei = $bound->imei_number;

        $this->post(route('admin.customer-device-management.replace', $bound->getRouteKey()), $this->good($new))->assertRedirect();

        $this->assertSame($oldImei, $bound->fresh()->imei_number);
    }

    // ================================================================== bank slips

    private function withSlip(string $content = 'FAKE-SLIP-BYTES', string $path = 'bank_slips/autotest-slip.pdf'): ActivatedDevice
    {
        Storage::disk('public')->put($path, $content);

        return $this->makeBound(['payment_status' => 'Paid', 'bank_slip' => $path]);
    }

    public static function slipRoles(): array
    {
        return ['admin' => ['ADMIN'], 'finance' => ['FINANCE']];
    }

    #[DataProvider('slipRoles')]
    public function test_admin_and_finance_can_open_a_bank_slip_with_safe_headers(string $role): void
    {
        $bound = $this->withSlip('FAKE-SLIP-BYTES');

        $res = $this->actingAs($this->makeAdmin($role))->get(route('admin.customer-device-management.bank-slip', $bound->getRouteKey()))->assertOk();

        $this->assertSame('FAKE-SLIP-BYTES', $res->streamedContent());
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('private', $res->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', $res->headers->get('Cache-Control'));
    }

    #[DataProvider('slipRoles')]
    public function test_every_viewing_of_a_bank_slip_is_audited_with_who_and_which_vehicle(string $role): void
    {
        $bound = $this->withSlip();
        $me = $this->makeAdmin($role);

        $this->actingAs($me)->get(route('admin.customer-device-management.bank-slip', $bound->getRouteKey()))->assertOk();
        $this->get(route('admin.customer-device-management.bank-slip', $bound->getRouteKey()))->assertOk();

        $audit = $this->audits('bank_slip.viewed', $bound);
        $this->assertCount(2, $audit);
        $this->assertSame($me->admin_id, $audit->first()->actor_id);
        $this->assertSame($bound->vehicle_number, $audit->first()->subject_label);
        $this->assertSame($bound->imei_number, $audit->first()->meta['imei']);
    }

    public function test_a_vehicle_with_no_slip_is_a_404_and_is_not_audited(): void
    {
        $bound = $this->makeBound(['payment_status' => 'Paid', 'bank_slip' => null]);

        $this->actingAs($this->admin())->get(route('admin.customer-device-management.bank-slip', $bound->getRouteKey()))->assertNotFound();

        $this->assertCount(0, $this->audits('bank_slip.viewed', $bound));
    }

    public function test_a_slip_whose_file_is_gone_says_so_in_a_404(): void
    {
        $bound = $this->makeBound(['payment_status' => 'Paid', 'bank_slip' => 'bank_slips/vanished.pdf']);

        $this->actingAs($this->admin())->get(route('admin.customer-device-management.bank-slip', $bound->getRouteKey()))
            ->assertNotFound();

        $this->assertCount(0, $this->audits('bank_slip.viewed', $bound));
    }

    public function test_a_vehicle_that_does_not_exist_has_no_slip_page(): void
    {
        $this->actingAs($this->admin())->get(route('admin.customer-device-management.bank-slip', 'ffffffff-ffff-4fff-8fff-ffffffffffff'))->assertNotFound();
    }

    public function test_the_slip_is_one_vehicles_own_and_never_another_ones(): void
    {
        $a = $this->withSlip('SLIP-OF-A', 'bank_slips/a.pdf');
        $b = $this->withSlip('SLIP-OF-B', 'bank_slips/b.pdf');

        $this->assertSame('SLIP-OF-B', $this->actingAs($this->admin())->get(route('admin.customer-device-management.bank-slip', $b->getRouteKey()))->streamedContent());
        $this->assertSame('SLIP-OF-A', $this->get(route('admin.customer-device-management.bank-slip', $a->getRouteKey()))->streamedContent());
    }

    public static function noSlipRoles(): array
    {
        return ['dealer' => ['DEALER'], 'distributor' => ['DISTRIBUTOR'], 'technician' => ['TECHNICIAN'], 'supplier' => ['SUPPLIER']];
    }

    #[DataProvider('noSlipRoles')]
    public function test_nobody_else_may_open_a_bank_slip(string $role): void
    {
        $bound = $this->withSlip();

        $this->actingAs($this->makeAdmin($role))->get(route('admin.customer-device-management.bank-slip', $bound->getRouteKey()))->assertForbidden();

        $this->assertCount(0, $this->audits('bank_slip.viewed', $bound));
    }

    public function test_a_visitor_who_is_not_signed_in_cannot_open_a_slip(): void
    {
        $bound = $this->withSlip();

        $this->get(route('admin.customer-device-management.bank-slip', $bound->getRouteKey()))->assertRedirect();

        $this->assertCount(0, $this->audits('bank_slip.viewed', $bound));
    }

    public function test_the_slip_is_not_reachable_by_a_public_storage_address(): void
    {
        $bound = $this->withSlip('SECRET-SLIP', 'bank_slips/secret.pdf');

        // The file sits on the "public" disk, but the app must hand it out only through the guarded route.
        $this->assertStringContainsString('/device-management/' . $bound->getRouteKey() . '/bank-slip', route('admin.customer-device-management.bank-slip', $bound->getRouteKey()));
        $this->assertNotSame(200, $this->get('/storage/bank_slips/secret.pdf')->getStatusCode());
    }
}