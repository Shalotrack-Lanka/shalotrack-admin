<?php

namespace Tests\Pgsql;

use App\Enums\DeviceStatus;
use App\Models\ActivatedDevice;
use App\Models\Admin;
use App\Models\Dealer;
use App\Models\DealerCustomerAd;
use App\Models\SetupShalotrackDevice;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * DEALER DEVICE LIFECYCLE, on real PostgreSQL (autotest schema).
 *
 * The dealer's life with a device, step by step:
 *
 *   in the dealer's stock (Activated)
 *     --assign-->  Assigned to Customer
 *     --unassign-> Pending Repair
 *     --reassign-> Assigned to Customer again (or back to stock when no customer is linked)
 *     --remove---> Broken Device  --to pending--> Pending Repair
 *
 * Rules the tests prove:
 *   - only an Activated, not-yet-bound device can be sold (company activates first, dealer sells after),
 *   - a device that is already bound to a customer vehicle (has an activated_devices row) can never be moved by the dealer,
 *   - a dealer can only touch ITS OWN devices and ITS OWN customers (anything else is a 404 and nothing changes),
 *   - every move is audited, and the customer's "devices still to receive" number stays right,
 *   - customer leads: add, edit, search, delete (soft) and the rules around them.
 *
 * ONE login per test (a second login mid-test is logged out by AuthenticateSession). Everything is rolled back.
 */
class DealerDeviceLifecycleTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();   // the "not enough stock" alert would use Firebase; nothing real may be called
    }

    // ------------------------------------------------------------------ helpers

    private function dealer(string $name = 'Dealer'): Dealer
    {
        $n = ++$this->seq;

        return Dealer::create([
            'full_name'     => "Autotest {$name} {$n}",
            'dealer_status' => 'retailer',
            'region'        => 'colombo',
            'country'       => 'Sri Lanka',
            'contact_email' => "autotest+life-{$n}@example.test",
            'status'        => 'active',
            'created_by'    => 'autotest',
        ]);
    }

    private function login(Dealer $dealer): Admin
    {
        $admin = $this->makeAdmin('DEALER', ['dealer_id' => $dealer->id]);
        $this->actingAs($admin);

        return $admin;
    }

    /** A device with this status in the dealer's hands (null dealer = company stock). */
    private function device(?Dealer $dealer, string $status = 'Activated', ?int $customerId = null): SetupShalotrackDevice
    {
        $n = ++$this->seq;

        return SetupShalotrackDevice::forceCreate([
            'device_category'      => 'AUTOTEST-LC',
            'imei_number'          => '9980000' . str_pad((string) $n, 8, '0', STR_PAD_LEFT),
            'sim_number'           => '0710' . str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'status'               => $status,
            'dealer_id'            => $dealer?->id,
            'assigned_customer_id' => $customerId,
        ]);
    }

    private function lead(Dealer $dealer, int $pending = 1, string $name = 'Lead'): DealerCustomerAd
    {
        $n = ++$this->seq;

        return DealerCustomerAd::create([
            'dealer_id'     => $dealer->id,
            'name'          => "{$name} {$n}",
            'email'         => "lead{$n}@example.test",
            'contact'       => '0771' . str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'no_of_devices' => $pending,
            'imei_numbers'  => [],
        ]);
    }

    /** The admin has bound the device to a customer vehicle: an activated_devices row exists. */
    private function bind(SetupShalotrackDevice $device): ActivatedDevice
    {
        return ActivatedDevice::create([
            'vehicle_id'            => (string) Str::uuid(),
            'customer_id'           => (string) Str::uuid(),
            'customer_name'         => 'Autotest Bound Customer',
            'vehicle_number'        => 'LIFE-' . ++$this->seq,
            'model'                 => 'Autotest Model',
            'has_gps_device'        => false,
            'imei_number'           => $device->imei_number,
            'sim_number'            => $device->sim_number,
            'device_category'       => $device->device_category,
            'status'                => DeviceStatus::Activated->value,
            'payment_status'        => 'not-Paid',
            'original_activated_at' => now(),
        ]);
    }

    /** Send a request the way the browser does: coming from the dealer dashboard, so "back" goes there. */
    private function go(string $method, string $uri, array $data = [])
    {
        return $this->from(route('dealer.dashboard'))->call($method, $uri, $data);
    }

    private function audit(string $action, $device): int
    {
        return DB::table('audit_logs')->where('action', $action)->where('subject_id', (string) $device->shdevice_id)->count();
    }

    private function statusOf(SetupShalotrackDevice $device): string
    {
        return SetupShalotrackDevice::find($device->shdevice_id)->status;
    }

    // ------------------------------------------------------------------ ASSIGN (sell to a customer)

    public function test_a_dealer_sells_an_activated_device_to_its_customer(): void
    {
        $dealer = $this->dealer();
        $lead   = $this->lead($dealer, 2);
        $device = $this->device($dealer);
        $this->login($dealer);

        $this->go('POST', route('dealer.assign-device'), ['shdevice_id' => $device->shdevice_id, 'customer_id' => $lead->id])
            ->assertRedirect(route('dealer.dashboard'))
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        $fresh = SetupShalotrackDevice::find($device->shdevice_id);
        $this->assertSame('Assigned to Customer', $fresh->status);
        $this->assertSame($lead->id, (int) $fresh->assigned_customer_id);
        $this->assertSame($dealer->id, (int) $fresh->dealer_id, 'The device stays with the dealer.');

        $lead = $lead->fresh();
        $this->assertSame([$device->imei_number], $lead->imei_numbers);
        $this->assertSame(1, (int) $lead->no_of_devices, 'Two were wanted, one is given, one is still to come.');
        $this->assertSame(1, $this->audit('device.assigned', $device));
        $this->assertSame(0, DB::table('dealer_commission_ledger')->where('shdevice_id', $device->shdevice_id)->count(), 'The old flat sale commission is switched off.');
    }

    public static function statusesThatCannotBeSold(): array
    {
        return [
            'Not Activated'       => ['Not Activated'],
            'Pending Repair'      => ['Pending Repair'],
            'Broken Device'       => ['Broken Device'],
            'Temporarily Stopped' => ['Temporarily Stopped'],
        ];
    }

    #[DataProvider('statusesThatCannotBeSold')]
    public function test_only_an_activated_device_can_be_sold(string $status): void
    {
        $dealer = $this->dealer();
        $lead   = $this->lead($dealer);
        $device = $this->device($dealer, $status);
        $this->login($dealer);

        $this->go('POST', route('dealer.assign-device'), ['shdevice_id' => $device->shdevice_id, 'customer_id' => $lead->id])
            ->assertSessionHasErrors('assign');

        $this->assertStringContainsString('cannot be assigned', session('errors')->first('assign'));
        $this->assertSame($status, $this->statusOf($device));
        $this->assertNull(SetupShalotrackDevice::find($device->shdevice_id)->assigned_customer_id);
        $this->assertSame(1, (int) $lead->fresh()->no_of_devices);
    }

    public function test_a_device_already_on_a_customer_cannot_be_sold_again_or_taken_by_another_lead(): void
    {
        $dealer = $this->dealer();
        $first  = $this->lead($dealer);
        $second = $this->lead($dealer);
        $device = $this->device($dealer, 'Assigned to Customer', $first->id);
        $this->login($dealer);

        $this->go('POST', route('dealer.assign-device'), ['shdevice_id' => $device->shdevice_id, 'customer_id' => $second->id])
            ->assertSessionHasErrors('assign');

        $this->assertStringContainsString('already assigned', session('errors')->first('assign'));
        $this->assertSame($first->id, (int) SetupShalotrackDevice::find($device->shdevice_id)->assigned_customer_id);
    }

    public function test_a_device_already_bound_to_a_customer_vehicle_cannot_be_sold(): void
    {
        $dealer = $this->dealer();
        $lead   = $this->lead($dealer);
        $device = $this->device($dealer);
        $this->bind($device);   // still says Activated, but it is live on a vehicle
        $this->login($dealer);

        $this->go('POST', route('dealer.assign-device'), ['shdevice_id' => $device->shdevice_id, 'customer_id' => $lead->id])
            ->assertSessionHasErrors('assign');

        $this->assertSame('Activated', $this->statusOf($device));
        $this->assertNull(SetupShalotrackDevice::find($device->shdevice_id)->assigned_customer_id);
    }

    public function test_the_sell_button_refuses_a_customer_with_nothing_pending_but_the_list_button_allows_it(): void
    {
        $dealer = $this->dealer();
        $lead   = $this->lead($dealer, 0);
        $one    = $this->device($dealer);
        $two    = $this->device($dealer);
        $this->login($dealer);

        $this->go('POST', route('dealer.assign-device'), ['shdevice_id' => $one->shdevice_id, 'customer_id' => $lead->id])
            ->assertSessionHasErrors('assign');
        $this->assertStringContainsString('already received all requested devices', session('errors')->first('assign'));
        $this->assertSame('Activated', $this->statusOf($one));

        // The customer-list button does not look at the pending number (it never goes below 0).
        $this->go('POST', route('dealer.customers.assign_new_device_from_list'), ['shdevice_id' => $two->shdevice_id, 'customer_id' => $lead->id])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('Assigned to Customer', $this->statusOf($two));
        $this->assertSame(0, (int) $lead->fresh()->no_of_devices);
    }

    public function test_the_customer_list_button_has_the_same_status_rules(): void
    {
        $dealer = $this->dealer();
        $lead   = $this->lead($dealer);
        $device = $this->device($dealer, 'Not Activated');
        $this->login($dealer);

        $this->go('POST', route('dealer.customers.assign_new_device_from_list'), ['shdevice_id' => $device->shdevice_id, 'customer_id' => $lead->id])
            ->assertSessionHasErrors('assign');

        $this->assertSame('Not Activated', $this->statusOf($device));
    }

    public function test_a_dealer_cannot_sell_another_dealers_device_or_to_another_dealers_customer(): void
    {
        $mine   = $this->dealer('Mine');
        $theirs = $this->dealer('Theirs');
        $myLead    = $this->lead($mine);
        $theirLead = $this->lead($theirs);
        $myDevice    = $this->device($mine);
        $theirDevice = $this->device($theirs);
        $companyDevice = $this->device(null);
        $this->login($mine);

        // Their device, my customer.
        $this->go('POST', route('dealer.assign-device'), ['shdevice_id' => $theirDevice->shdevice_id, 'customer_id' => $myLead->id])->assertNotFound();
        // Company stock, my customer.
        $this->go('POST', route('dealer.assign-device'), ['shdevice_id' => $companyDevice->shdevice_id, 'customer_id' => $myLead->id])->assertNotFound();
        // My device, their customer.
        $this->go('POST', route('dealer.assign-device'), ['shdevice_id' => $myDevice->shdevice_id, 'customer_id' => $theirLead->id])->assertNotFound();

        $this->assertSame('Activated', $this->statusOf($theirDevice));
        $this->assertSame('Activated', $this->statusOf($companyDevice));
        $this->assertSame('Activated', $this->statusOf($myDevice));
        $this->assertSame([], $theirLead->fresh()->imei_numbers);
        $this->assertSame(1, (int) $theirLead->fresh()->no_of_devices);
    }

    public static function badAssignForms(): array
    {
        return [
            'nothing sent'            => [[]],
            'device id missing'       => [['customer_id' => '1']],
            'customer id missing'     => [['shdevice_id' => '1']],
            'ids that do not exist'   => [['shdevice_id' => '999999999', 'customer_id' => '999999999']],
            'device id is text'       => [['shdevice_id' => 'abc', 'customer_id' => '1']],
            'customer id is text'     => [['shdevice_id' => '1', 'customer_id' => 'abc']],
            'ids too big for the db'  => [['shdevice_id' => '99999999999999999999', 'customer_id' => '1']],
        ];
    }

    #[DataProvider('badAssignForms')]
    public function test_a_bad_sell_form_gets_a_message_never_a_crash(array $form): void
    {
        $dealer = $this->dealer();
        $this->login($dealer);

        $this->go('POST', route('dealer.assign-device'), $form)->assertRedirect(route('dealer.dashboard'));
        $this->assertTrue(session('errors')?->any() ?? false, 'The dealer must be told what is wrong with the form.');
    }

    // ------------------------------------------------------------------ UNASSIGN

    public function test_unassign_sends_a_sold_device_to_pending_repair_and_gives_the_customer_the_slot_back(): void
    {
        $dealer = $this->dealer();
        $lead   = $this->lead($dealer, 1);
        $device = $this->device($dealer);
        $this->login($dealer);

        $this->go('POST', route('dealer.assign-device'), ['shdevice_id' => $device->shdevice_id, 'customer_id' => $lead->id])->assertSessionHas('success');
        $this->assertSame(0, (int) $lead->fresh()->no_of_devices);

        $this->go('DELETE', route('dealer.unassign-device', $device->shdevice_id))
            ->assertRedirect(route('dealer.dashboard'))
            ->assertSessionHas('success');

        $this->assertSame('Pending Repair', $this->statusOf($device));
        $lead = $lead->fresh();
        $this->assertSame([], $lead->imei_numbers, 'The device leaves the customer list.');
        $this->assertSame(1, (int) $lead->no_of_devices, 'The customer can receive a new device again.');
        $this->assertSame(1, $this->audit('device.unassigned', $device));
    }

    public function test_a_device_live_on_a_customer_vehicle_cannot_be_unassigned_broken_or_moved_by_the_dealer(): void
    {
        $dealer = $this->dealer();
        $lead   = $this->lead($dealer, 0);
        $device = $this->device($dealer, 'Assigned to Customer', $lead->id);
        $this->bind($device);
        $this->login($dealer);

        $this->go('DELETE', route('dealer.unassign-device', $device->shdevice_id))->assertSessionHasErrors('assign');
        $this->go('DELETE', route('dealer.remove-broken-device', $device->shdevice_id))->assertSessionHasErrors('assign');

        $this->assertSame('Assigned to Customer', $this->statusOf($device));
        $this->assertSame($lead->id, (int) SetupShalotrackDevice::find($device->shdevice_id)->assigned_customer_id);
        $this->assertSame(0, $this->audit('device.unassigned', $device));
        $this->assertSame(0, $this->audit('device.marked_broken', $device));
    }

    // ------------------------------------------------------------------ REASSIGN

    public function test_reassign_puts_a_repaired_device_back_on_its_customer(): void
    {
        $dealer = $this->dealer();
        $lead   = $this->lead($dealer, 1);
        $device = $this->device($dealer);
        $this->login($dealer);

        $this->go('POST', route('dealer.assign-device'), ['shdevice_id' => $device->shdevice_id, 'customer_id' => $lead->id]);
        $this->go('DELETE', route('dealer.unassign-device', $device->shdevice_id));
        $this->assertSame(1, (int) $lead->fresh()->no_of_devices);

        $this->go('POST', route('dealer.reassign-device', $device->shdevice_id))
            ->assertSessionHas('success')->assertSessionHasNoErrors();

        $this->assertSame('Assigned to Customer', $this->statusOf($device));
        $lead = $lead->fresh();
        $this->assertSame([$device->imei_number], $lead->imei_numbers);
        $this->assertSame(0, (int) $lead->no_of_devices);
        $this->assertSame(1, $this->audit('device.reassigned', $device));
    }

    public function test_reassign_of_a_device_with_no_customer_returns_it_to_the_dealers_stock(): void
    {
        $dealer = $this->dealer();
        $device = $this->device($dealer, 'Pending Repair');   // came back from testing, no customer linked
        $this->login($dealer);

        $this->go('POST', route('dealer.reassign-device', $device->shdevice_id))->assertSessionHas('success');

        $this->assertSame('Activated', $this->statusOf($device));
        $this->assertSame(1, SetupShalotrackDevice::availableForDealer($dealer->id)->count());
    }

    public static function statusesThatCannotBeReassigned(): array
    {
        return [
            'Activated'          => ['Activated'],
            'Assigned to Customer' => ['Assigned to Customer'],
            'Broken Device'      => ['Broken Device'],
            'Not Activated'      => ['Not Activated'],
        ];
    }

    #[DataProvider('statusesThatCannotBeReassigned')]
    public function test_only_a_pending_repair_device_can_be_reassigned(string $status): void
    {
        $dealer = $this->dealer();
        $device = $this->device($dealer, $status);
        $this->login($dealer);

        $this->go('POST', route('dealer.reassign-device', $device->shdevice_id))->assertSessionHasErrors('assign');
        $this->assertStringContainsString('Only devices in Pending Repair can be reassigned', session('errors')->first('assign'));
        $this->assertSame($status, $this->statusOf($device));
    }

    // ------------------------------------------------------------------ BROKEN and back to pending

    public function test_a_device_can_be_marked_broken_and_sent_back_for_testing(): void
    {
        $dealer = $this->dealer();
        $device = $this->device($dealer, 'Pending Repair');
        $this->login($dealer);

        $this->go('DELETE', route('dealer.remove-broken-device', $device->shdevice_id))->assertSessionHas('success');
        $this->assertSame('Broken Device', $this->statusOf($device));
        $this->assertSame(1, $this->audit('device.marked_broken', $device));

        $this->go('POST', route('dealer.move-to-pending', $device->shdevice_id))->assertSessionHas('success');
        $this->assertSame('Pending Repair', $this->statusOf($device));
        $this->assertSame(1, $this->audit('device.moved_to_pending', $device));
    }

    public static function statusesThatCannotGoBackToPending(): array
    {
        return [
            'Activated'            => ['Activated'],
            'Assigned to Customer' => ['Assigned to Customer'],
            'Pending Repair'       => ['Pending Repair'],
            'Not Activated'        => ['Not Activated'],
        ];
    }

    #[DataProvider('statusesThatCannotGoBackToPending')]
    public function test_only_a_broken_device_can_be_sent_back_for_testing(string $status): void
    {
        $dealer = $this->dealer();
        $device = $this->device($dealer, $status);
        $this->login($dealer);

        $this->go('POST', route('dealer.move-to-pending', $device->shdevice_id))->assertSessionHasErrors('assign');
        $this->assertSame($status, $this->statusOf($device));
    }

    public function test_the_whole_life_of_a_device_ends_with_the_customer_count_exactly_where_it_started(): void
    {
        $dealer = $this->dealer();
        $lead   = $this->lead($dealer, 1);
        $device = $this->device($dealer);
        $this->login($dealer);

        $id = $device->shdevice_id;
        $this->go('POST', route('dealer.assign-device'), ['shdevice_id' => $id, 'customer_id' => $lead->id]);       // sold
        $this->assertSame('Assigned to Customer', $this->statusOf($device));
        $this->go('DELETE', route('dealer.unassign-device', $id));                                                    // customer complains
        $this->assertSame('Pending Repair', $this->statusOf($device));
        $this->go('DELETE', route('dealer.remove-broken-device', $id));                                               // it is dead
        $this->assertSame('Broken Device', $this->statusOf($device));
        $this->go('POST', route('dealer.move-to-pending', $id));                                                      // test it again
        $this->assertSame('Pending Repair', $this->statusOf($device));
        $this->go('POST', route('dealer.reassign-device', $id));                                                      // it works, give it back
        $this->assertSame('Assigned to Customer', $this->statusOf($device));

        $lead = $lead->fresh();
        $this->assertSame(0, (int) $lead->no_of_devices);
        $this->assertSame([$device->imei_number], $lead->imei_numbers);
        $this->assertSame($lead->id, (int) SetupShalotrackDevice::find($id)->assigned_customer_id);
    }

    // ------------------------------------------------------------------ whose devices

    public static function dealerMoves(): array
    {
        // [route name, method, status the device has]
        return [
            'unassign'     => ['dealer.unassign-device', 'DELETE', 'Assigned to Customer'],
            'reassign'     => ['dealer.reassign-device', 'POST', 'Pending Repair'],
            'mark broken'  => ['dealer.remove-broken-device', 'DELETE', 'Pending Repair'],
            'to pending'   => ['dealer.move-to-pending', 'POST', 'Broken Device'],
        ];
    }

    #[DataProvider('dealerMoves')]
    public function test_a_dealer_cannot_move_another_dealers_device(string $route, string $method, string $status): void
    {
        $mine   = $this->dealer('Mine');
        $theirs = $this->dealer('Theirs');
        $theirDevice = $this->device($theirs, $status);
        $this->login($mine);

        $this->go($method, route($route, $theirDevice->shdevice_id))->assertNotFound();

        $this->assertSame($status, $this->statusOf($theirDevice));
    }

    #[DataProvider('dealerMoves')]
    public function test_a_dealer_cannot_move_company_stock(string $route, string $method, string $status): void
    {
        $mine = $this->dealer('Mine');
        $companyDevice = $this->device(null, $status);
        $this->login($mine);

        $this->go($method, route($route, $companyDevice->shdevice_id))->assertNotFound();

        $this->assertSame($status, $this->statusOf($companyDevice));
    }

    #[DataProvider('dealerMoves')]
    public function test_a_dealer_login_with_no_dealer_record_cannot_touch_company_stock(string $route, string $method, string $status): void
    {
        // A DEALER login that points at no dealer. "dealer_id IS NULL" must never be read as "company stock is mine".
        $companyDevice = $this->device(null, $status);
        $this->actingAs($this->makeAdmin('DEALER'));

        $response = $this->go($method, route($route, $companyDevice->shdevice_id));

        $this->assertContains($response->getStatusCode(), [403, 404], 'A dealer with no dealer record must be refused.');
        $this->assertSame($status, $this->statusOf($companyDevice), 'Company stock must not change.');
    }

    #[DataProvider('dealerMoves')]
    public function test_a_text_device_id_in_the_address_is_a_404_not_a_crash(string $route, string $method, string $status): void
    {
        $this->login($this->dealer());

        $this->go($method, route($route, 'abc'))->assertNotFound();
        $this->go($method, route($route, '0'))->assertNotFound();
    }

    #[DataProvider('dealerMoves')]
    public function test_a_device_id_too_big_for_the_database_is_a_404_not_a_crash(string $route, string $method, string $status): void
    {
        $this->login($this->dealer());

        $this->go($method, route($route, '99999999999999999999'))->assertNotFound();
    }

    // ------------------------------------------------------------------ customer leads

    public function test_a_dealer_adds_a_customer_with_cleaned_up_details(): void
    {
        $dealer = $this->dealer();
        $this->login($dealer);

        $this->go('POST', route('dealer.customer-ad.store'), [
            'name' => '  Nimal Perera  ', 'contact' => '0771234567', 'email' => '  Nimal@Example.COM ',
            'nic_or_id' => '901234567V', 'has_device' => '1', 'no_of_devices' => '3', 'address' => 'Colombo 5',
        ])->assertSessionHas('success');

        $lead = DealerCustomerAd::where('dealer_id', $dealer->id)->first();
        $this->assertNotNull($lead);
        $this->assertSame('Nimal Perera', $lead->name);
        $this->assertSame('nimal@example.com', $lead->email);
        $this->assertSame('0771234567', $lead->contact);
        $this->assertSame(3, (int) $lead->no_of_devices);
        $this->assertSame([], $lead->imei_numbers);
    }

    public function test_a_customer_with_no_device_wanted_has_nothing_pending_and_a_big_order_with_no_stock_still_saves(): void
    {
        $dealer = $this->dealer();
        $this->login($dealer);

        $this->go('POST', route('dealer.customer-ad.store'), ['name' => 'No Device', 'contact' => '0771111111', 'has_device' => '0', 'no_of_devices' => '5'])
            ->assertSessionHas('success');
        $this->go('POST', route('dealer.customer-ad.store'), ['name' => 'Big Order', 'contact' => '0772222222', 'has_device' => '1', 'no_of_devices' => '50'])
            ->assertSessionHas('success');   // the stock alert cannot reach Firebase here, and must not break the save

        $this->assertSame(0, (int) DealerCustomerAd::where('name', 'No Device')->value('no_of_devices'));
        $this->assertSame(50, (int) DealerCustomerAd::where('name', 'Big Order')->value('no_of_devices'));
    }

    public static function badCustomerForms(): array
    {
        $ok = ['name' => 'Good Name', 'contact' => '0771234567'];

        return [
            'no name'                => [array_merge($ok, ['name' => '']), 'name'],
            'name too long'          => [array_merge($ok, ['name' => str_repeat('a', 256)]), 'name'],
            'phone has 9 digits'     => [array_merge($ok, ['contact' => '077123456']), 'contact'],
            'phone has 11 digits'    => [array_merge($ok, ['contact' => '07712345678']), 'contact'],
            'phone does not start 0' => [array_merge($ok, ['contact' => '1771234567']), 'contact'],
            'phone has letters'      => [array_merge($ok, ['contact' => '07712345ab']), 'contact'],
            'phone has a plus'       => [array_merge($ok, ['contact' => '+94771234567']), 'contact'],
            'no phone'               => [['name' => 'Good Name'], 'contact'],
            'email is not an email'  => [array_merge($ok, ['email' => 'not-an-email']), 'email'],
            'id number too long'     => [array_merge($ok, ['nic_or_id' => str_repeat('1', 21)]), 'nic_or_id'],
            'address too long'       => [array_merge($ok, ['address' => str_repeat('a', 501)]), 'address'],
            'devices below zero'     => [array_merge($ok, ['has_device' => '1', 'no_of_devices' => '-1']), 'no_of_devices'],
            'devices is text'        => [array_merge($ok, ['has_device' => '1', 'no_of_devices' => 'many']), 'no_of_devices'],
        ];
    }

    #[DataProvider('badCustomerForms')]
    public function test_a_bad_customer_form_is_refused_and_nothing_is_saved(array $form, string $badField): void
    {
        $dealer = $this->dealer();
        $this->login($dealer);

        $this->go('POST', route('dealer.customer-ad.store'), $form)->assertSessionHasErrors($badField);
        $this->assertSame(0, DealerCustomerAd::where('dealer_id', $dealer->id)->count());
    }

    public function test_a_customer_can_be_read_for_editing_only_by_its_own_dealer(): void
    {
        $mine   = $this->dealer('Mine');
        $theirs = $this->dealer('Theirs');
        $myLead    = $this->lead($mine);
        $theirLead = $this->lead($theirs);
        $this->login($mine);

        $this->getJson(route('dealer.customer-ad.edit', $myLead->id))
            ->assertOk()
            ->assertJson(['id' => $myLead->id, 'name' => $myLead->name, 'contact' => $myLead->contact]);

        $this->getJson(route('dealer.customer-ad.edit', $theirLead->id))->assertNotFound();
    }

    public function test_a_bad_customer_id_in_the_address_is_a_404_not_a_crash(): void
    {
        $this->login($this->dealer());

        foreach (['abc', '0', '99999999999999999999'] as $badId) {
            $this->getJson(route('dealer.customer-ad.edit', $badId))->assertNotFound();
            $this->go('DELETE', route('dealer.customer-ad.destroy', $badId))->assertNotFound();
        }
    }

    public function test_deleting_a_customer_hides_it_but_keeps_it_and_is_audited(): void
    {
        $dealer = $this->dealer();
        $lead   = $this->lead($dealer, 0);
        $this->login($dealer);

        $this->go('DELETE', route('dealer.customer-ad.destroy', $lead->id))->assertSessionHas('success');

        $this->assertNull(DealerCustomerAd::find($lead->id), 'Hidden from the dealer.');
        $this->assertNotNull(DealerCustomerAd::withTrashed()->find($lead->id)?->deleted_at, 'Still recoverable.');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'customer_lead.deleted')->where('subject_id', (string) $lead->id)->count());
    }

    public static function statusesThatKeepACustomer(): array
    {
        return [
            'sold'           => ['Assigned to Customer'],
            'pending repair' => ['Pending Repair'],
            'broken'         => ['Broken Device'],
        ];
    }

    #[DataProvider('statusesThatKeepACustomer')]
    public function test_a_customer_with_a_device_still_pointing_at_it_cannot_be_deleted(string $status): void
    {
        $dealer = $this->dealer();
        $lead   = $this->lead($dealer, 0);
        $this->device($dealer, $status, $lead->id);
        $this->login($dealer);

        $this->go('DELETE', route('dealer.customer-ad.destroy', $lead->id))->assertSessionHasErrors('assign');
        $this->assertNotNull(DealerCustomerAd::find($lead->id));
    }

    public function test_a_dealer_cannot_delete_another_dealers_customer(): void
    {
        $mine   = $this->dealer('Mine');
        $theirs = $this->dealer('Theirs');
        $theirLead = $this->lead($theirs, 0);
        $this->login($mine);

        $this->go('DELETE', route('dealer.customer-ad.destroy', $theirLead->id))->assertNotFound();

        $this->assertNotNull(DealerCustomerAd::find($theirLead->id));
    }

    public function test_the_customer_list_shows_only_own_customers_and_search_works_on_every_field(): void
    {
        $mine   = $this->dealer('Mine');
        $theirs = $this->dealer('Theirs');
        $a = DealerCustomerAd::create(['dealer_id' => $mine->id, 'name' => 'Kamal Silva', 'email' => 'kamal@example.test', 'contact' => '0771110001', 'nic_or_id' => '851234567V', 'address' => 'Galle Road', 'no_of_devices' => 0, 'imei_numbers' => ['998000011112222']]);
        $b = DealerCustomerAd::create(['dealer_id' => $mine->id, 'name' => 'Sunil Fernando', 'email' => 'sunil@example.test', 'contact' => '0772220002', 'no_of_devices' => 0, 'imei_numbers' => []]);
        DealerCustomerAd::create(['dealer_id' => $theirs->id, 'name' => 'Other Dealer Customer', 'email' => 'x@example.test', 'contact' => '0773330003', 'no_of_devices' => 0, 'imei_numbers' => []]);
        $this->login($mine);

        $this->get(route('dealer.customers.index'))
            ->assertOk()->assertSee('Kamal Silva')->assertSee('Sunil Fernando')->assertDontSee('Other Dealer Customer');

        foreach (['kamal silva', 'KAMAL', '0771110001', 'kamal@example', '851234567', 'galle road', '998000011112222'] as $term) {
            $this->get(route('dealer.customers.index', ['search' => $term]))
                ->assertOk()->assertSee('Kamal Silva')->assertDontSee('Sunil Fernando');
        }

        $this->get(route('dealer.customers.index', ['search' => 'nobody-has-this-name']))
            ->assertOk()->assertDontSee('Kamal Silva')->assertDontSee('Sunil Fernando');
    }

    // ------------------------------------------------------------------ the dashboard

    public function test_the_dashboard_puts_each_device_in_the_right_list_and_counts_only_the_dealers_own(): void
    {
        $dealer = $this->dealer();
        $other  = $this->dealer('Other');
        $lead   = $this->lead($dealer, 0);

        $this->device($dealer);                                           // stock
        $this->device($dealer);                                           // stock
        $bound = $this->device($dealer, 'Activated', $lead->id);          // sold and now live on a vehicle
        $this->bind($bound);
        $this->device($dealer, 'Assigned to Customer', $lead->id);        // sold, waiting for the admin
        $this->device($dealer, 'Pending Repair', $lead->id);
        $this->device($dealer, 'Broken Device');
        $this->device($dealer, 'Not Activated');                          // legacy: neither stock nor sold
        $this->device($other);                                            // someone else's
        $this->device(null);                                              // company stock
        $this->login($dealer);

        $this->get(route('dealer.dashboard'))
            ->assertOk()
            ->assertViewHas('allocatedDevicesCount', 2)
            ->assertViewHas('assignedDevicesCount', 2)
            ->assertViewHas('totalCustomers', 1)
            ->assertViewHas('pendingDevices', fn ($d) => $d->count() === 1)
            ->assertViewHas('brokenDevices', fn ($d) => $d->count() === 1);
    }

    public function test_the_dashboard_repairs_a_device_marked_sold_with_no_customer(): void
    {
        $dealer = $this->dealer();
        $orphan = $this->device($dealer, 'Assigned to Customer', null);
        $this->login($dealer);

        $this->get(route('dealer.dashboard'))->assertOk()->assertViewHas('allocatedDevicesCount', 1);

        $this->assertSame('Activated', $this->statusOf($orphan));
    }

    public function test_a_first_visit_by_a_dealer_login_with_no_dealer_record_creates_one_and_links_it(): void
    {
        $admin = $this->makeAdmin('DEALER');   // no dealer_id yet
        $this->actingAs($admin);
        $before = Dealer::count();

        $this->get(route('dealer.dashboard'))->assertOk();

        $this->assertSame($before + 1, Dealer::count());
        $this->assertNotNull($admin->fresh()->dealer_id);

        $this->get(route('dealer.dashboard'))->assertOk();
        $this->assertSame($before + 1, Dealer::count(), 'A second visit must not create another dealer.');
    }
}