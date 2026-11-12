<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\Dealer;
use App\Models\DealerCustomerAd;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsReportData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * THREE SMALL AREAS, on real PostgreSQL (autotest schema):
 *
 *   GET /api/internal/dealer-lookup   called BY the mobile API (not by a person) to find which dealer brought a customer
 *   GET /                             the home address: sends each person to their own dashboard
 *   finance.dashboard, technician.dashboard   the two placeholder dashboards
 *
 * What the tests prove (simple words):
 *   - the dealer lookup answers ONLY when the shared secret header is right: no header, a wrong one, the secret in the
 *     address bar, or a signed-in browser session are all refused (401), and an empty secret in the settings never
 *     lets anyone in,
 *   - it finds the dealer by email (any letter case) or by phone however it is written (0771234567, +94771234567,
 *     94771234567, with spaces or dashes), gives "no dealer" (not an error) when nothing matches, and wildcard characters
 *     typed in the email can never match somebody else's lead,
 *   - it only reads (a POST is refused) and never writes anything,
 *   - "/" sends a guest to login and every role to its own dashboard,
 *   - the Finance and Technician pages open for their own role only and show the person's name as text, never as HTML.
 *
 * Everything made here is rolled back after each test.
 */
class InternalApiAndHomeTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsReportData;

    private const KEY = 'autotest-sync-key';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.shalotrack_api.sync_key' => self::KEY]);
    }

    private function lookup(array $query, ?string $key = self::KEY)
    {
        return $this->withHeaders($key === null ? [] : ['X-Admin-Sync-Key' => $key])->getJson('/api/internal/dealer-lookup?' . http_build_query($query));
    }

    private function lead(Dealer $dealer, array $overrides = []): DealerCustomerAd
    {
        $n = $this->nextIntakeNumber();

        return DealerCustomerAd::create(array_merge([
            'dealer_id' => $dealer->id, 'name' => "Autotest Lead {$n}", 'email' => "autotest+lead{$n}@example.test",
            'nic_or_id' => "AUTO{$n}V", 'contact' => '0771234567', 'no_of_devices' => 1, 'imei_numbers' => [], 'address' => 'Colombo',
        ], $overrides));
    }

    // ================================================================== dealer lookup: the key

    public function test_a_request_with_no_key_is_refused(): void
    {
        $this->lookup(['email' => 'a@example.test'], null)->assertStatus(401)->assertExactJson(['message' => 'Unauthorized.']);
    }

    public static function wrongKeys(): array
    {
        return ['wrong' => ['not-the-key'], 'empty' => [''], 'the key with a space' => [self::KEY . ' '], 'the key in other letter case' => [strtoupper(self::KEY)], 'only the start' => [substr(self::KEY, 0, 8)]];
    }

    #[DataProvider('wrongKeys')]
    public function test_a_wrong_key_is_refused(string $key): void
    {
        $dealer = $this->makeDealer();
        $this->lead($dealer, ['email' => 'secret.lead@example.test']);

        $this->lookup(['email' => 'secret.lead@example.test'], $key)->assertStatus(401)->assertJsonMissing(['dealerName' => $dealer->full_name]);
    }

    public function test_the_key_in_the_address_bar_or_the_body_is_not_accepted(): void
    {
        $this->getJson('/api/internal/dealer-lookup?email=a@example.test&X-Admin-Sync-Key=' . self::KEY)->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Bearer ' . self::KEY])->getJson('/api/internal/dealer-lookup?email=a@example.test')->assertStatus(401);
    }

    public function test_when_no_secret_is_set_in_the_settings_nobody_gets_in_even_with_an_empty_header(): void
    {
        foreach ([null, ''] as $unset) {
            config(['services.shalotrack_api.sync_key' => $unset]);

            $this->lookup(['email' => 'a@example.test'], '')->assertStatus(401);
            $this->lookup(['email' => 'a@example.test'], null)->assertStatus(401);
        }
    }

    public function test_a_signed_in_browser_session_does_not_replace_the_key(): void
    {
        $this->actingAs($this->makeAdmin('ADMIN'))->getJson('/api/internal/dealer-lookup?email=a@example.test')->assertStatus(401);
    }

    public function test_a_wrong_key_is_never_told_whether_a_dealer_exists(): void
    {
        $dealer = $this->makeDealer();
        $this->lead($dealer, ['email' => 'exists@example.test']);

        $a = $this->lookup(['email' => 'exists@example.test'], 'bad')->assertStatus(401)->getContent();
        $b = $this->lookup(['email' => 'nobody@example.test'], 'bad')->assertStatus(401)->getContent();

        $this->assertSame($a, $b);
    }

    public function test_the_lookup_only_reads_so_a_post_put_or_delete_is_refused(): void
    {
        $headers = ['X-Admin-Sync-Key' => self::KEY];

        $this->withHeaders($headers)->postJson('/api/internal/dealer-lookup', ['email' => 'a@example.test'])->assertStatus(405);
        $this->withHeaders($headers)->putJson('/api/internal/dealer-lookup', [])->assertStatus(405);
        $this->withHeaders($headers)->deleteJson('/api/internal/dealer-lookup')->assertStatus(405);
    }

    // ================================================================== dealer lookup: the answer

    public function test_a_known_email_gives_the_dealer_id_and_name(): void
    {
        $dealer = $this->makeDealer(['full_name' => 'Autotest Lookup Dealer']);
        $this->lead($dealer, ['email' => 'known.customer@example.test', 'contact' => '0710000001']);

        $this->lookup(['email' => 'known.customer@example.test'])
            ->assertOk()
            ->assertExactJson(['dealerId' => $dealer->id, 'dealerName' => 'Autotest Lookup Dealer']);
    }

    public function test_the_email_match_ignores_letter_case(): void
    {
        $dealer = $this->makeDealer();
        $this->lead($dealer, ['email' => 'Mixed.Case@Example.Test', 'contact' => '0710000002']);

        $this->lookup(['email' => 'mixed.case@example.test'])->assertOk()->assertJsonPath('dealerId', $dealer->id);
        $this->lookup(['email' => 'MIXED.CASE@EXAMPLE.TEST'])->assertOk()->assertJsonPath('dealerId', $dealer->id);
    }

    public static function phoneWritings(): array
    {
        return [
            'local'            => ['0771234567'],
            'international +'  => ['+94771234567'],
            'international'    => ['94771234567'],
            'with spaces'      => ['077 123 4567'],
            'with dashes'      => ['077-123-4567'],
            'international spaced' => ['+94 77 123 4567'],
            'without the zero' => ['771234567'],
        ];
    }

    #[DataProvider('phoneWritings')]
    public function test_a_phone_is_found_however_it_is_written(string $phone): void
    {
        $dealer = $this->makeDealer();
        $this->lead($dealer, ['email' => 'phone.lead@example.test', 'contact' => '0771234567']);

        $this->lookup(['phone' => $phone])->assertOk()->assertJsonPath('dealerId', $dealer->id);
    }

    public function test_the_email_alone_or_the_phone_alone_is_enough(): void
    {
        $dealer = $this->makeDealer();
        $this->lead($dealer, ['email' => 'either.way@example.test', 'contact' => '0712223334']);

        $this->lookup(['email' => 'either.way@example.test', 'phone' => '0799999999'])->assertOk()->assertJsonPath('dealerId', $dealer->id);
        $this->lookup(['email' => 'someone.else@example.test', 'phone' => '0712223334'])->assertOk()->assertJsonPath('dealerId', $dealer->id);
    }

    public function test_when_nothing_matches_the_answer_is_no_dealer_not_an_error(): void
    {
        $this->lookup(['email' => 'nobody.at.all@example.test', 'phone' => '0700000000'])
            ->assertOk()->assertExactJson(['dealerId' => null, 'dealerName' => null]);
    }

    public function test_a_lookup_with_nothing_to_look_for_matches_nobody(): void
    {
        $this->lead($this->makeDealer(), ['email' => 'present@example.test']);

        $this->lookup([])->assertOk()->assertExactJson(['dealerId' => null, 'dealerName' => null]);
        $this->lookup(['email' => '', 'phone' => ''])->assertOk()->assertExactJson(['dealerId' => null, 'dealerName' => null]);
        $this->lookup(['phone' => 'abc'])->assertOk()->assertExactJson(['dealerId' => null, 'dealerName' => null]);
    }

    public function test_the_answer_has_exactly_two_fields_and_no_customer_details(): void
    {
        $dealer = $this->makeDealer();
        $this->lead($dealer, ['email' => 'private.lead@example.test', 'nic_or_id' => 'PRIVATE123V', 'address' => 'Secret Address 99']);

        $body = $this->lookup(['email' => 'private.lead@example.test'])->assertOk()->getContent();

        $this->assertEqualsCanonicalizing(['dealerId', 'dealerName'], array_keys(json_decode($body, true)));
        $this->assertStringNotContainsString('PRIVATE123V', $body);
        $this->assertStringNotContainsString('Secret Address', $body);
    }

    public function test_wildcard_characters_in_the_email_never_match_somebody_elses_lead(): void
    {
        $dealer = $this->makeDealer();
        $this->lead($dealer, ['email' => 'victim.customer@example.test', 'contact' => '0714445556']);

        foreach (['%', '%@%', 'victim%', '%customer@example.test', 'v_ctim.customer@example.test', '_%'] as $probe) {
            $this->lookup(['email' => $probe])->assertOk()->assertExactJson(['dealerId' => null, 'dealerName' => null]);
        }
    }

    public function test_a_wildcard_still_does_not_hide_the_real_email(): void
    {
        $dealer = $this->makeDealer();
        $this->lead($dealer, ['email' => 'real_name+tag@example.test', 'contact' => '0715556667']);

        $this->lookup(['email' => 'real_name+tag@example.test'])->assertOk()->assertJsonPath('dealerId', $dealer->id);
    }

    public function test_sql_text_and_very_long_input_are_harmless(): void
    {
        $this->lead($this->makeDealer(), ['email' => 'safe@example.test']);
        $leads = DealerCustomerAd::count();

        foreach (["' OR '1'='1", "x'; DROP TABLE dealer_customer_ads;--", '<script>alert(1)</script>', str_repeat('a', 5000) . '@example.test'] as $probe) {
            $this->lookup(['email' => $probe, 'phone' => $probe])->assertOk()->assertExactJson(['dealerId' => null, 'dealerName' => null]);
        }
        $this->assertSame($leads, DealerCustomerAd::count());
    }

    public function test_a_list_instead_of_text_is_handled_without_an_error(): void
    {
        $this->withHeaders(['X-Admin-Sync-Key' => self::KEY])->getJson('/api/internal/dealer-lookup?email[]=a&phone[x]=b')->assertOk()
            ->assertExactJson(['dealerId' => null, 'dealerName' => null]);
    }

    public function test_the_lookup_never_changes_any_data(): void
    {
        $dealer = $this->makeDealer();
        $this->lead($dealer, ['email' => 'readonly@example.test']);
        $before = [DealerCustomerAd::count(), Dealer::count(), \App\Models\CustomerAd::count()];

        $this->lookup(['email' => 'readonly@example.test'])->assertOk();
        $this->lookup(['email' => 'new.person@example.test', 'phone' => '0701112223'])->assertOk();

        $this->assertSame($before, [DealerCustomerAd::count(), Dealer::count(), \App\Models\CustomerAd::count()]);
    }

    // ================================================================== "/" sends people to their own dashboard

    public function test_a_guest_who_opens_the_home_address_is_sent_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public static function homes(): array
    {
        return [
            'admin'       => ['ADMIN', 'admin.dashboard'],
            'dealer'      => ['DEALER', 'dealer.dashboard'],
            'distributor' => ['DISTRIBUTOR', 'distributor.dashboard'],
            'finance'     => ['FINANCE', 'finance.dashboard'],
            'technician'  => ['TECHNICIAN', 'technician.dashboard'],
            'supplier'    => ['SUPPLIER', 'supplier.dashboard'],
        ];
    }

    #[DataProvider('homes')]
    public function test_each_role_is_sent_from_the_home_address_to_its_own_dashboard(string $role, string $route): void
    {
        $this->actingAs($this->makeAdmin($role))->get('/')->assertRedirect(route($route));
    }

    public function test_the_home_address_only_answers_a_get(): void
    {
        $this->post('/')->assertStatus(405);
    }

    // ================================================================== Finance and Technician placeholder pages

    public static function placeholders(): array
    {
        return ['finance' => ['FINANCE', 'finance.dashboard', 'Finance'], 'technician' => ['TECHNICIAN', 'technician.dashboard', 'Technician']];
    }

    #[DataProvider('placeholders')]
    public function test_the_placeholder_page_welcomes_the_person_and_says_there_is_nothing_to_show(string $role, string $route, string $label): void
    {
        $html = $this->actingAs($this->makeAdmin($role, ['full_name' => 'Autotest Person']))->get(route($route))->assertOk()->getContent();

        $this->assertStringContainsString('Welcome, Autotest Person', $html);
        $this->assertStringContainsString("There is no {$label} dashboard set up yet", $html);
        $this->assertStringContainsString("<title>", $html);
    }

    #[DataProvider('placeholders')]
    public function test_a_name_with_html_in_it_is_shown_as_text(string $role, string $route): void
    {
        $html = $this->actingAs($this->makeAdmin($role, ['full_name' => '<script>alert(1)</script>']))->get(route($route))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public static function placeholderOutsiders(): array
    {
        $out = [];
        foreach (['finance.dashboard' => 'FINANCE', 'technician.dashboard' => 'TECHNICIAN'] as $route => $own) {
            foreach (['ADMIN', 'DEALER', 'DISTRIBUTOR', 'FINANCE', 'TECHNICIAN', 'SUPPLIER'] as $role) {
                if ($role !== $own) {
                    $out["$role on $route"] = [$role, $route];
                }
            }
        }

        return $out;
    }

    #[DataProvider('placeholderOutsiders')]
    public function test_the_placeholder_pages_are_for_their_own_role_only(string $role, string $route): void
    {
        $this->actingAs($this->makeAdmin($role))->get(route($route))->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login_from_both_placeholder_pages(): void
    {
        $this->get(route('finance.dashboard'))->assertRedirect(route('login'));
        $this->get(route('technician.dashboard'))->assertRedirect(route('login'));
    }
}