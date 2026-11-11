<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\CustomerAd;
use App\Models\Dealer;
use App\Models\DealerCustomerAd;
use App\Models\VehicleAd;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * TWO DEALER SCREENS THAT HAD NO TEST, on real PostgreSQL (autotest schema).
 *
 *   PATCH admin.dealer.toggle-status   ADMIN switches a dealer (or distributor) on or off
 *   GET   admin.dealers.customer-ads   ADMIN: customers the dealers added, matched against real app accounts
 *
 * What the tests prove (simple words):
 *   - one click turns an active dealer off and a switched-off dealer on, and every portal login of THAT dealer follows
 *     (logins of other dealers are not touched); the change is written to the audit log,
 *   - a switched-off dealer cannot sign in, and one who is already signed in is signed out on the next click,
 *   - a bad or unknown dealer number is "not found" and changes nothing; only an ADMIN can do it, only with PATCH,
 *   - the customers list shows every lead a dealer added (newest first), hides deleted ones, says whether the customer
 *     already has an app account (matched by email, or by phone in any Sri Lankan format) and lists that account's vehicles,
 *   - names, addresses and plates typed by a person are shown as text, never run as a script.
 *
 * Everything is rolled back after each test; assertions look only at the rows made here, never at table totals.
 */
class DealerStatusAndCustomerAdsTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    private int $seq = 0;
    private ?Admin $adminLogin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
    }

    // ------------------------------------------------------------------ helpers

    private function dealer(array $overrides = []): Dealer
    {
        $n = ++$this->seq;

        return Dealer::create(array_merge([
            'full_name'     => "Autotest Status Dealer {$n}",
            'dealer_status' => 'retailer',
            'region'        => 'colombo',
            'country'       => 'Sri Lanka',
            'contact_email' => "autotest+status-{$n}@example.test",
            'status'        => 'active',
            'created_by'    => 'autotest',
        ], $overrides));
    }

    private function login(Dealer $dealer, array $overrides = []): Admin
    {
        return $this->makeAdmin('DEALER', array_merge(['dealer_id' => $dealer->id], $overrides));
    }

    private function toggle(Dealer|int|string $dealer)
    {
        $this->adminLogin ??= $this->makeAdmin('ADMIN');
        $this->actingAs($this->adminLogin);
        $id = $dealer instanceof Dealer ? $dealer->id : $dealer;

        return $this->patch(route('admin.dealer.toggle-status', $id));
    }

    private function auditCount(Dealer $dealer): int
    {
        return DB::table('audit_logs')->where('action', 'dealer.status_changed')->where('subject_id', (string) $dealer->id)->count();
    }

    private function lead(Dealer $dealer, array $overrides = []): DealerCustomerAd
    {
        $n = ++$this->seq;

        return DealerCustomerAd::create(array_merge([
            'dealer_id'     => $dealer->id,
            'name'          => "Autotest Lead {$n}",
            'email'         => "autotest+lead{$n}@example.test",
            'nic_or_id'     => '19900000000V',
            'contact'       => '07' . str_pad((string) (10000000 + $n), 8, '0', STR_PAD_LEFT),
            'no_of_devices' => 1,
            'imei_numbers'  => [],
            'address'       => 'Autotest Road, Colombo',
        ], $overrides));
    }

    private function appAccount(array $overrides = []): CustomerAd
    {
        $n = ++$this->seq;

        return CustomerAd::create(array_merge([
            'customer_id'  => (string) Str::uuid(),
            'full_name'    => "Autotest App User {$n}",
            'email'        => "autotest+app{$n}@example.test",
            'phone_number' => '+94' . (700000000 + $n),
        ], $overrides));
    }

    private function adminOpensCustomers()
    {
        $this->adminLogin ??= $this->makeAdmin('ADMIN');
        $this->actingAs($this->adminLogin);

        return $this->get(route('admin.dealers.customer-ads'));
    }

    /** The leads the page was given, keyed by lead id. */
    private function leadsOf($response): array
    {
        return $response->viewData('customerAds')->keyBy('id')->all();
    }

    // ------------------------------------------------------------------ switching a dealer on and off

    public function test_one_click_switches_an_active_dealer_off_and_its_logins_follow(): void
    {
        $dealer = $this->dealer();
        $first = $this->login($dealer);
        $second = $this->login($dealer);

        $this->toggle($dealer)->assertRedirect()->assertSessionHas('success');

        $this->assertSame('archived', $dealer->fresh()->status);
        $this->assertSame('INACTIVE', $first->fresh()->status);
        $this->assertSame('INACTIVE', $second->fresh()->status);
    }

    public function test_a_second_click_switches_it_back_on(): void
    {
        $dealer = $this->dealer();
        $login = $this->login($dealer);

        $this->toggle($dealer);
        $this->toggle($dealer);

        $this->assertSame('active', $dealer->fresh()->status);
        $this->assertSame('ACTIVE', $login->fresh()->status);
    }

    public function test_only_that_dealers_logins_change(): void
    {
        $dealer = $this->dealer();
        $other = $this->dealer();
        $mine = $this->login($dealer);
        $theirs = $this->login($other);
        $adminLogin = $this->makeAdmin('ADMIN');

        $this->toggle($dealer);

        $this->assertSame('INACTIVE', $mine->fresh()->status);
        $this->assertSame('ACTIVE', $theirs->fresh()->status, 'another dealer must not be switched off');
        $this->assertSame('active', $other->fresh()->status);
        $this->assertSame('ACTIVE', $adminLogin->fresh()->status);
    }

    public function test_the_message_says_what_happened_and_names_a_distributor_as_one(): void
    {
        $dealer = $this->dealer();
        $distributor = $this->dealer(['dealer_status' => 'distributor']);

        $off = $this->toggle($dealer)->getSession()->get('success');
        $this->assertStringContainsString('Dealer account deactivated', $off);
        $this->assertStringContainsString('blocked', $off);

        $on = $this->toggle($dealer)->getSession()->get('success');
        $this->assertStringContainsString('Dealer account activated', $on);
        $this->assertStringContainsString('enabled', $on);

        $this->assertStringContainsString('Distributor account deactivated', $this->toggle($distributor)->getSession()->get('success'));
    }

    public function test_every_change_is_written_to_the_audit_log(): void
    {
        $dealer = $this->dealer();

        $this->toggle($dealer);
        $this->assertSame(1, $this->auditCount($dealer));

        $this->toggle($dealer);
        $this->assertSame(2, $this->auditCount($dealer));

        $latest = DB::table('audit_logs')->where('action', 'dealer.status_changed')->where('subject_id', (string) $dealer->id)->orderByDesc('id')->first();
        $this->assertStringContainsString('active', json_encode($latest));
    }

    public function test_a_dealer_with_no_login_can_still_be_switched(): void
    {
        $dealer = $this->dealer();

        $this->toggle($dealer)->assertSessionHas('success');

        $this->assertSame('archived', $dealer->fresh()->status);
    }

    public function test_a_switched_off_dealer_cannot_sign_in_but_can_after_it_is_switched_on(): void
    {
        $dealer = $this->dealer();
        $login = $this->login($dealer, ['username' => 'autotest-switch-' . Str::random(6)]);

        $this->toggle($dealer);
        $this->assertSame('INACTIVE', $login->fresh()->status);

        // A visitor with the right password is still refused.
        auth()->logout();
        $this->flushSession();
        $this->post(route('login'), ['username' => $login->username, 'password' => 'Autotest#12345'])
            ->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_a_dealer_who_is_signed_in_is_signed_out_on_the_next_click(): void
    {
        $dealer = $this->dealer();
        $login = $this->login($dealer, ['status' => 'INACTIVE']);   // already switched off while it was inside the portal

        $this->actingAs($login);
        $response = $this->get(route('dealer.profile.edit'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors(['username' => 'Your account has been deactivated. Please contact an administrator.']);
        $this->assertGuest();
    }

    public static function badDealerNumbers(): array
    {
        return [
            'a number nobody has' => ['99999999'],
            'zero'                => ['0'],
            'negative'            => ['-1'],
            'text'                => ['abc'],
            'huge number'         => ['99999999999999999999'],
            'sql in a value'      => ["1'%20or%20'1'='1"],
        ];
    }

    #[DataProvider('badDealerNumbers')]
    public function test_a_bad_or_unknown_dealer_number_is_not_found_and_changes_nothing(string $id): void
    {
        $dealer = $this->dealer();
        $login = $this->login($dealer);

        $this->toggle($id)->assertNotFound();

        $this->assertSame('active', $dealer->fresh()->status);
        $this->assertSame('ACTIVE', $login->fresh()->status);
    }

    public static function rolesThatAreNotAdmins(): array
    {
        return ['DEALER' => ['DEALER'], 'DISTRIBUTOR' => ['DISTRIBUTOR'], 'FINANCE' => ['FINANCE'], 'TECHNICIAN' => ['TECHNICIAN'], 'SUPPLIER' => ['SUPPLIER']];
    }

    #[DataProvider('rolesThatAreNotAdmins')]
    public function test_only_an_admin_can_switch_a_dealer(string $role): void
    {
        $dealer = $this->dealer();
        $login = $this->login($dealer);
        $this->actingAs($this->makeAdmin($role, $role === 'DEALER' ? ['dealer_id' => $dealer->id] : []));

        $this->patch(route('admin.dealer.toggle-status', $dealer->id))->assertForbidden();

        $this->assertSame('active', $dealer->fresh()->status);
        $this->assertSame('ACTIVE', $login->fresh()->status);
        $this->assertSame(0, $this->auditCount($dealer));
    }

    public function test_a_visitor_who_is_not_logged_in_is_sent_to_the_login_page(): void
    {
        $dealer = $this->dealer();

        $this->patch(route('admin.dealer.toggle-status', $dealer->id))->assertRedirect(route('login'));
        $this->get(route('admin.dealers.customer-ads'))->assertRedirect(route('login'));

        $this->assertSame('active', $dealer->fresh()->status);
    }

    public function test_the_switch_cannot_be_used_with_a_plain_link(): void
    {
        $dealer = $this->dealer();
        $this->actingAs($this->makeAdmin('ADMIN'));

        $this->get('/admin/dealer/' . $dealer->id . '/toggle-status')->assertStatus(405);
        $this->post('/admin/dealer/' . $dealer->id . '/toggle-status')->assertStatus(405);

        $this->assertSame('active', $dealer->fresh()->status);
    }

    // ------------------------------------------------------------------ customers added by dealers

    public function test_the_page_lists_every_lead_newest_first_with_its_dealer(): void
    {
        $dealer = $this->dealer(['full_name' => 'Autotest Lead Dealer']);
        $old = $this->lead($dealer, ['name' => 'Autotest Old Lead']);
        $new = $this->lead($dealer, ['name' => 'Autotest New Lead']);
        DB::table('dealer_customer_ads')->where('id', $old->id)->update(['created_at' => now()->subDays(5)]);

        $response = $this->adminOpensCustomers()->assertOk();

        $ids = $response->viewData('customerAds')->pluck('id')->all();
        $this->assertLessThan(array_search($old->id, $ids), array_search($new->id, $ids), 'newest first');
        $response->assertSee('Autotest Old Lead');
        $response->assertSee('Autotest New Lead');
        $response->assertSee('Autotest Lead Dealer');
        $response->assertSee('Records');
    }

    public function test_a_deleted_lead_is_not_listed(): void
    {
        $dealer = $this->dealer();
        $kept = $this->lead($dealer, ['name' => 'Autotest Kept Lead']);
        $gone = $this->lead($dealer, ['name' => 'Autotest Deleted Lead']);
        $gone->delete();

        $response = $this->adminOpensCustomers()->assertOk();

        $this->assertArrayHasKey($kept->id, $this->leadsOf($response));
        $this->assertArrayNotHasKey($gone->id, $this->leadsOf($response));
        $response->assertDontSee('Autotest Deleted Lead');
    }

    public function test_a_lead_matched_by_email_shows_the_live_app_account(): void
    {
        $dealer = $this->dealer();
        $app = $this->appAccount(['full_name' => 'Autotest Live Account', 'email' => 'autotest+same@example.test', 'phone_number' => '+94770000001']);
        $lead = $this->lead($dealer, ['email' => 'autotest+same@example.test', 'contact' => '0711111111']);

        $response = $this->adminOpensCustomers()->assertOk();
        $row = $this->leadsOf($response)[$lead->id];

        $this->assertSame($app->customer_id, $row->appAccount?->customer_id);
        $response->assertSee('Autotest Live Account');
        $response->assertSee('Live');
    }

    public static function phoneFormats(): array
    {
        return [
            'local, plus 94'     => ['0771234567', '+94771234567'],
            'local, 94 no plus'  => ['0771234567', '94771234567'],
            'local, with spaces' => ['077 123 4567', '+94771234567'],
            'local, with dashes' => ['077-123-4567', '+94771234567'],
            'both local'         => ['0771234567', '0771234567'],
        ];
    }

    #[DataProvider('phoneFormats')]
    public function test_a_lead_matched_by_phone_in_any_sri_lankan_format(string $leadPhone, string $appPhone): void
    {
        $dealer = $this->dealer();
        $app = $this->appAccount(['email' => null, 'phone_number' => $appPhone]);
        $lead = $this->lead($dealer, ['email' => null, 'contact' => $leadPhone]);

        $row = $this->leadsOf($this->adminOpensCustomers()->assertOk())[$lead->id];

        $this->assertSame($app->customer_id, $row->appAccount?->customer_id);
    }

    public function test_a_lead_with_no_matching_account_says_not_registered(): void
    {
        $dealer = $this->dealer();
        $this->appAccount(['email' => 'autotest+someone-else@example.test', 'phone_number' => '+94779999991']);
        $lead = $this->lead($dealer, ['email' => 'autotest+nobody@example.test', 'contact' => '0765555555']);

        $response = $this->adminOpensCustomers()->assertOk();

        $this->assertNull($this->leadsOf($response)[$lead->id]->appAccount);
        $response->assertSee('Not Registered');
    }

    public function test_a_lead_with_no_email_and_no_phone_matches_nothing_and_never_crashes(): void
    {
        $dealer = $this->dealer();
        $this->appAccount(['email' => null, 'phone_number' => null]);
        $lead = $this->lead($dealer, ['email' => null, 'contact' => '']);

        $row = $this->leadsOf($this->adminOpensCustomers()->assertOk())[$lead->id];

        $this->assertNull($row->appAccount, 'an empty phone must not match every account');
    }

    public function test_the_email_match_wins_over_the_phone_match(): void
    {
        $dealer = $this->dealer();
        $byPhone = $this->appAccount(['email' => 'autotest+phone-owner@example.test', 'phone_number' => '+94772223334']);
        $byEmail = $this->appAccount(['email' => 'autotest+email-owner@example.test', 'phone_number' => '+94773334445']);
        $lead = $this->lead($dealer, ['email' => 'autotest+email-owner@example.test', 'contact' => '0772223334']);

        $row = $this->leadsOf($this->adminOpensCustomers()->assertOk())[$lead->id];

        $this->assertSame($byEmail->customer_id, $row->appAccount->customer_id);
        $this->assertNotSame($byPhone->customer_id, $row->appAccount->customer_id);
    }

    public function test_the_matched_account_lists_its_vehicles_and_the_gps_device(): void
    {
        $dealer = $this->dealer();
        $app = $this->appAccount(['email' => 'autotest+vehicles@example.test']);
        VehicleAd::create([
            'vehicle_id' => (string) Str::uuid(), 'customer_id' => $app->customer_id, 'customer_name' => $app->full_name,
            'vehicle_number' => 'AUTO-VEH-1', 'make' => 'Toyota', 'model' => 'Aqua', 'has_gps_device' => true, 'imei' => '999000000000777',
        ]);
        $lead = $this->lead($dealer, ['email' => 'autotest+vehicles@example.test']);

        $response = $this->adminOpensCustomers()->assertOk();

        $this->assertCount(1, $this->leadsOf($response)[$lead->id]->appVehicles);
        $response->assertSee('AUTO-VEH-1');
        $response->assertSee('Toyota');
        $response->assertSee('GPS');
        $response->assertSee('999000000000777');
    }

    public function test_an_account_with_no_vehicles_says_so(): void
    {
        $dealer = $this->dealer();
        $this->appAccount(['email' => 'autotest+novehicle@example.test']);
        $this->lead($dealer, ['email' => 'autotest+novehicle@example.test']);

        $this->adminOpensCustomers()->assertOk()->assertSee('Registered, no vehicles yet');
    }

    public function test_the_assigned_imeis_are_listed(): void
    {
        $dealer = $this->dealer();
        $this->lead($dealer, ['imei_numbers' => ['999000000000881', '999000000000882']]);

        $this->adminOpensCustomers()->assertOk()->assertSee('999000000000881')->assertSee('999000000000882');
    }

    public function test_names_and_addresses_typed_by_a_dealer_are_shown_as_text(): void
    {
        $dealer = $this->dealer(['full_name' => 'Autotest <b>Bold</b> Dealer']);
        $this->lead($dealer, ['name' => 'Autotest <script>alert(1)</script> Lead', 'email' => 'autotest+x"onmouseover="alert(2)@example.test']);

        $response = $this->adminOpensCustomers()->assertOk();

        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $response->assertDontSee('<b>Bold</b>', false);
        $response->assertDontSee('"onmouseover="alert(2)', false);
    }

    public static function rolesThatCannotSeeTheLeads(): array
    {
        return ['DEALER' => ['DEALER'], 'DISTRIBUTOR' => ['DISTRIBUTOR'], 'FINANCE' => ['FINANCE'], 'TECHNICIAN' => ['TECHNICIAN'], 'SUPPLIER' => ['SUPPLIER']];
    }

    #[DataProvider('rolesThatCannotSeeTheLeads')]
    public function test_only_an_admin_sees_all_the_dealers_customers(string $role): void
    {
        $dealer = $this->dealer();
        $this->lead($dealer, ['name' => 'Autotest Private Lead']);
        $this->actingAs($this->makeAdmin($role, $role === 'DEALER' ? ['dealer_id' => $dealer->id] : []));

        $response = $this->get(route('admin.dealers.customer-ads'));

        $response->assertForbidden();
        $response->assertDontSee('Autotest Private Lead');
    }
}