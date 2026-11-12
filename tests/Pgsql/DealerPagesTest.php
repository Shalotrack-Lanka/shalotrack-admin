<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\Dealer;
use App\Models\SetupShalotrackDevice;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * DEALER SCREENS THAT HAD NO TEST, on real PostgreSQL (autotest schema).
 *
 *   GET  admin.dealer.assigned-devices   every device held by any dealer (ADMIN only), with search and dealer filter
 *   GET  admin.dealer-ledger             static page (ADMIN only)
 *   GET  admin.manage-replacement        static page (ADMIN only)
 *   GET  dealer.profile.edit             a dealer's OWN profile form (DEALER only)
 *   PUT  dealer.profile.update           a dealer saves its OWN profile and, if it wants, a new password
 *
 * Rules the tests prove:
 *   - only the right role opens each page; a logged-out visitor is sent to the login page,
 *   - the assigned-devices search finds an IMEI or SIM (any letter case), % and _ typed by a person are plain letters,
 *     a text / array / too-big value in the filters never crashes the page,
 *   - a dealer edits ONLY itself: it cannot change its role, its status or another dealer, whatever it posts,
 *   - bad input is refused with a message and nothing is saved; a good save is audited,
 *   - names are escaped on the page (no script runs).
 *
 * ONE login per test (a second login mid-test is logged out by AuthenticateSession). Everything is rolled back;
 * assertions look only at the tagged rows made here, never at table totals.
 */
class DealerPagesTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    private int $seq = 0;

    private const OLD_PASSWORD = 'Autotest#12345';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();   // the admin sidebar may ask the app server for the complaint count; nothing real may be called
    }

    // ------------------------------------------------------------------ helpers

    private function dealer(string $name = 'Dealer', array $overrides = []): Dealer
    {
        $n = ++$this->seq;

        return Dealer::create(array_merge([
            'full_name'     => "Autotest {$name} {$n}",
            'dealer_status' => 'retailer',
            'region'        => 'colombo',
            'country'       => 'Sri Lanka',
            'contact_email' => "autotest+dpage-{$n}@example.test",
            'status'        => 'active',
            'created_by'    => 'autotest',
        ], $overrides));
    }

    private function dealerLogin(Dealer $dealer, array $overrides = []): Admin
    {
        $admin = $this->makeAdmin('DEALER', array_merge(['dealer_id' => $dealer->id], $overrides));
        $this->actingAs($admin);

        return $admin;
    }

    private function device(?Dealer $dealer, ?string $sim = null, string $status = 'Activated'): SetupShalotrackDevice
    {
        $n = ++$this->seq;

        return SetupShalotrackDevice::forceCreate([
            'device_category' => 'AUTOTEST-DP',
            'imei_number'     => '9981000' . str_pad((string) $n, 8, '0', STR_PAD_LEFT),
            'sim_number'      => $sim ?? '0711' . str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'status'          => $status,
            'dealer_id'       => $dealer?->id,
        ]);
    }

    /** A profile form a dealer could post, with a few fields changed. */
    private function profileForm(Dealer $dealer, array $changes = []): array
    {
        return array_merge([
            'full_name'     => $dealer->full_name,
            'contact_email' => $dealer->contact_email,
            'phone_number'  => '0771234567',
            'address'       => 'Autotest address',
            'region'        => 'colombo',
            'country'       => 'Sri Lanka',
            'qualification' => 'Autotest qualification',
        ], $changes);
    }

    private function auditCount(string $action, string $subjectId): int
    {
        return DB::table('audit_logs')->where('action', $action)->where('subject_id', $subjectId)->count();
    }

    // ------------------------------------------------------------------ who may open the admin pages

    public static function adminOnlyPages(): array
    {
        return [
            'assigned devices'   => ['admin.dealer.assigned-devices'],
            'dealer ledger'      => ['admin.dealer-ledger'],
            'manage replacement' => ['admin.manage-replacement'],
        ];
    }

    #[DataProvider('adminOnlyPages')]
    public function test_an_admin_can_open_the_page(string $routeName): void
    {
        $this->actingAs($this->makeAdmin('ADMIN'));

        $this->get(route($routeName))->assertOk();
    }

    #[DataProvider('adminOnlyPages')]
    public function test_a_visitor_who_is_not_logged_in_is_sent_to_the_login_page(string $routeName): void
    {
        $this->get(route($routeName))->assertRedirect(route('login'));
    }

    public static function pagesAndOtherRoles(): array
    {
        $cases = [];
        foreach (self::adminOnlyPages() as $label => [$routeName]) {
            foreach (['DEALER', 'DISTRIBUTOR', 'FINANCE', 'TECHNICIAN', 'SUPPLIER'] as $role) {
                $cases["{$label} refused to {$role}"] = [$routeName, $role];
            }
        }

        return $cases;
    }

    #[DataProvider('pagesAndOtherRoles')]
    public function test_every_other_role_is_refused(string $routeName, string $role): void
    {
        $this->actingAs($this->makeAdmin($role));

        $this->get(route($routeName))->assertForbidden();
    }

    // ------------------------------------------------------------------ assigned devices

    public function test_assigned_devices_lists_only_devices_that_a_dealer_holds(): void
    {
        $dealer  = $this->dealer('Holder');
        $held    = $this->device($dealer);
        $company = $this->device(null);   // company stock: no dealer

        $this->actingAs($this->makeAdmin('ADMIN'));

        $this->get(route('admin.dealer.assigned-devices'))
            ->assertOk()
            ->assertSee($held->imei_number)
            ->assertSee($dealer->full_name)
            ->assertDontSee($company->imei_number);
    }

    public function test_the_search_finds_a_device_by_imei_or_by_sim_number(): void
    {
        $dealer = $this->dealer('Search');
        $one    = $this->device($dealer, '0715551001');
        $two    = $this->device($dealer, '0715551002');

        $this->actingAs($this->makeAdmin('ADMIN'));

        $this->get(route('admin.dealer.assigned-devices', ['search' => $one->imei_number]))
            ->assertOk()->assertSee($one->imei_number)->assertDontSee($two->imei_number);

        $this->get(route('admin.dealer.assigned-devices', ['search' => '0715551002']))
            ->assertOk()->assertSee($two->imei_number)->assertDontSee($one->imei_number);
    }

    public function test_the_search_ignores_letter_case(): void
    {
        $dealer = $this->dealer('Case');
        $device = $this->device($dealer, 'sim-AbC-9001');

        $this->actingAs($this->makeAdmin('ADMIN'));

        $this->get(route('admin.dealer.assigned-devices', ['search' => 'SIM-abc-9001']))
            ->assertOk()->assertSee($device->imei_number);
    }

    public function test_a_search_with_no_match_says_so(): void
    {
        $this->actingAs($this->makeAdmin('ADMIN'));

        $this->get(route('admin.dealer.assigned-devices', ['search' => 'no-such-device-zzzz']))
            ->assertOk()
            ->assertSee('No assigned devices match your search.');
    }

    public function test_percent_and_underscore_in_the_search_are_plain_letters(): void
    {
        $dealer = $this->dealer('Wild');
        $device = $this->device($dealer);

        $this->actingAs($this->makeAdmin('ADMIN'));

        // "%" would match everything if it were a wildcard.
        $this->get(route('admin.dealer.assigned-devices', ['search' => '%']))
            ->assertOk()->assertDontSee($device->imei_number);

        // "_" would match any single character if it were a wildcard.
        $this->get(route('admin.dealer.assigned-devices', ['search' => '_']))
            ->assertOk()->assertDontSee($device->imei_number);
    }

    public function test_the_dealer_filter_narrows_to_one_dealer(): void
    {
        $a = $this->dealer('FilterA');
        $b = $this->dealer('FilterB');
        $da = $this->device($a);
        $db = $this->device($b);

        $this->actingAs($this->makeAdmin('ADMIN'));

        $this->get(route('admin.dealer.assigned-devices', ['dealer_id' => $a->id]))
            ->assertOk()->assertSee($da->imei_number)->assertDontSee($db->imei_number);
    }

    public function test_search_and_dealer_filter_work_together(): void
    {
        $a = $this->dealer('BothA');
        $b = $this->dealer('BothB');
        $da1 = $this->device($a);
        $da2 = $this->device($a);
        $db1 = $this->device($b);

        $this->actingAs($this->makeAdmin('ADMIN'));

        $this->get(route('admin.dealer.assigned-devices', ['dealer_id' => $a->id, 'search' => $da1->imei_number]))
            ->assertOk()
            ->assertSee($da1->imei_number)
            ->assertDontSee($da2->imei_number)
            ->assertDontSee($db1->imei_number);
    }

    public static function strangeFilterValues(): array
    {
        return [
            'text id'               => [['dealer_id' => 'abc']],
            'negative id'           => [['dealer_id' => '-5']],
            'zero id'               => [['dealer_id' => '0']],
            'id too big for bigint' => [['dealer_id' => '99999999999999999999']],
            'array id'              => [['dealer_id' => ['1', '2']]],
            'array search'          => [['search' => ['x', 'y']]],
            'very long search'      => [['search' => str_repeat('a', 5000)]],
            'sql in search'         => [['search' => "' OR 1=1 --"]],
        ];
    }

    #[DataProvider('strangeFilterValues')]
    public function test_strange_filter_values_never_crash_the_page(array $query): void
    {
        $this->actingAs($this->makeAdmin('ADMIN'));

        $this->get(route('admin.dealer.assigned-devices', $query))->assertOk();
    }

    public function test_a_script_in_a_dealer_name_is_shown_as_text(): void
    {
        $dealer = $this->dealer('Xss', ['full_name' => 'Autotest <script>alert(1)</script> Xss']);
        $this->device($dealer);

        $this->actingAs($this->makeAdmin('ADMIN'));

        $this->get(route('admin.dealer.assigned-devices'))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    // ------------------------------------------------------------------ dealer profile: open

    public function test_a_dealer_opens_its_own_profile_form_with_its_own_details(): void
    {
        $mine  = $this->dealer('Mine', ['full_name' => 'Autotest Mine Profile']);
        $other = $this->dealer('Other', ['full_name' => 'Autotest Other Profile']);
        $this->dealerLogin($mine, ['phone_number' => '0779990001']);

        $this->get(route('dealer.profile.edit'))
            ->assertOk()
            ->assertSee('Autotest Mine Profile')
            ->assertSee('0779990001')
            ->assertDontSee('Autotest Other Profile');
    }

    public function test_a_dealer_login_with_no_dealer_record_is_refused_on_the_profile_form(): void
    {
        $this->actingAs($this->makeAdmin('DEALER', ['dealer_id' => null]));

        $this->get(route('dealer.profile.edit'))->assertForbidden();
    }

    public function test_a_dealer_login_with_no_dealer_record_cannot_save_a_profile(): void
    {
        $this->actingAs($this->makeAdmin('DEALER', ['dealer_id' => null]));

        $this->put(route('dealer.profile.update'), [
            'full_name' => 'Autotest Nobody', 'contact_email' => 'autotest+nobody@example.test',
        ])->assertForbidden();
    }

    public static function rolesThatAreNotDealers(): array
    {
        return ['ADMIN' => ['ADMIN'], 'DISTRIBUTOR' => ['DISTRIBUTOR'], 'FINANCE' => ['FINANCE'], 'TECHNICIAN' => ['TECHNICIAN'], 'SUPPLIER' => ['SUPPLIER']];
    }

    #[DataProvider('rolesThatAreNotDealers')]
    public function test_other_roles_cannot_open_or_save_the_dealer_profile(string $role): void
    {
        $dealer = $this->dealer('Guarded');
        $this->actingAs($this->makeAdmin($role));

        $this->get(route('dealer.profile.edit'))->assertForbidden();

        $this->put(route('dealer.profile.update'), $this->profileForm($dealer, ['full_name' => 'Autotest Hijacked']))
            ->assertForbidden();

        $this->assertSame($dealer->full_name, Dealer::find($dealer->id)->full_name);
    }

    public function test_a_visitor_who_is_not_logged_in_cannot_use_the_dealer_profile(): void
    {
        $this->get(route('dealer.profile.edit'))->assertRedirect(route('login'));
        $this->put(route('dealer.profile.update'), [])->assertRedirect(route('login'));
    }

    public function test_a_script_in_the_dealer_name_is_shown_as_text_on_the_profile_form(): void
    {
        $dealer = $this->dealer('XssProfile', ['full_name' => 'Autotest <script>alert(2)</script>']);
        $this->dealerLogin($dealer);

        $this->get(route('dealer.profile.edit'))
            ->assertOk()
            ->assertDontSee('<script>alert(2)</script>', false);
    }

    // ------------------------------------------------------------------ dealer profile: save

    public function test_a_dealer_can_save_its_profile(): void
    {
        $dealer = $this->dealer('Save');
        $admin  = $this->dealerLogin($dealer);

        $this->from(route('dealer.profile.edit'))
            ->put(route('dealer.profile.update'), $this->profileForm($dealer, [
                'full_name'     => 'Autotest Saved Name',
                'contact_email' => 'autotest+saved@example.test',
                'phone_number'  => '+94 77 123 4567',
                'address'       => 'Autotest New Street',
                'region'        => 'kandy',
                'qualification' => 'Autotest MBA',
            ]))
            ->assertRedirect(route('dealer.profile.edit'))
            ->assertSessionHas('success');

        $dealer->refresh();
        $this->assertSame('Autotest Saved Name', $dealer->full_name);
        $this->assertSame('autotest+saved@example.test', $dealer->contact_email);
        $this->assertSame('Autotest New Street', $dealer->address);
        $this->assertSame('kandy', $dealer->region);
        $this->assertSame('Autotest MBA', $dealer->qualification);

        // The login row is kept in step with the dealer row.
        $admin->refresh();
        $this->assertSame('Autotest Saved Name', $admin->full_name);
        $this->assertSame('autotest+saved@example.test', $admin->email);
        $this->assertSame('+94 77 123 4567', $admin->phone_number);
    }

    public function test_saving_without_a_new_password_keeps_the_old_password(): void
    {
        $dealer = $this->dealer('KeepPw');
        $admin  = $this->dealerLogin($dealer);

        $this->put(route('dealer.profile.update'), $this->profileForm($dealer))->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $admin->fresh()->password));
    }

    public function test_a_good_new_password_is_saved_as_a_hash(): void
    {
        $dealer = $this->dealer('NewPw');
        $admin  = $this->dealerLogin($dealer);

        $this->put(route('dealer.profile.update'), $this->profileForm($dealer, [
            'new_password' => 'Brand#NewPass9', 'new_password_confirmation' => 'Brand#NewPass9',
        ]))->assertSessionHasNoErrors();

        $stored = $admin->fresh()->password;
        $this->assertNotSame('Brand#NewPass9', $stored);
        $this->assertTrue(Hash::check('Brand#NewPass9', $stored));
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, $stored));
    }

    public function test_a_dealer_cannot_take_an_email_that_belongs_to_another_dealer(): void
    {
        $other  = $this->dealer('Other');
        $dealer = $this->dealer('Mine', ['full_name' => 'Autotest Mine Unchanged']);
        $admin  = $this->dealerLogin($dealer);

        $this->from(route('dealer.profile.edit'))
            ->put(route('dealer.profile.update'), $this->profileForm($dealer, ['contact_email' => $other->contact_email]))
            ->assertRedirect(route('dealer.profile.edit'))
            ->assertSessionHasErrors(['contact_email' => 'This email is already registered to another dealer.']);

        $this->assertSame('Autotest Mine Unchanged', $dealer->fresh()->full_name);
        $this->assertNotSame($other->contact_email, $dealer->fresh()->contact_email);
        $this->assertNotSame($other->contact_email, $admin->fresh()->email);
    }

    public function test_a_dealer_can_keep_or_change_to_a_free_email(): void
    {
        $dealer = $this->dealer('Keep');
        $this->dealerLogin($dealer);

        $this->put(route('dealer.profile.update'), $this->profileForm($dealer))->assertSessionHasNoErrors();
        $this->put(route('dealer.profile.update'), $this->profileForm($dealer, ['contact_email' => 'autotest+free-' . uniqid() . '@example.test']))->assertSessionHasNoErrors();
    }

    public function test_a_too_short_new_password_names_the_real_minimum_of_ten(): void
    {
        $dealer = $this->dealer('Short');
        $this->dealerLogin($dealer);

        $response = $this->from(route('dealer.profile.edit'))->put(route('dealer.profile.update'), $this->profileForm($dealer, ['new_password' => 'Ab1', 'new_password_confirmation' => 'Ab1']));

        $message = session('errors')->first('new_password');
        $this->assertStringContainsString('10', $message);
        $this->assertStringNotContainsString('8 characters', $message);
    }

    public function test_a_blank_address_keeps_the_old_address(): void
    {
        $dealer = $this->dealer('KeepAddr');
        $dealer->forceFill(['address' => 'Autotest Old Address'])->save(); // address is not mass-assignable
        $this->dealerLogin($dealer);

        $this->put(route('dealer.profile.update'), $this->profileForm($dealer, ['address' => '']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Autotest Old Address', $dealer->fresh()->address);
    }

    public function test_a_good_save_is_written_to_the_audit_log_once(): void
    {
        $dealer = $this->dealer('Audit');
        $admin  = $this->dealerLogin($dealer);

        $before = $this->auditCount('account.profile_updated', $admin->admin_id);

        $this->put(route('dealer.profile.update'), $this->profileForm($dealer, ['full_name' => 'Autotest Audited']))
            ->assertSessionHasNoErrors();

        $this->assertSame($before + 1, $this->auditCount('account.profile_updated', $admin->admin_id));
    }

    public function test_the_audit_log_never_stores_the_password(): void
    {
        $dealer = $this->dealer('AuditPw');
        $admin  = $this->dealerLogin($dealer);

        $this->put(route('dealer.profile.update'), $this->profileForm($dealer, [
            'new_password' => 'Secret#Audit77', 'new_password_confirmation' => 'Secret#Audit77',
        ]))->assertSessionHasNoErrors();

        $row = DB::table('audit_logs')
            ->where('action', 'account.profile_updated')->where('subject_id', $admin->admin_id)->first();

        $this->assertNotNull($row);
        $this->assertStringNotContainsString('Secret#Audit77', json_encode($row));
    }

    // ------------------------------------------------------------------ dealer profile: bad input

    public static function badProfileForms(): array
    {
        return [
            'name missing'              => [['full_name' => ''], 'full_name'],
            'name too long'             => [['full_name' => str_repeat('a', 256)], 'full_name'],
            'email missing'             => [['contact_email' => ''], 'contact_email'],
            'email not an email'        => [['contact_email' => 'not-an-email'], 'contact_email'],
            'email too long'            => [['contact_email' => str_repeat('a', 260) . '@x.lk'], 'contact_email'],
            'phone with letters'        => [['phone_number' => 'call-me'], 'phone_number'],
            'phone too short'           => [['phone_number' => '12345'], 'phone_number'],
            'phone too long'            => [['phone_number' => str_repeat('1', 21)], 'phone_number'],
            'address too long'          => [['address' => str_repeat('a', 501)], 'address'],
            'region too long'           => [['region' => str_repeat('a', 256)], 'region'],
            'country too long'          => [['country' => str_repeat('a', 256)], 'country'],
            'qualification too long'    => [['qualification' => str_repeat('a', 256)], 'qualification'],
            'name sent as a list'       => [['full_name' => ['a', 'b']], 'full_name'],
            'password not confirmed'    => [['new_password' => 'Brand#NewPass9'], 'new_password'],
            'password confirm differs'  => [['new_password' => 'Brand#NewPass9', 'new_password_confirmation' => 'Other#NewPass9'], 'new_password'],
            'password too short'        => [['new_password' => 'Ab1', 'new_password_confirmation' => 'Ab1'], 'new_password'],
            'password no upper case'    => [['new_password' => 'brand#newpass9', 'new_password_confirmation' => 'brand#newpass9'], 'new_password'],
            'password no lower case'    => [['new_password' => 'BRAND#NEWPASS9', 'new_password_confirmation' => 'BRAND#NEWPASS9'], 'new_password'],
            'password no number'        => [['new_password' => 'Brand#NewPass', 'new_password_confirmation' => 'Brand#NewPass'], 'new_password'],
            'password over 72 letters'  => [['new_password' => 'Aa1' . str_repeat('x', 70), 'new_password_confirmation' => 'Aa1' . str_repeat('x', 70)], 'new_password'],
        ];
    }

    #[DataProvider('badProfileForms')]
    public function test_bad_input_is_refused_and_nothing_is_saved(array $changes, string $field): void
    {
        $dealer = $this->dealer('Bad', ['full_name' => 'Autotest Unchanged']);
        $admin  = $this->dealerLogin($dealer);

        $this->from(route('dealer.profile.edit'))
            ->put(route('dealer.profile.update'), $this->profileForm($dealer, $changes))
            ->assertRedirect(route('dealer.profile.edit'))
            ->assertSessionHasErrors($field);

        $this->assertSame('Autotest Unchanged', $dealer->fresh()->full_name);
        $this->assertSame($dealer->contact_email, $dealer->fresh()->contact_email);
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $admin->fresh()->password));
        $this->assertSame(0, $this->auditCount('account.profile_updated', $admin->admin_id));
    }

    // ------------------------------------------------------------------ a dealer can only change itself

    public function test_extra_fields_in_the_form_cannot_change_the_role_the_status_or_the_dealer_link(): void
    {
        $dealer = $this->dealer('Mass');
        $other  = $this->dealer('MassOther');
        $admin  = $this->dealerLogin($dealer);

        $this->put(route('dealer.profile.update'), $this->profileForm($dealer, [
            'role'          => 'ADMIN',
            'status'        => 'INACTIVE',
            'dealer_id'     => $other->id,
            'username'      => 'autotest-hijack',
            'dealer_status' => 'distributor',
        ]))->assertSessionHasNoErrors();

        $admin->refresh();
        $this->assertSame('DEALER', $admin->role);
        $this->assertSame('ACTIVE', $admin->status);
        $this->assertSame($dealer->id, (int) $admin->dealer_id);
        $this->assertNotSame('autotest-hijack', $admin->username);
        $this->assertSame('retailer', $dealer->fresh()->dealer_status);
        $this->assertSame('active', $dealer->fresh()->status);
    }

    public function test_a_dealer_cannot_change_another_dealer_by_posting_its_id(): void
    {
        $dealer = $this->dealer('Mine2');
        $other  = $this->dealer('Victim', ['full_name' => 'Autotest Victim Untouched']);
        $this->dealerLogin($dealer);

        $this->put(route('dealer.profile.update'), $this->profileForm($dealer, [
            'full_name' => 'Autotest Attacker Name', 'id' => $other->id, 'dealer_id' => $other->id,
        ]))->assertSessionHasNoErrors();

        $this->assertSame('Autotest Victim Untouched', $other->fresh()->full_name);
        $this->assertSame('Autotest Attacker Name', $dealer->fresh()->full_name);
    }
}