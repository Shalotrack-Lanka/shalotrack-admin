<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\CustomerAd;
use App\Models\DealerCustomerAd;
use App\Models\VehicleAd;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsReportData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * CUSTOMER SETUP (Customers menu), on real PostgreSQL (autotest schema).
 *
 *   GET   admin.customer-setup              ADMIN: two lists, Active (verified) and Inactive (everything else)
 *   GET   admin.customer-setup.refresh      ADMIN: the same two lists as JSON for the Refresh button
 *   PATCH admin.customer-setup.toggle-status ADMIN: switch one customer between verified and not verified
 *
 * (The two PDF reports of this page are tested in ReportPagesTest.)
 *
 * What the tests prove (simple words):
 *   - opening the page first pulls fresh customers and vehicles from the app server (with the secret key) and
 *     saves them; a customer the app server stops listing is NOT deleted here,
 *   - the app server being down, slow, or answering rubbish never breaks the page: the saved customers still show,
 *   - a customer is Active only when its status is exactly "verified"; "not_verified" and an empty status are Inactive,
 *   - each customer shows its vehicles (plate, make, model, GPS badge with the IMEI) or "No vehicles",
 *   - "Via Dealer" shows when a dealer registered the same person (by e-mail first, then by phone number),
 *   - the switch saves the new status, moves the customer to the other list and answers with both lists,
 *   - a wrong status, no status, a customer that does not exist or a strange id changes nothing,
 *   - the sync never overwrites a status an admin chose,
 *   - only the company ADMIN may use any of it,
 *   - names from the app server are shown as text, never run as a script.
 *
 * The app server is FAKED (no real network). Everything is rolled back after each test.
 */
class CustomerSetupTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsReportData;

    private const API = 'https://api.test';

    private ?Admin $admin = null;
    /** What the faked app server answers for customers and vehicles. */
    private array $apiCustomers = [];
    private array $apiVehicles = [];
    /** null = answer normally, otherwise 'down' (cannot connect), 500, 'rubbish' (not JSON) or 'nodata'. */
    private int|string|null $apiMode = null;
    /** @var array<int,ClientRequest> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.shalotrack_api.base_url' => self::API, 'services.shalotrack_api.sync_key' => 'autotest-sync-key']);

        Http::fake(function (ClientRequest $request) {
            $this->sent[] = $request;
            if ($this->apiMode === 'down') {
                throw new ConnectionException('cURL error 7: could not connect');
            }
            if ($this->apiMode === 500) {
                return Http::response(['message' => 'boom'], 500);
            }
            if ($this->apiMode === 'rubbish') {
                return Http::response('<html>gateway timeout</html>', 200);
            }
            if ($this->apiMode === 'nodata') {
                return Http::response(['ok' => true], 200);
            }
            if (str_contains($request->url(), 'customers-sync')) {
                return Http::response(['data' => $this->apiCustomers], 200);
            }
            if (str_contains($request->url(), 'vehicles-sync')) {
                return Http::response(['data' => $this->apiVehicles], 200);
            }

            return Http::response(['data' => []], 200);
        });
    }

    private function admin(): Admin
    {
        return $this->admin ??= $this->makeAdmin('ADMIN');
    }

    private function customer(string $name, ?string $status = 'not_verified', array $over = []): CustomerAd
    {
        $n = $this->nextIntakeNumber();

        return CustomerAd::create(array_merge([
            'customer_id'  => (string) Str::uuid(),
            'full_name'    => $name,
            'email'        => "autotest+setup{$n}@example.test",
            'phone_number' => '+9477' . str_pad((string) (7000000 + $n), 7, '0', STR_PAD_LEFT),
            'cus_status'   => $status,
        ], $over));
    }

    private function vehicle(CustomerAd $c, array $over = []): VehicleAd
    {
        return VehicleAd::create(array_merge([
            'vehicle_id'     => (string) Str::uuid(),
            'customer_id'    => $c->customer_id,
            'customer_name'  => $c->full_name,
            'vehicle_number' => 'AUTO-' . $this->nextIntakeNumber(),
            'make'           => 'Toyota',
            'model'          => 'Aqua',
            'has_gps_device' => false,
        ], $over));
    }

    /** The two lists as the page script receives them. */
    private function panels(): array
    {
        $json = $this->actingAs($this->admin())->getJson(route('admin.customer-setup.refresh'))->assertOk()->json();

        return [$json['active_html'], $json['inactive_html']];
    }

    private function toggle(string $id, mixed $status)
    {
        return $this->actingAs($this->admin())->patchJson(route('admin.customer-setup.toggle-status', $id), ['cus_status' => $status]);
    }

    private function verificationLogs(string $customerId)
    {
        return \App\Models\AuditLog::where('action', 'customer.verification_changed')->where('subject_id', $customerId)->orderBy('id')->get();
    }

    public function test_marking_a_customer_verified_or_not_is_written_to_the_audit_log(): void
    {
        $c = CustomerAd::create(['customer_id' => (string) \Illuminate\Support\Str::uuid(), 'full_name' => 'Autotest Audit Person', 'cus_status' => 'not_verified']);
        $admin = $this->admin();

        $this->actingAs($admin)->patchJson(route('admin.customer-setup.toggle-status', $c->customer_id), ['cus_status' => 'verified'])->assertOk();
        $this->actingAs($admin)->patchJson(route('admin.customer-setup.toggle-status', $c->customer_id), ['cus_status' => 'not_verified'])->assertOk();

        $logs = $this->verificationLogs($c->customer_id);
        $this->assertCount(2, $logs);
        $this->assertEquals(['cus_status' => ['from' => 'not_verified', 'to' => 'verified']], $logs[0]->changes);
        $this->assertEquals(['cus_status' => ['from' => 'verified', 'to' => 'not_verified']], $logs[1]->changes);
        $this->assertSame($admin->admin_id, $logs[0]->actor_id);
        $this->assertSame('Autotest Audit Person', $logs[0]->subject_label);
        $this->assertSame('customer', $logs[0]->subject_type);
    }

    public function test_saving_the_same_status_again_or_a_refused_change_adds_no_audit_entry(): void
    {
        $c = CustomerAd::create(['customer_id' => (string) \Illuminate\Support\Str::uuid(), 'full_name' => 'Autotest Same Person', 'cus_status' => 'verified']);
        $admin = $this->admin();

        $this->actingAs($admin)->patchJson(route('admin.customer-setup.toggle-status', $c->customer_id), ['cus_status' => 'verified'])->assertOk();
        $this->actingAs($admin)->patchJson(route('admin.customer-setup.toggle-status', $c->customer_id), ['cus_status' => 'anything-else'])->assertStatus(422);
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->makeAdmin('DEALER'))->patchJson(route('admin.customer-setup.toggle-status', $c->customer_id), ['cus_status' => 'not_verified'])->assertForbidden();

        $this->assertCount(0, $this->verificationLogs($c->customer_id));
        $this->assertSame('verified', $c->fresh()->cus_status);
    }

    private function apiCustomer(string $id, array $over = []): array
    {
        return array_merge([
            'customerId' => $id, 'fullName' => 'Autotest Synced Person', 'email' => 'autotest+synced@example.test',
            'phoneNumber' => '+94771110000', 'nicNumber' => '200012345678', 'address' => 'Colombo',
            'vehicleCount' => 1, 'accountStatus' => 1,   // the app sends its account status as a number
        ], $over);
    }

    // ================================================================== opening the page syncs first

    public function test_opening_the_page_fetches_customers_and_vehicles_with_the_secret_key_and_saves_them(): void
    {
        $id = (string) Str::uuid();
        $vid = (string) Str::uuid();
        $this->apiCustomers = [$this->apiCustomer($id, ['fullName' => 'Autotest Nimal Perera', 'nicNumber' => '199912345678'])];
        $this->apiVehicles  = [['vehicleId' => $vid, 'customerId' => $id, 'customerName' => 'Autotest Nimal Perera', 'vehicleNumber' => 'CAB-1234',
            'make' => 'Honda', 'model' => 'Vezel', 'hasGpsDevice' => true, 'imei' => '864876170981409']];

        $html = $this->actingAs($this->admin())->get(route('admin.customer-setup'))->assertOk()->getContent();

        $row = CustomerAd::find($id);
        $this->assertNotNull($row);
        $this->assertSame('Autotest Nimal Perera', $row->full_name);
        $this->assertSame('199912345678', $row->nic_number);
        $this->assertEquals(1, $row->source_account_status);
        $this->assertSame('not_verified', $row->cus_status);          // a new customer starts Not Verified
        $this->assertSame('CAB-1234', VehicleAd::where('vehicle_id', $vid)->value('vehicle_number'));

        $urls = array_map(fn ($r) => $r->url(), $this->sent);
        $this->assertNotEmpty(array_filter($urls, fn ($u) => str_contains($u, '/api/internal/customers-sync')));
        $this->assertNotEmpty(array_filter($urls, fn ($u) => str_contains($u, '/api/internal/vehicles-sync')));
        foreach ($this->sent as $r) {
            $this->assertSame('autotest-sync-key', $r->header('X-Admin-Sync-Key')[0] ?? null);
        }
        $this->assertStringContainsString('Autotest Nimal Perera', $html);
    }

    public function test_a_synced_customer_is_saved_again_not_copied_and_the_name_follows_the_app(): void
    {
        $id = (string) Str::uuid();
        $this->apiCustomers = [$this->apiCustomer($id, ['fullName' => 'Autotest Old Name'])];
        $this->actingAs($this->admin())->get(route('admin.customer-setup'))->assertOk();

        $this->apiCustomers = [$this->apiCustomer($id, ['fullName' => 'Autotest New Name'])];
        $this->get(route('admin.customer-setup'))->assertOk();

        $this->assertSame(1, CustomerAd::where('customer_id', $id)->count());
        $this->assertSame('Autotest New Name', CustomerAd::find($id)->full_name);
    }

    public function test_the_sync_never_changes_a_status_an_admin_chose(): void
    {
        $c = $this->customer('Autotest Chosen', 'verified');
        $this->apiCustomers = [$this->apiCustomer($c->customer_id, ['fullName' => 'Autotest Chosen', 'accountStatus' => 2])];

        $this->actingAs($this->admin())->get(route('admin.customer-setup'))->assertOk();

        $this->assertSame('verified', CustomerAd::find($c->customer_id)->cus_status);
        $this->assertEquals(2, CustomerAd::find($c->customer_id)->source_account_status);
    }

    public function test_a_customer_the_app_server_stops_listing_is_not_deleted_here(): void
    {
        $c = $this->customer('Autotest Gone From App', 'verified');
        $this->apiCustomers = [];

        $this->actingAs($this->admin())->get(route('admin.customer-setup'))->assertOk()->assertSee('Autotest Gone From App');

        $this->assertNotNull(CustomerAd::find($c->customer_id));
    }

    public static function brokenAnswers(): array
    {
        return ['cannot connect' => ['down'], 'server error' => [500], 'not json' => ['rubbish'], 'no data key' => ['nodata']];
    }

    #[DataProvider('brokenAnswers')]
    public function test_a_broken_app_server_never_breaks_the_page_and_the_saved_customers_still_show(int|string $mode): void
    {
        $c = $this->customer('Autotest Saved Before', 'verified');
        $this->apiMode = $mode;

        $this->actingAs($this->admin())->get(route('admin.customer-setup'))->assertOk()->assertSee('Autotest Saved Before');

        $this->assertNotNull(CustomerAd::find($c->customer_id));
    }

    #[DataProvider('brokenAnswers')]
    public function test_a_broken_app_server_never_breaks_the_refresh_button_either(int|string $mode): void
    {
        $this->customer('Autotest Saved Refresh', 'not_verified');
        $this->apiMode = $mode;

        $json = $this->actingAs($this->admin())->getJson(route('admin.customer-setup.refresh'))->assertOk()->json();

        $this->assertStringContainsString('Autotest Saved Refresh', $json['inactive_html']);
    }

    // ================================================================== the two lists

    public function test_active_means_exactly_verified_and_everything_else_is_inactive(): void
    {
        $this->customer('Autotest Is Verified', 'verified');
        $this->customer('Autotest Is Not Verified', 'not_verified');

        [$active, $inactive] = $this->panels();

        $this->assertStringContainsString('Autotest Is Verified', $active);
        $this->assertStringNotContainsString('Autotest Is Not Verified', $active);
        $this->assertStringContainsString('Autotest Is Not Verified', $inactive);
        $this->assertStringNotContainsString('Autotest Is Verified', $inactive);
    }

    public function test_each_list_is_in_name_order(): void
    {
        $this->customer('Autotest Zed', 'verified');
        $this->customer('Autotest Amal', 'verified');
        $this->customer('Autotest Kamal', 'verified');

        [$active] = $this->panels();

        $this->assertLessThan(strpos($active, 'Autotest Kamal'), strpos($active, 'Autotest Amal'));
        $this->assertLessThan(strpos($active, 'Autotest Zed'), strpos($active, 'Autotest Kamal'));
    }

    public function test_the_count_of_each_list_is_shown(): void
    {
        $this->customer('Autotest Count A', 'verified');
        $this->customer('Autotest Count B', 'verified');

        [$active] = $this->panels();

        $this->assertMatchesRegularExpression('/\d+ customers/', $active);
        $total = CustomerAd::where('cus_status', 'verified')->count();
        $this->assertStringContainsString("{$total} customers", $active);
    }

    public function test_a_customer_shows_name_phone_email_and_nic(): void
    {
        $c = $this->customer('Autotest Full Row', 'verified', ['email' => 'autotest+fullrow@example.test', 'phone_number' => '+94771234567', 'nic_number' => '199812345V']);

        [$active] = $this->panels();

        foreach (['Autotest Full Row', 'autotest+fullrow@example.test', '+94771234567', '199812345V'] as $text) {
            $this->assertStringContainsString($text, $active);
        }
        $this->assertStringContainsString($c->customer_id, $active);     // the switch knows which customer it changes
    }

    public function test_a_customer_with_no_nic_shows_a_dash(): void
    {
        $this->customer('Autotest No Nic', 'verified', ['nic_number' => null]);

        [$active] = $this->panels();

        $this->assertStringContainsString('—', $active);
    }

    public function test_vehicles_show_with_a_gps_badge_and_a_customer_without_vehicles_says_so(): void
    {
        $with = $this->customer('Autotest With Cars', 'verified');
        $this->vehicle($with, ['vehicle_number' => 'AUTO-GPS-1', 'make' => 'Nissan', 'model' => 'Leaf', 'has_gps_device' => true, 'imei' => '864876170981409']);
        $this->vehicle($with, ['vehicle_number' => 'AUTO-PLAIN-2', 'has_gps_device' => false, 'imei' => '868159941856606']);
        $this->customer('Autotest Without Cars', 'verified');

        [$active] = $this->panels();

        $this->assertStringContainsString('AUTO-GPS-1', $active);
        $this->assertStringContainsString('Nissan Leaf', $active);
        $this->assertStringContainsString('GPS · 864876170981409', $active);
        $this->assertStringContainsString('AUTO-PLAIN-2', $active);
        $this->assertStringNotContainsString('868159941856606', $active);          // no badge, so no IMEI shown
        $this->assertStringContainsString('No vehicles', $active);
    }

    public function test_a_vehicle_without_a_plate_says_no_plate(): void
    {
        $c = $this->customer('Autotest Plateless', 'verified');
        $this->vehicle($c, ['vehicle_number' => null]);

        [$active] = $this->panels();

        $this->assertStringContainsString('No plate', $active);
    }

    public function test_a_vehicle_of_one_customer_never_shows_under_another(): void
    {
        $a = $this->customer('Autotest Owner A', 'verified');
        $this->customer('Autotest Owner B', 'verified');
        $this->vehicle($a, ['vehicle_number' => 'AUTO-ONLY-A']);

        [$active] = $this->panels();

        $this->assertSame(1, substr_count($active, 'AUTO-ONLY-A'));
        $this->assertSame(1, substr_count($active, 'No vehicles'));
    }

    // ================================================================== "Via Dealer"

    public function test_a_customer_registered_by_a_dealer_shows_the_dealer_and_others_show_direct(): void
    {
        $dealer = $this->makeDealer(['full_name' => 'Autotest Via Dealer Ltd', 'region' => 'kandy']);
        $c = $this->customer('Autotest Dealer Lead', 'verified', ['email' => 'autotest+lead@example.test']);
        DealerCustomerAd::create(['dealer_id' => $dealer->id, 'name' => 'Autotest Dealer Lead', 'email' => 'autotest+lead@example.test', 'contact' => '0770000001']);
        $this->customer('Autotest Direct Person', 'verified');

        [$active] = $this->panels();

        $this->assertStringContainsString('Via Dealer', $active);
        $this->assertStringContainsString('Autotest Via Dealer Ltd', $active);
        $this->assertStringContainsString('Kandy', $active);
        $this->assertStringContainsString('Direct / Unknown', $active);
        $this->assertSame(1, substr_count($active, 'Direct / Unknown'));
    }

    public function test_the_dealer_is_found_by_phone_when_the_email_does_not_match(): void
    {
        $dealer = $this->makeDealer(['full_name' => 'Autotest Phone Dealer']);
        $this->customer('Autotest Phone Match', 'verified', ['email' => 'autotest+other@example.test', 'phone_number' => '+94771239876']);
        DealerCustomerAd::create(['dealer_id' => $dealer->id, 'name' => 'Autotest Phone Match', 'email' => 'autotest+different@example.test', 'contact' => '0771239876']);

        [$active] = $this->panels();

        $this->assertStringContainsString('Autotest Phone Dealer', $active);
    }

    public function test_the_email_match_wins_over_the_phone_match(): void
    {
        $byEmail = $this->makeDealer(['full_name' => 'Autotest Email Dealer']);
        $byPhone = $this->makeDealer(['full_name' => 'Autotest Phone Only Dealer']);
        $this->customer('Autotest Both Match', 'verified', ['email' => 'autotest+both@example.test', 'phone_number' => '+94771112233']);
        DealerCustomerAd::create(['dealer_id' => $byEmail->id, 'name' => 'x', 'email' => 'autotest+both@example.test', 'contact' => '0700000000']);
        DealerCustomerAd::create(['dealer_id' => $byPhone->id, 'name' => 'y', 'email' => 'autotest+nobody@example.test', 'contact' => '0771112233']);

        [$active] = $this->panels();

        $this->assertStringContainsString('Autotest Email Dealer', $active);
        $this->assertStringNotContainsString('Autotest Phone Only Dealer', $active);
    }

    // ================================================================== the switch

    public function test_switching_to_verified_saves_it_and_moves_the_customer_to_the_active_list(): void
    {
        $c = $this->customer('Autotest To Verify', 'not_verified');

        $json = $this->toggle($c->customer_id, 'verified')->assertOk()->json();

        $this->assertSame('verified', CustomerAd::find($c->customer_id)->cus_status);
        $this->assertStringContainsString('Autotest To Verify', $json['active_html']);
        $this->assertStringNotContainsString('Autotest To Verify', $json['inactive_html']);
    }

    public function test_switching_to_not_verified_saves_it_and_moves_the_customer_to_the_inactive_list(): void
    {
        $c = $this->customer('Autotest To Unverify', 'verified');

        $json = $this->toggle($c->customer_id, 'not_verified')->assertOk()->json();

        $this->assertSame('not_verified', CustomerAd::find($c->customer_id)->cus_status);
        $this->assertStringContainsString('Autotest To Unverify', $json['inactive_html']);
        $this->assertStringNotContainsString('Autotest To Unverify', $json['active_html']);
    }

    public function test_switching_to_the_status_it_already_has_is_harmless(): void
    {
        $c = $this->customer('Autotest Same Status', 'verified');

        $this->toggle($c->customer_id, 'verified')->assertOk();

        $this->assertSame('verified', CustomerAd::find($c->customer_id)->cus_status);
    }

    public function test_switching_one_customer_never_touches_another(): void
    {
        $a = $this->customer('Autotest Switch A', 'not_verified');
        $b = $this->customer('Autotest Switch B', 'not_verified');

        $this->toggle($a->customer_id, 'verified')->assertOk();

        $this->assertSame('not_verified', CustomerAd::find($b->customer_id)->cus_status);
    }

    public static function badStatuses(): array
    {
        return [
            'empty'           => [''],
            'null'            => [null],
            'a made-up word'  => ['approved'],
            'capital letters' => ['VERIFIED'],
            'an array'        => [['verified']],
            'a number'        => [1],
            'a script'        => ['<script>alert(1)</script>'],
            'sql'             => ["verified'; DROP TABLE \"Customer-ad\"; --"],
        ];
    }

    #[DataProvider('badStatuses')]
    public function test_a_wrong_status_is_refused_and_nothing_changes(mixed $bad): void
    {
        $c = $this->customer('Autotest Bad Status', 'not_verified');

        $this->toggle($c->customer_id, $bad)->assertStatus(422)->assertJsonValidationErrors('cus_status');

        $this->assertSame('not_verified', CustomerAd::find($c->customer_id)->cus_status);
    }

    public function test_a_status_with_spaces_around_it_is_trimmed_and_saved_as_verified(): void
    {
        $c = $this->customer('Autotest Padded', 'not_verified');

        $this->toggle($c->customer_id, '  verified  ')->assertOk();

        $this->assertSame('verified', CustomerAd::find($c->customer_id)->cus_status);
    }

    public function test_no_status_at_all_is_refused(): void
    {
        $c = $this->customer('Autotest No Body', 'not_verified');

        $this->actingAs($this->admin())->patchJson(route('admin.customer-setup.toggle-status', $c->customer_id), [])->assertStatus(422);

        $this->assertSame('not_verified', CustomerAd::find($c->customer_id)->cus_status);
    }

    public function test_a_customer_that_does_not_exist_is_a_404_and_creates_nothing(): void
    {
        $before = CustomerAd::count();

        $this->toggle((string) Str::uuid(), 'verified')->assertNotFound();

        $this->assertSame($before, CustomerAd::count());
    }

    public static function strangeIds(): array
    {
        return ['letters' => ['abc'], 'a number' => ['12345'], 'sql' => ["1%27%20or%20%271%27%3D%271"], 'very long' => [str_repeat('a', 300)], 'script' => ['%3Cscript%3E']];
    }

    #[DataProvider('strangeIds')]
    public function test_a_strange_id_is_a_404_not_a_crash_and_changes_nothing(string $id): void
    {
        $c = $this->customer('Autotest Untouched', 'not_verified');

        $this->actingAs($this->admin())->patchJson('/admin/customer/setup/' . $id . '/status', ['cus_status' => 'verified'])->assertNotFound();

        $this->assertSame('not_verified', CustomerAd::find($c->customer_id)->cus_status);
    }

    public function test_the_switch_address_does_not_accept_get_post_or_put(): void
    {
        $c = $this->customer('Autotest Verbs', 'not_verified');
        $url = route('admin.customer-setup.toggle-status', $c->customer_id);

        $this->actingAs($this->admin())->get($url)->assertStatus(405);
        $this->post($url, ['cus_status' => 'verified'])->assertStatus(405);
        $this->put($url, ['cus_status' => 'verified'])->assertStatus(405);

        $this->assertSame('not_verified', CustomerAd::find($c->customer_id)->cus_status);
    }

    public function test_the_switch_does_not_ask_the_app_server_anything(): void
    {
        $c = $this->customer('Autotest Local Only', 'not_verified');

        $this->toggle($c->customer_id, 'verified')->assertOk();

        $this->assertSame([], $this->sent);
    }

    // ================================================================== names are text

    public function test_names_and_emails_from_the_app_are_shown_as_text_never_run(): void
    {
        $dealer = $this->makeDealer(['full_name' => '<img src=x onerror=alert(2)>']);
        $this->customer('<script>alert(1)</script>', 'verified', ['email' => 'autotest+xss@example.test']);
        $c = $this->customer('Autotest Xss Lead', 'verified', ['email' => 'autotest+xsslead@example.test']);
        DealerCustomerAd::create(['dealer_id' => $dealer->id, 'name' => 'x', 'email' => 'autotest+xsslead@example.test', 'contact' => '0700000009']);
        $this->vehicle($c, ['vehicle_number' => '<b>BAD</b>', 'make' => '<i>x</i>']);

        [$active] = $this->panels();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $active);
        $this->assertStringNotContainsString('<img src=x', $active);
        $this->assertStringNotContainsString('<b>BAD</b>', $active);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $active);
    }

    // ================================================================== the page itself

    public function test_the_page_has_both_panels_and_the_script_addresses(): void
    {
        $this->customer('Autotest Page Customer', 'verified');

        $html = $this->actingAs($this->admin())->get(route('admin.customer-setup'))->assertOk()->getContent();

        $this->assertStringContainsString('active-customers-panel', $html);
        $this->assertStringContainsString('inactive-customers-panel', $html);
        $this->assertStringContainsString('/admin/customer/setup/refresh', $html);
        $this->assertStringContainsString('Autotest Page Customer', $html);
    }

    public function test_the_refresh_answer_has_exactly_the_two_lists(): void
    {
        $json = $this->actingAs($this->admin())->getJson(route('admin.customer-setup.refresh'))->assertOk()->json();

        $this->assertEqualsCanonicalizing(['active_html', 'inactive_html'], array_keys($json));
        $this->assertIsString($json['active_html']);
        $this->assertIsString($json['inactive_html']);
    }

    public function test_the_lists_say_when_they_are_empty(): void
    {
        CustomerAd::query()->delete();

        [$active, $inactive] = $this->panels();

        $this->assertStringContainsString('No active customers.', $active);
        $this->assertStringContainsString('No inactive customers.', $inactive);
    }

    // ================================================================== who may use it

    public static function otherRoles(): array
    {
        return ['dealer' => ['DEALER'], 'distributor' => ['DISTRIBUTOR'], 'finance' => ['FINANCE'], 'technician' => ['TECHNICIAN'], 'supplier' => ['SUPPLIER']];
    }

    #[DataProvider('otherRoles')]
    public function test_only_the_company_admin_may_open_the_page_or_refresh(string $role): void
    {
        $this->actingAs($this->makeAdmin($role))->get(route('admin.customer-setup'))->assertForbidden();
        $this->getJson(route('admin.customer-setup.refresh'))->assertForbidden();
        $this->assertSame([], $this->sent);                       // and nothing was pulled from the app server
    }

    #[DataProvider('otherRoles')]
    public function test_only_the_company_admin_may_switch_a_status(string $role): void
    {
        $c = $this->customer('Autotest Role Guard', 'not_verified');

        $this->actingAs($this->makeAdmin($role))->patchJson(route('admin.customer-setup.toggle-status', $c->customer_id), ['cus_status' => 'verified'])->assertForbidden();

        $this->assertSame('not_verified', CustomerAd::find($c->customer_id)->cus_status);
    }

    public function test_a_visitor_who_is_not_signed_in_is_sent_to_login_and_nothing_is_changed(): void
    {
        $c = $this->customer('Autotest Guest Guard', 'not_verified');

        $this->get(route('admin.customer-setup'))->assertRedirect();
        $this->get(route('admin.customer-setup.refresh'))->assertRedirect();
        $this->patch(route('admin.customer-setup.toggle-status', $c->customer_id), ['cus_status' => 'verified'])->assertRedirect();

        $this->assertSame('not_verified', CustomerAd::find($c->customer_id)->cus_status);
        $this->assertSame([], $this->sent);
    }
}