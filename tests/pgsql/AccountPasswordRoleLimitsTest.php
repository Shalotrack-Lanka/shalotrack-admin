<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\PasswordResetRequest;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * Accounts and passwords, and who is allowed to do what:
 *   - every signed-in role can change ITS OWN password (current password asked again, policy enforced, 5 wrong tries lock)
 *   - a login that holds a temporary password is held on the "choose a new password" page until it changes it
 *   - only an ADMIN can add administrators and answer "forgot password" requests
 *   - only the developer account (ADMIN + ACTIVE + name listed in config/erp.php) sees the account directory
 *     and can set a temporary password on any login
 *   - "forgot password" never tells a visitor which usernames exist
 *   - a password (typed, generated or temporary) never reaches the audit log
 *
 * Runs in the isolated "autotest" schema; every row is rolled back by DatabaseTransactions.
 * The developer account is faked with config(), pointing at a throw-away autotest login, so the real
 * "admin001" name is never touched.
 */
class AccountPasswordRoleLimitsTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    private const OWN  = 'Autotest#12345';      // password every makeAdmin() login has
    private const NEW  = 'Brand#New98765x';

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        // These two parts were added to the app on 2026-10-14. If the autotest schema was built before that,
        // say so clearly instead of failing with a confusing SQL error.
        if (! Schema::hasTable('password_reset_requests')
            || ! Schema::hasColumn('Admins', 'must_change_password')
            || ! Schema::hasColumn('Admins', 'password_changed_at')) {
            $this->fail('The autotest schema is missing the password tables/columns. Run: '
                . 'php artisan db:show --env=testing   then   '
                . 'php artisan migrate --env=testing --path=database/migrations/2026_10_14_000001_add_password_columns_to_admins_table.php   and   '
                . 'php artisan migrate --env=testing --path=database/migrations/2026_10_14_000002_create_password_reset_requests_table.php');
        }
    }

    // ---------------------------------------------------------------- helpers

    /** Acts as this person in a fresh browser session (two people never share one session). */
    private function as(Admin $user): static
    {
        app('auth')->forgetGuards();
        $this->flushSession();

        return $this->actingAs($user);
    }

    private function tag(): string
    {
        return substr(md5((string) microtime(true) . ++$this->n), 0, 8);
    }

    /** A throw-away login that counts as "the developer account" for this test only. */
    private function developer(): Admin
    {
        $dev = $this->makeAdmin('ADMIN');
        config(['erp.developer_usernames' => [strtolower($dev->username)]]);

        return $dev;
    }

    private function pending(Admin $for): PasswordResetRequest
    {
        return PasswordResetRequest::create([
            'admin_id'     => $for->admin_id,
            'username'     => $for->username,
            'status'       => PasswordResetRequest::PENDING,
            'requested_at' => now(),
        ]);
    }

    private function audit(string $action, ?string $subjectLabel = null): ?AuditLog
    {
        $q = AuditLog::where('action', $action);
        if ($subjectLabel !== null) {
            $q->where('subject_label', $subjectLabel);
        }

        return $q->latest('id')->first();
    }

    /** True when this text appears anywhere in the audit log (who, what, details, browser). */
    private function auditMentions(string $needle): bool
    {
        $row = DB::selectOne(
            "select count(*) as c from audit_logs where (coalesce(changes::text,'') || ' ' || coalesce(meta::text,'') || ' ' || coalesce(subject_label,'') || ' ' || coalesce(subject_id,'') || ' ' || coalesce(actor_name,'') || ' ' || coalesce(user_agent,'')) like ?",
            ['%' . $needle . '%']
        );

        return (int) $row->c > 0;
    }

    private function changeForm(array $o = []): array
    {
        return array_merge([
            'current_password'      => self::OWN,
            'password'              => self::NEW,
            'password_confirmation' => self::NEW,
        ], $o);
    }

    private function newAdminForm(array $o = []): array
    {
        $t = $this->tag();

        return array_merge([
            'full_name'             => 'Autotest New Admin ' . $t,
            'username'              => 'autotest.new.' . $t,
            'email'                 => "autotest+newadmin-{$t}@example.test",
            'phone_number'          => '0771234567',
            'password'              => 'Newbie#Pass123',
            'password_confirmation' => 'Newbie#Pass123',
            'current_password'      => self::OWN,
        ], $o);
    }

    public static function allRoles(): array
    {
        return [
            'an admin'       => ['ADMIN'],
            'a dealer'       => ['DEALER'],
            'a distributor'  => ['DISTRIBUTOR'],
            'a finance login' => ['FINANCE'],
            'a technician'   => ['TECHNICIAN'],
            'a supplier'     => ['SUPPLIER'],
        ];
    }

    public static function notAdminRoles(): array
    {
        return [
            'a dealer'       => ['DEALER'],
            'a distributor'  => ['DISTRIBUTOR'],
            'a finance login' => ['FINANCE'],
            'a technician'   => ['TECHNICIAN'],
            'a supplier'     => ['SUPPLIER'],
        ];
    }

    // ------------------------------------------------ nobody signed in

    public function test_a_visitor_who_is_not_signed_in_is_sent_to_the_login_page(): void
    {
        $someone = $this->makeAdmin('DEALER');

        $this->get(route('account.password.edit'))->assertRedirect(route('login'));
        $this->put(route('account.password.update'), $this->changeForm())->assertRedirect(route('login'));
        $this->get(route('admin.profile'))->assertRedirect(route('login'));
        $this->get(route('admin.accounts.index'))->assertRedirect(route('login'));
        $this->post(route('admin.accounts.temporary-password', $someone->admin_id), ['current_password' => 'x'])->assertRedirect(route('login'));
        $this->post(route('admin.profile.admins.store'), $this->newAdminForm())->assertRedirect(route('login'));

        $this->assertTrue(Hash::check(self::OWN, $someone->fresh()->password), 'nothing may change for a visitor');
    }

    // ------------------------------------------------ changing your own password

    #[DataProvider('allRoles')]
    public function test_every_role_can_change_its_own_password(string $role): void
    {
        $me = $this->makeAdmin($role);

        $this->as($me)->get(route('account.password.edit'))->assertOk();

        $this->put(route('account.password.update'), $this->changeForm())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $fresh = $me->fresh();
        $this->assertTrue(Hash::check(self::NEW, $fresh->password), "{$role}: the new password must work");
        $this->assertFalse(Hash::check(self::OWN, $fresh->password), "{$role}: the old password must stop working");
        $this->assertNotNull($fresh->password_changed_at);
        $this->assertFalse((bool) $fresh->must_change_password);

        $log = $this->audit('account.password_changed', $me->username);
        $this->assertNotNull($log, 'a password change must be audited');
        $this->assertSame($me->admin_id, $log->actor_id);
        $this->assertFalse($this->auditMentions(self::NEW), 'the new password must never reach the audit log');
        $this->assertFalse($this->auditMentions(self::OWN), 'the old password must never reach the audit log');
    }

    public function test_the_wrong_current_password_changes_nothing_and_is_audited(): void
    {
        $me = $this->makeAdmin('DEALER');
        $before = $me->password;

        $this->as($me)->put(route('account.password.update'), $this->changeForm(['current_password' => 'Not-My-Password1']))
            ->assertSessionHasErrors('current_password', null, 'changePassword');

        $this->assertSame($before, $me->fresh()->password);
        $this->assertSame(1, AuditLog::where('action', 'auth.reauth_failed')->where('actor_id', $me->admin_id)->count());
    }

    public function test_a_weak_or_unconfirmed_or_unchanged_new_password_is_refused(): void
    {
        $me = $this->makeAdmin('FINANCE');
        $before = $me->password;
        $this->as($me);

        $weak = [
            'shorter than 10'   => 'Ab1defg',
            'no capital letter' => 'abcdefghij1',
            'no small letter'   => 'ABCDEFGHIJ1',
            'no number'         => 'Abcdefghijk',
        ];
        foreach ($weak as $why => $pw) {
            $this->put(route('account.password.update'), $this->changeForm(['password' => $pw, 'password_confirmation' => $pw]))
                ->assertSessionHasErrors('password', null, 'changePassword');
            $this->assertSame($before, $me->fresh()->password, "refused: {$why}");
        }

        $this->put(route('account.password.update'), $this->changeForm(['password_confirmation' => 'Different#Typed99']))
            ->assertSessionHasErrors('password', null, 'changePassword');

        // The same password again is not a change.
        $this->put(route('account.password.update'), $this->changeForm(['password' => self::OWN, 'password_confirmation' => self::OWN]))
            ->assertSessionHasErrors('password', null, 'changePassword');

        $this->assertSame($before, $me->fresh()->password);
    }

    public function test_five_wrong_current_passwords_lock_the_change_even_for_the_right_one(): void
    {
        $me = $this->makeAdmin('TECHNICIAN');
        $before = $me->password;
        $this->as($me);

        for ($i = 0; $i < 5; $i++) {
            $this->put(route('account.password.update'), $this->changeForm(['current_password' => "Wrong-Guess-{$i}x"]))
                ->assertSessionHasErrors('current_password', null, 'changePassword');
        }

        $res = $this->put(route('account.password.update'), $this->changeForm());
        $res->assertSessionHasErrors('current_password', null, 'changePassword');
        $message = $res->getSession()->get('errors')->getBag('changePassword')->first('current_password');
        $this->assertStringContainsString('Too many wrong passwords', (string) $message);

        $this->assertSame($before, $me->fresh()->password, 'locked: the right password must not work either');
        $this->assertSame(5, AuditLog::where('action', 'auth.reauth_failed')->where('actor_id', $me->admin_id)->count());
    }

    // ------------------------------------------------ temporary password: forced change

    #[DataProvider('allRoles')]
    public function test_a_login_with_a_temporary_password_is_held_on_the_change_page(string $role): void
    {
        $me = $this->makeAdmin($role, ['must_change_password' => true]);
        $home = route($me->homeRouteName());

        // Normal pages bounce to the change page ...
        $this->as($me)->get($home)->assertRedirect(route('account.password.edit'));
        // ... machine calls get 423 "locked" ...
        $this->getJson($home)->assertStatus(423);
        // ... and nothing else works, e.g. saving a profile (only some roles have a profile page of their own).
        $profileRoutes = ['ADMIN' => 'admin.profile.update', 'FINANCE' => 'finance.profile.update', 'TECHNICIAN' => 'technician.profile.update'];
        if (isset($profileRoutes[$role])) {
            $this->put(route($profileRoutes[$role]), ['full_name' => 'Hacker', 'email' => 'h@example.test'])
                ->assertRedirect(route('account.password.edit'));
            $this->assertNotSame('Hacker', $me->fresh()->full_name);
        }

        // The change page itself and Logout stay open.
        $this->get(route('account.password.edit'))->assertOk();
        $this->post(route('logout'))->assertRedirect('/');
    }

    #[DataProvider('allRoles')]
    public function test_choosing_a_new_password_releases_the_login(string $role): void
    {
        $me = $this->makeAdmin($role, ['must_change_password' => true]);

        // Forced screen uses the normal (default) error bag.
        $this->as($me)->put(route('account.password.update'), $this->changeForm(['password' => 'weak', 'password_confirmation' => 'weak']))
            ->assertSessionHasErrors('password');
        $this->assertTrue((bool) $me->fresh()->must_change_password, 'a refused change must not release the login');

        $this->put(route('account.password.update'), $this->changeForm())
            ->assertRedirect(route($me->homeRouteName()))
            ->assertSessionHasNoErrors();

        $fresh = $me->fresh();
        $this->assertFalse((bool) $fresh->must_change_password);
        $this->assertNotNull($fresh->password_changed_at);
        $this->assertTrue(Hash::check(self::NEW, $fresh->password));

        $log = $this->audit('account.password_changed', $me->username);
        $this->assertNotNull($log);
        $this->assertTrue((bool) $log->meta['forced_change']);
    }

    // ------------------------------------------------ forgot password (public page)

    public function test_forgot_password_answers_the_same_for_real_unknown_and_inactive_usernames(): void
    {
        $real     = $this->makeAdmin('DEALER');
        $inactive = $this->makeAdmin('DEALER', ['status' => 'INACTIVE']);

        $a = $this->post(route('password.request.store'), ['username' => $real->username]);
        $b = $this->post(route('password.request.store'), ['username' => 'nobody-' . $this->tag()]);
        $c = $this->post(route('password.request.store'), ['username' => $inactive->username]);

        $a->assertSessionHasNoErrors();
        $this->assertNotNull($a->getSession()->get('status'));
        $this->assertSame($a->getSession()->get('status'), $b->getSession()->get('status'), 'unknown names must get the same answer');
        $this->assertSame($a->getSession()->get('status'), $c->getSession()->get('status'), 'inactive names must get the same answer');

        $this->assertSame(1, PasswordResetRequest::where('admin_id', $real->admin_id)->count());
        $this->assertSame(0, PasswordResetRequest::where('admin_id', $inactive->admin_id)->count(), 'an inactive login gets no request');
        $this->assertNotNull($this->audit('auth.password_reset_requested', $real->username));
    }

    public function test_only_one_request_can_wait_per_login(): void
    {
        $me = $this->makeAdmin('FINANCE');

        $this->post(route('password.request.store'), ['username' => $me->username]);
        $this->post(route('password.request.store'), ['username' => $me->username]);
        $this->post(route('password.request.store'), ['username' => $me->username]);

        $this->assertSame(1, PasswordResetRequest::where('admin_id', $me->admin_id)->where('status', 'PENDING')->count());
    }

    public function test_forgot_password_is_limited_per_network_address(): void
    {
        $target = $this->makeAdmin('DEALER');

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('password.request.store'), ['username' => 'nobody-' . $this->tag()])->assertSessionHasNoErrors();
        }

        $this->post(route('password.request.store'), ['username' => $target->username])->assertSessionHasErrors('username');
        $this->assertSame(0, PasswordResetRequest::where('admin_id', $target->admin_id)->count(), 'a blocked address creates nothing');
    }

    // ------------------------------------------------ administration: ADMIN only

    #[DataProvider('notAdminRoles')]
    public function test_only_an_admin_can_reach_the_administration_actions(string $role): void
    {
        $login  = $this->makeAdmin($role);
        $victim = $this->makeAdmin('DEALER');
        $form   = $this->newAdminForm();
        $count  = Admin::count();

        $this->as($login);
        $this->post(route('admin.profile.admins.store'), $form)->assertForbidden();
        $this->post(route('admin.profile.password-requests.resolve', 1), ['current_password' => self::OWN])->assertForbidden();
        $this->post(route('admin.profile.password-requests.dismiss', 1))->assertForbidden();
        $this->get(route('admin.accounts.index'))->assertForbidden();
        $this->post(route('admin.accounts.temporary-password', $victim->admin_id), ['current_password' => self::OWN])->assertForbidden();
        $this->get(route('admin.profile'))->assertForbidden();

        $this->assertSame($count, Admin::count(), "a {$role} login must not be able to add an account");
        $this->assertTrue(Hash::check(self::OWN, $victim->fresh()->password), 'and must not be able to change anyone\'s password');
    }

    public function test_a_plain_admin_is_not_the_developer(): void
    {
        $dev    = $this->developer();
        $boss   = $this->makeAdmin('ADMIN');
        $victim = $this->makeAdmin('DEALER');

        $this->as($boss)->get(route('admin.accounts.index'))->assertForbidden();
        $this->post(route('admin.accounts.temporary-password', $victim->admin_id), ['current_password' => self::OWN])->assertForbidden();
        $this->assertTrue(Hash::check(self::OWN, $victim->fresh()->password));
        $this->assertFalse((bool) $victim->fresh()->must_change_password);

        $this->as($dev)->get(route('admin.accounts.index'))->assertOk();
    }

    public function test_who_counts_as_the_developer(): void
    {
        $dev = $this->developer();
        $this->assertTrue($dev->isDeveloper());

        // Name match ignores capital letters.
        $loud = $this->makeAdmin('ADMIN', ['username' => 'AUTOTEST-DEV-' . strtoupper($this->tag())]);
        config(['erp.developer_usernames' => [strtolower($loud->username)]]);
        $this->assertTrue($loud->isDeveloper());

        // The right name is not enough: it must be an ADMIN login and ACTIVE.
        $dealer = $this->makeAdmin('DEALER');
        $off    = $this->makeAdmin('ADMIN', ['status' => 'INACTIVE']);
        config(['erp.developer_usernames' => [strtolower($dealer->username), strtolower($off->username)]]);
        $this->assertFalse($dealer->isDeveloper(), 'a dealer with the developer name is not the developer');
        $this->assertFalse($off->isDeveloper(), 'an inactive admin with the developer name is not the developer');
    }

    public function test_the_profile_page_shows_the_account_directory_link_only_to_the_developer(): void
    {
        $dev  = $this->developer();
        $boss = $this->makeAdmin('ADMIN');

        $this->as($dev)->get(route('admin.profile'))->assertOk()->assertSee(route('admin.accounts.index'), false);
        $this->as($boss)->get(route('admin.profile'))->assertOk()->assertDontSee(route('admin.accounts.index'), false);
    }

    // ------------------------------------------------ add administrator

    public function test_an_admin_adds_an_administrator_who_must_choose_their_own_password(): void
    {
        $boss = $this->makeAdmin('ADMIN');
        // The form is forged: it tries to choose the role, the status and to skip the forced change.
        $form = $this->newAdminForm(['role' => 'DEALER', 'status' => 'INACTIVE', 'must_change_password' => 0]);

        $this->as($boss)->post(route('admin.profile.admins.store'), $form)
            ->assertRedirect(route('admin.profile'))
            ->assertSessionHasNoErrors();

        $new = Admin::where('username', $form['username'])->first();
        $this->assertNotNull($new);
        $this->assertSame('ADMIN', $new->role, 'this form can only create administrators');
        $this->assertSame('ACTIVE', $new->status);
        $this->assertTrue((bool) $new->must_change_password);
        $this->assertTrue(Hash::check($form['password'], $new->password));
        $this->assertNotSame($form['password'], $new->password);

        $log = $this->audit('admin.created', $new->username);
        $this->assertNotNull($log);
        $this->assertSame($boss->admin_id, $log->actor_id);
        $this->assertFalse($this->auditMentions($form['password']), 'the typed password must never reach the audit log');

        // The new administrator is held on the change page at the first sign-in.
        $this->as($new)->get(route('admin.dashboard'))->assertRedirect(route('account.password.edit'));
    }

    public function test_adding_an_administrator_needs_the_signed_in_admins_own_password(): void
    {
        $boss  = $this->makeAdmin('ADMIN');
        $count = Admin::count();

        $this->as($boss)->post(route('admin.profile.admins.store'), $this->newAdminForm(['current_password' => 'Not-My-Password1']))
            ->assertSessionHasErrors('current_password', null, 'addAdmin');
        $this->post(route('admin.profile.admins.store'), $this->newAdminForm(['current_password' => '']))
            ->assertSessionHasErrors('current_password', null, 'addAdmin');

        $this->assertSame($count, Admin::count());
        $this->assertSame(1, AuditLog::where('action', 'auth.reauth_failed')->where('actor_id', $boss->admin_id)->count());
    }

    public function test_adding_an_administrator_checks_every_field(): void
    {
        $boss  = $this->makeAdmin('ADMIN');
        $other = $this->makeAdmin('DEALER');
        $count = Admin::count();
        $this->as($boss);

        $bad = [
            'username with a space'      => [['username' => 'bad name'], 'username'],
            'username too short'         => [['username' => 'abc'], 'username'],
            'username already used'      => [['username' => strtoupper($other->username)], 'username'],   // capital letters do not make it new
            'email already used'         => [['email' => strtoupper($other->email)], 'email'],
            'email is not an email'      => [['email' => 'not-an-email'], 'email'],
            'weak password'              => [['password' => 'weak', 'password_confirmation' => 'weak'], 'password'],
            'password not confirmed'     => [['password_confirmation' => 'Other#Typed99x'], 'password'],
            'phone with letters'         => [['phone_number' => 'call-me'], 'phone_number'],
            'name missing'               => [['full_name' => ''], 'full_name'],
        ];

        foreach ($bad as $why => [$override, $field]) {
            $this->post(route('admin.profile.admins.store'), $this->newAdminForm($override))
                ->assertSessionHasErrors($field, null, 'addAdmin');
            $this->assertSame($count, Admin::count(), "refused: {$why}");
        }
    }

    // ------------------------------------------------ forgot-password queue (ADMIN answers)

    public function test_an_admin_answers_a_request_with_a_generated_temporary_password(): void
    {
        $boss   = $this->makeAdmin('ADMIN');
        $dealer = $this->makeAdmin('DEALER');
        $req    = $this->pending($dealer);
        $oldHash = $dealer->password;
        $oldToken = $dealer->remember_token;

        $res = $this->as($boss)->post(route('admin.profile.password-requests.resolve', $req->id), ['current_password' => self::OWN]);

        $res->assertRedirect(route('admin.profile'))->assertSessionHasNoErrors();
        $shown = $res->getSession()->get('temp_password');
        $this->assertSame($dealer->username, $shown['username']);
        $this->assertTrue($shown['generated']);

        $tmp = $shown['password'];
        $this->assertTrue(Validator::make(['p' => $tmp], ['p' => PasswordPolicy::rule()])->passes(), 'a generated password must satisfy the password rule');

        $fresh = $dealer->fresh();
        $this->assertNotSame($oldHash, $fresh->password);
        $this->assertTrue(Hash::check($tmp, $fresh->password));
        $this->assertTrue((bool) $fresh->must_change_password);
        $this->assertNull($fresh->password_changed_at);
        $this->assertNotSame($oldToken, $fresh->remember_token, 'the dealer\'s other sessions must end');

        $req = $req->fresh();
        $this->assertSame(PasswordResetRequest::DONE, $req->status);
        $this->assertSame($boss->admin_id, $req->resolved_by);
        $this->assertNotNull($req->resolved_at);

        $log = $this->audit('account.password_reset', $dealer->username);
        $this->assertNotNull($log);
        $this->assertSame($boss->admin_id, $log->actor_id);
        $this->assertTrue((bool) $log->meta['password_generated']);
        $this->assertSame('DEALER', $log->meta['target_role']);
        $this->assertFalse($this->auditMentions($tmp), 'the temporary password must never reach the audit log');

        // The dealer is held on the change page with it.
        $this->as($fresh)->get(route('dealer.dashboard'))->assertRedirect(route('account.password.edit'));
    }

    public function test_an_admin_can_type_the_temporary_password_but_a_weak_one_is_refused(): void
    {
        $boss   = $this->makeAdmin('ADMIN');
        $dealer = $this->makeAdmin('DEALER');
        $req    = $this->pending($dealer);
        $before = $dealer->password;
        $this->as($boss);

        $this->post(route('admin.profile.password-requests.resolve', $req->id), ['current_password' => self::OWN, 'temporary_password' => 'weak'])
            ->assertSessionHasErrors('temporary_password', null, 'resetRequest');
        $this->assertSame($before, $dealer->fresh()->password);
        $this->assertSame(PasswordResetRequest::PENDING, $req->fresh()->status);

        $typed = 'Typed#Temp' . $this->tag();
        $res = $this->post(route('admin.profile.password-requests.resolve', $req->id), ['current_password' => self::OWN, 'temporary_password' => $typed]);
        $res->assertRedirect(route('admin.profile'));
        $this->assertFalse($res->getSession()->get('temp_password')['generated']);
        $this->assertTrue(Hash::check($typed, $dealer->fresh()->password));
        $this->assertFalse($this->auditMentions($typed));
    }

    public function test_answering_a_request_needs_the_admins_own_password_and_five_misses_lock_it(): void
    {
        $boss   = $this->makeAdmin('ADMIN');
        $dealer = $this->makeAdmin('DEALER');
        $req    = $this->pending($dealer);
        $before = $dealer->password;
        $url    = route('admin.profile.password-requests.resolve', $req->id);
        $this->as($boss);

        for ($i = 0; $i < 5; $i++) {
            $this->post($url, ['current_password' => "Wrong-Guess-{$i}x"])->assertSessionHasErrors('current_password', null, 'resetRequest');
        }
        // Even the right one is refused now.
        $this->post($url, ['current_password' => self::OWN])->assertSessionHasErrors('current_password', null, 'resetRequest');

        $this->assertSame($before, $dealer->fresh()->password);
        $this->assertSame(PasswordResetRequest::PENDING, $req->fresh()->status);
    }

    public function test_an_admin_cannot_answer_a_request_for_their_own_account_or_for_an_inactive_one(): void
    {
        $boss     = $this->makeAdmin('ADMIN');
        $mine     = $this->pending($boss);
        $gone     = $this->makeAdmin('DEALER', ['status' => 'INACTIVE']);
        $goneReq  = $this->pending($gone);
        $this->as($boss);

        $before = $boss->password;
        $this->post(route('admin.profile.password-requests.resolve', $mine->id), ['current_password' => self::OWN])
            ->assertSessionHasErrors('request', null, 'resetRequest');
        $this->assertSame($before, $boss->fresh()->password);
        $this->assertSame(PasswordResetRequest::PENDING, $mine->fresh()->status);

        $beforeGone = $gone->password;
        $this->post(route('admin.profile.password-requests.resolve', $goneReq->id), ['current_password' => self::OWN])
            ->assertSessionHasErrors('request', null, 'resetRequest');
        $this->assertSame($beforeGone, $gone->fresh()->password, 'no password is set on an inactive login');
        $this->assertSame(PasswordResetRequest::DISMISSED, $goneReq->fresh()->status, 'the request is closed instead');
    }

    public function test_dismissing_closes_the_request_without_touching_the_password(): void
    {
        $boss   = $this->makeAdmin('ADMIN');
        $dealer = $this->makeAdmin('DEALER');
        $req    = $this->pending($dealer);
        $before = $dealer->password;

        $this->as($boss)->post(route('admin.profile.password-requests.dismiss', $req->id))->assertRedirect(route('admin.profile'));

        $this->assertSame(PasswordResetRequest::DISMISSED, $req->fresh()->status);
        $this->assertSame($boss->admin_id, $req->fresh()->resolved_by);
        $this->assertSame($before, $dealer->fresh()->password);
        $this->assertFalse((bool) $dealer->fresh()->must_change_password);
        $this->assertNotNull($this->audit('account.password_reset_dismissed', $dealer->username));

        // A closed request cannot be answered or dismissed again.
        $this->post(route('admin.profile.password-requests.resolve', $req->id), ['current_password' => self::OWN])->assertNotFound();
        $this->post(route('admin.profile.password-requests.dismiss', $req->id))->assertNotFound();
    }

    // ------------------------------------------------ developer: directory + temporary password

    #[DataProvider('allRoles')]
    public function test_the_developer_can_set_a_temporary_password_on_a_login_of_every_role(string $role): void
    {
        $dev    = $this->developer();
        $target = $this->makeAdmin($role);
        $req    = $this->pending($target);
        $oldToken = $target->remember_token;
        $typed  = 'Typed#Temp' . $this->tag();

        $res = $this->as($dev)->post(route('admin.accounts.temporary-password', $target->admin_id), [
            'current_password' => self::OWN, 'temporary_password' => $typed,
        ]);

        $res->assertSessionHasNoErrors();
        $shown = $res->getSession()->get('temp_password');
        $this->assertSame($target->username, $shown['username']);
        $this->assertSame($typed, $shown['password']);

        $fresh = $target->fresh();
        $this->assertTrue(Hash::check($typed, $fresh->password));
        $this->assertTrue((bool) $fresh->must_change_password);
        $this->assertNull($fresh->password_changed_at);
        $this->assertNotSame($oldToken, $fresh->remember_token);
        $this->assertSame(PasswordResetRequest::DONE, $req->fresh()->status, 'a waiting request for that login is closed');

        $log = $this->audit('account.password_reset', $target->username);
        $this->assertNotNull($log);
        $this->assertSame($dev->admin_id, $log->actor_id);
        $this->assertSame($role, $log->meta['target_role']);
        $this->assertSame($dev->username, $log->meta['set_by']);
        $this->assertFalse((bool) $log->meta['password_generated']);
        $this->assertFalse($this->auditMentions($typed), 'the temporary password must never reach the audit log');

        // That person is now held on the change page, whatever their role.
        $this->as($fresh)->get(route($fresh->homeRouteName()))->assertRedirect(route('account.password.edit'));
    }

    public function test_the_developer_can_have_a_password_generated(): void
    {
        $dev    = $this->developer();
        $target = $this->makeAdmin('SUPPLIER');

        $res = $this->as($dev)->post(route('admin.accounts.temporary-password', $target->admin_id), ['current_password' => self::OWN]);

        $shown = $res->getSession()->get('temp_password');
        $this->assertTrue($shown['generated']);
        $this->assertGreaterThanOrEqual(PasswordPolicy::MIN_LENGTH, strlen($shown['password']));
        $this->assertTrue(Validator::make(['p' => $shown['password']], ['p' => PasswordPolicy::rule()])->passes());
        $this->assertTrue(Hash::check($shown['password'], $target->fresh()->password));
        $this->assertFalse($this->auditMentions($shown['password']));
    }

    public function test_the_developer_reset_refuses_bad_requests_and_changes_nothing(): void
    {
        $dev     = $this->developer();
        $target  = $this->makeAdmin('DEALER');
        $off     = $this->makeAdmin('DEALER', ['status' => 'INACTIVE']);
        $before  = $target->password;
        $beforeOff = $off->password;
        $this->as($dev);

        $url = route('admin.accounts.temporary-password', $target->admin_id);

        $this->post($url, ['current_password' => 'Not-My-Password1'])->assertSessionHasErrors('current_password', null, 'resetAccount');
        $this->post($url, ['current_password' => self::OWN, 'temporary_password' => 'weak'])->assertSessionHasErrors('temporary_password', null, 'resetAccount');
        $this->post(route('admin.accounts.temporary-password', $dev->admin_id), ['current_password' => self::OWN])->assertSessionHasErrors('account', null, 'resetAccount');
        $this->post(route('admin.accounts.temporary-password', $off->admin_id), ['current_password' => self::OWN])->assertSessionHasErrors('account', null, 'resetAccount');
        $this->post(route('admin.accounts.temporary-password', (string) \Illuminate\Support\Str::uuid()), ['current_password' => self::OWN])->assertNotFound();

        $this->assertSame($before, $target->fresh()->password);
        $this->assertSame($beforeOff, $off->fresh()->password);
        $this->assertFalse((bool) $target->fresh()->must_change_password);
        $this->assertNull($this->audit('account.password_reset'));
    }

    public function test_five_wrong_passwords_lock_the_developer_reset(): void
    {
        $dev    = $this->developer();
        $target = $this->makeAdmin('DEALER');
        $before = $target->password;
        $url    = route('admin.accounts.temporary-password', $target->admin_id);
        $this->as($dev);

        for ($i = 0; $i < 5; $i++) {
            $this->post($url, ['current_password' => "Wrong-Guess-{$i}x"])->assertSessionHasErrors('current_password', null, 'resetAccount');
        }
        $this->post($url, ['current_password' => self::OWN])->assertSessionHasErrors('current_password', null, 'resetAccount');

        $this->assertSame($before, $target->fresh()->password);
    }

    public function test_the_developer_directory_lists_every_role_and_can_filter(): void
    {
        $dev = $this->developer();
        $t   = $this->tag();
        $dealer  = $this->makeAdmin('DEALER', ['username' => "zz-dealer-{$t}"]);
        $finance = $this->makeAdmin('FINANCE', ['username' => "zz-finance-{$t}"]);
        $off     = $this->makeAdmin('SUPPLIER', ['username' => "zz-supplier-{$t}", 'status' => 'INACTIVE']);

        $this->as($dev);
        $this->get(route('admin.accounts.index'))->assertOk()
            ->assertSee($dealer->username)->assertSee($finance->username)->assertSee($off->username);
        $this->get(route('admin.accounts.index', ['role' => 'FINANCE']))->assertOk()->assertSee($finance->username)->assertDontSee($dealer->username);
        $this->get(route('admin.accounts.index', ['status' => 'INACTIVE']))->assertOk()->assertSee($off->username)->assertDontSee($finance->username);

        // Odd input must not break the page or widen the list.
        $this->get(route('admin.accounts.index', ['q' => ['x']]))->assertOk();
        $this->get(route('admin.accounts.index', ['role' => 'SUPERUSER', 'status' => 'DELETED']))->assertOk();
    }

    /**
     * KNOWN BUG on Postgres (found by this suite): the search box 500s because the query says
     * LIKE ? ESCAPE '\\' and the Postgres PDO driver reads the backslash as an escape, swallowing the "?".
     * The SQLite tests cannot see it. Goes green when the controller is fixed (see the pull-request notes).
     */
    public function test_the_directory_search_box_works_on_postgres(): void
    {
        $dev = $this->developer();
        $t   = $this->tag();
        $dealer  = $this->makeAdmin('DEALER', ['username' => "zz-dealer-{$t}", 'phone_number' => '0779876543']);
        $finance = $this->makeAdmin('FINANCE', ['username' => "zz-finance-{$t}"]);

        $this->as($dev);
        $this->get(route('admin.accounts.index', ['q' => "zz-dealer-{$t}"]))->assertOk()->assertSee($dealer->username)->assertDontSee($finance->username);
        $this->get(route('admin.accounts.index', ['q' => '0779876543']))->assertOk()->assertSee($dealer->username)->assertDontSee($finance->username);
        $this->get(route('admin.accounts.index', ['q' => '%']))->assertOk()->assertDontSee($finance->username);
        $this->get(route('admin.accounts.index', ['q' => "' OR 1=1 --"]))->assertOk()->assertDontSee($finance->username);
    }

    /** KNOWN BUG: a malformed id (not a uuid) gives a 500 on Postgres instead of a 404. Goes green when fixed. */
    public function test_a_malformed_account_id_is_a_404_not_a_server_error(): void
    {
        $dev = $this->developer();

        $this->as($dev)->post(route('admin.accounts.temporary-password', 'no-such-id'), ['current_password' => self::OWN])->assertNotFound();
    }

    // ------------------------------------------------ audit log scoping

    public function test_an_admin_sees_only_their_own_audit_entries_and_the_developer_sees_all(): void
    {
        $dev  = $this->developer();
        $boss = $this->makeAdmin('ADMIN');
        $t    = $this->tag();

        $row = fn (Admin $actor, string $label, ?string $subjectId = null) => AuditLog::create([
            'occurred_at' => now(), 'actor_id' => $actor->admin_id, 'actor_name' => $actor->username, 'actor_role' => 'ADMIN',
            'category' => 'security', 'action' => 'auth.login', 'subject_type' => 'admin',
            'subject_id' => $subjectId ?? $actor->admin_id, 'subject_label' => $label,
        ]);
        $row($boss, "mk-boss-{$t}");                                    // done by the boss
        $row($dev, "mk-about-boss-{$t}", $boss->admin_id);              // done by the developer TO the boss
        $row($dev, "mk-dev-only-{$t}", 'some-other-person');            // nothing to do with the boss

        $csvOf = fn (Admin $who) => $this->as($who)->get(route('admin.audit-log', ['export' => 'csv']))->streamedContent();

        $boss_csv = $csvOf($boss);
        $this->assertStringContainsString("mk-boss-{$t}", $boss_csv);
        $this->assertStringContainsString("mk-about-boss-{$t}", $boss_csv, 'entries about my own account are mine to see');
        $this->assertStringNotContainsString("mk-dev-only-{$t}", $boss_csv, 'someone else\'s activity must stay hidden');

        $dev_csv = $csvOf($dev);
        foreach (["mk-boss-{$t}", "mk-about-boss-{$t}", "mk-dev-only-{$t}"] as $marker) {
            $this->assertStringContainsString($marker, $dev_csv, 'the developer sees everything');
        }
    }

    /** KNOWN BUG on Postgres: the audit log "q" and "actor" filters 500 (same cause as the directory search). */
    public function test_the_audit_log_filters_can_only_narrow_and_work_on_postgres(): void
    {
        $boss = $this->makeAdmin('ADMIN');
        $dev  = $this->developer();
        $t    = $this->tag();
        $mk = fn (Admin $actor, string $label, string $subjectId) => AuditLog::create([
            'occurred_at' => now(), 'actor_id' => $actor->admin_id, 'actor_name' => $actor->username, 'actor_role' => 'ADMIN',
            'category' => 'security', 'action' => 'auth.login', 'subject_type' => 'admin', 'subject_id' => $subjectId, 'subject_label' => $label,
        ]);
        $mk($boss, "mk-boss-{$t}", $boss->admin_id);
        $mk($dev, "mk-dev-only-{$t}", 'some-other-person');

        $csv = fn (Admin $who, array $q) => $this->as($who)->get(route('admin.audit-log', ['export' => 'csv'] + $q))->streamedContent();

        $this->assertStringContainsString("mk-boss-{$t}", $csv($boss, ['q' => "mk-boss-{$t}"]));
        // A filter can only narrow: asking for someone else's entry gives the boss nothing.
        $this->assertStringNotContainsString("mk-dev-only-{$t}", $csv($boss, ['q' => "mk-dev-only-{$t}"]));
        $this->assertStringNotContainsString("mk-dev-only-{$t}", $csv($boss, ['actor' => $dev->username]));
        $this->assertStringContainsString("mk-dev-only-{$t}", $csv($dev, ['q' => "mk-dev-only-{$t}"]));
    }

    // ------------------------------------------------ own profile (name, email, phone)

    public function test_each_role_edits_its_own_profile_only_through_its_own_route(): void
    {
        $finance = $this->makeAdmin('FINANCE');
        $tech    = $this->makeAdmin('TECHNICIAN');
        $t       = $this->tag();

        $this->as($finance)->put(route('finance.profile.update'), ['full_name' => "Fin New {$t}", 'email' => "fin-{$t}@example.test", 'phone_number' => '0771111111'])
            ->assertSessionHasNoErrors();
        $this->assertSame("Fin New {$t}", $finance->fresh()->full_name);

        $this->as($tech)->put(route('technician.profile.update'), ['full_name' => "Tech New {$t}", 'email' => "tech-{$t}@example.test"])
            ->assertSessionHasNoErrors();
        $this->assertSame("Tech New {$t}", $tech->fresh()->full_name);

        // Crossing over is refused.
        $this->as($finance)->put(route('admin.profile.update'), ['full_name' => 'x', 'email' => "x-{$t}@example.test"])->assertForbidden();
        $this->as($finance)->get(route('technician.profile'))->assertForbidden();
        $this->as($this->makeAdmin('DEALER'))->put(route('finance.profile.update'), ['full_name' => 'x', 'email' => "y-{$t}@example.test"])->assertForbidden();
        $this->as($this->makeAdmin('ADMIN'))->put(route('technician.profile.update'), ['full_name' => 'x', 'email' => "z-{$t}@example.test"])->assertForbidden();
    }

    public function test_a_forged_profile_form_cannot_change_username_role_or_status(): void
    {
        $me = $this->makeAdmin('FINANCE');
        $t  = $this->tag();

        $this->as($me)->put(route('finance.profile.update'), [
            'full_name' => "Fin {$t}", 'email' => "fin-{$t}@example.test",
            'username' => 'hacker', 'role' => 'ADMIN', 'status' => 'INACTIVE', 'must_change_password' => 1, 'password' => 'Hacked#Pass123',
        ]);

        $fresh = $me->fresh();
        $this->assertSame($me->username, $fresh->username);
        $this->assertSame('FINANCE', $fresh->role);
        $this->assertSame('ACTIVE', $fresh->status);
        $this->assertFalse((bool) $fresh->must_change_password);
        $this->assertTrue(Hash::check(self::OWN, $fresh->password), 'the profile form must not be a back door to the password');
    }

    public function test_the_profile_refuses_bad_input_and_an_email_that_belongs_to_someone_else(): void
    {
        $me    = $this->makeAdmin('FINANCE');
        $other = $this->makeAdmin('DEALER');
        $this->as($me);

        $this->put(route('finance.profile.update'), ['full_name' => '', 'email' => 'x'])->assertSessionHasErrors(['full_name', 'email'], null, 'profile');
        $this->put(route('finance.profile.update'), ['full_name' => 'Fin', 'email' => strtoupper($other->email)])->assertSessionHasErrors('email', null, 'profile');
        $this->put(route('finance.profile.update'), ['full_name' => 'Fin', 'email' => $me->email, 'phone_number' => 'call-me'])->assertSessionHasErrors('phone_number', null, 'profile');
    }
}