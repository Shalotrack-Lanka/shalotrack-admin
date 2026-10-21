<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\PasswordResetRequest;
use App\Support\PasswordPolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Profile edit, change password, add an administrator, the no-email "forgot password" flow, and the
 * "choose your own password at first sign-in" hold.
 */
class AdminAccountTest extends TestCase
{
    private const STRONG = 'Str0ng-Passw0rd';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake(); // the admin layout talks to the API; keep every test offline
        Cache::flush();

        Schema::create('Admins', function (Blueprint $t) {
            $t->string('admin_id')->primary();
            $t->string('username')->nullable();
            $t->string('password')->nullable();
            $t->string('full_name')->nullable();
            $t->string('email')->nullable();
            $t->string('phone_number')->nullable();
            $t->string('role');
            $t->string('status')->default('ACTIVE');
            $t->unsignedBigInteger('dealer_id')->nullable();
            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->boolean('must_change_password')->default(false);
            $t->timestamp('password_changed_at')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('password_reset_requests', function (Blueprint $t) {
            $t->id();
            $t->string('admin_id', 64)->index();
            $t->string('username', 100);
            $t->string('status', 12)->default('PENDING');
            $t->string('requested_ip', 45)->nullable();
            $t->timestamp('requested_at')->nullable();
            $t->string('resolved_by', 64)->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->timestamp('occurred_at');
            $t->string('actor_id', 64)->nullable();
            $t->string('actor_name', 150)->nullable();
            $t->string('actor_role', 20)->nullable();
            $t->string('category', 20);
            $t->string('action', 60);
            $t->string('subject_type', 40)->nullable();
            $t->string('subject_id', 100)->nullable();
            $t->string('subject_label', 150)->nullable();
            $t->json('changes')->nullable();
            $t->json('meta')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 200)->nullable();
        });
    }

    private function make(string $role, string $username, array $extra = []): Admin
    {
        return Admin::forceCreate(array_merge([
            'admin_id'  => 'id-' . $username,
            'username'  => $username,
            'full_name' => ucfirst($username),
            'email'     => $username . '@example.com',
            'password'  => Hash::make('Old-Passw0rd-x'),
            'role'      => $role,
            'status'    => 'ACTIVE',
        ], $extra));
    }

    /** Acts as this person in a fresh browser session (two people never share one session). */
    private function as(Admin $user): static
    {
        $this->flushSession();

        return $this->actingAs($user);
    }

    private function audit(string $action): ?AuditLog
    {
        return AuditLog::where('action', $action)->latest('id')->first();
    }

    // ---------- forgot password (no email) ----------

    public function test_forgot_password_page_asks_for_a_username_and_says_nothing_is_emailed(): void
    {
        $this->get('/forgot-password')->assertOk()
            ->assertSee('name="username"', false)
            ->assertSee('Nothing is sent by email');
        $this->get('/login')->assertOk()->assertSee(route('password.request'), false);
    }

    public function test_a_request_for_a_real_account_is_recorded_once_and_audited(): void
    {
        $dealer = $this->make('DEALER', 'dealer1');

        $this->post('/forgot-password', ['username' => 'dealer1'])->assertSessionHas('status');
        $this->post('/forgot-password', ['username' => 'dealer1'])->assertSessionHas('status');

        $this->assertSame(1, PasswordResetRequest::where('admin_id', $dealer->admin_id)->where('status', 'PENDING')->count());
        $this->assertSame('auth.password_reset_requested', $this->audit('auth.password_reset_requested')->action);
        $this->assertSame('dealer1', $this->audit('auth.password_reset_requested')->subject_label);
    }

    public function test_the_answer_is_the_same_for_unknown_and_inactive_accounts_and_nothing_is_stored(): void
    {
        $this->make('DEALER', 'gone', ['status' => 'INACTIVE']);
        $real = $this->make('DEALER', 'real');

        $a = $this->post('/forgot-password', ['username' => 'real'])->getSession()->get('status');
        $b = $this->post('/forgot-password', ['username' => 'nobody-here'])->getSession()->get('status');
        $c = $this->post('/forgot-password', ['username' => 'gone'])->getSession()->get('status');

        $this->assertSame($a, $b);
        $this->assertSame($a, $c);
        $this->assertSame(1, PasswordResetRequest::count());
        $this->assertSame($real->admin_id, PasswordResetRequest::first()->admin_id);
    }

    public function test_requests_are_limited_per_address(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['username' => 'x' . $i])->assertSessionHas('status');
        }
        $this->post('/forgot-password', ['username' => 'x6'])->assertSessionHasErrors('username');
    }

    public function test_an_administrator_sees_the_request_and_sets_a_generated_temporary_password(): void
    {
        $admin = $this->make('ADMIN', 'boss');
        $dealer = $this->make('DEALER', 'dealer1');
        $this->post('/forgot-password', ['username' => 'dealer1']);

        $this->as($admin)->get('/admin/profile')->assertOk()
            ->assertSee('Forgot-password requests')->assertSee('dealer1')->assertSee('1 password request');

        $req = PasswordResetRequest::first();
        $response = $this->as($admin)->post(route('admin.profile.password-requests.resolve', $req->id), [
            'current_password' => 'Old-Passw0rd-x',
        ]);
        $response->assertRedirect(route('admin.profile'));

        $temp = session('temp_password');
        $this->assertSame('dealer1', $temp['username']);
        $this->assertTrue($temp['generated']);
        $this->assertTrue(Validator::make(['p' => $temp['password']], ['p' => PasswordPolicy::rule()])->passes());

        $dealer->refresh();
        $this->assertTrue(Hash::check($temp['password'], $dealer->password));
        $this->assertTrue($dealer->must_change_password);
        $this->assertSame('DONE', $req->fresh()->status);
        $this->assertSame($admin->admin_id, $req->fresh()->resolved_by);

        $row = $this->audit('account.password_reset');
        $this->assertSame('dealer1', $row->subject_label);
        $this->assertStringNotContainsString($temp['password'], json_encode($row->meta));
        $this->assertSame(0, PasswordResetRequest::where('status', 'PENDING')->count());
    }

    public function test_an_administrator_can_type_the_temporary_password(): void
    {
        $admin = $this->make('ADMIN', 'boss');
        $dealer = $this->make('DEALER', 'dealer1');
        $this->post('/forgot-password', ['username' => 'dealer1']);

        $this->as($admin)->post(route('admin.profile.password-requests.resolve', PasswordResetRequest::first()->id), [
            'current_password' => 'Old-Passw0rd-x', 'temporary_password' => 'Typed-Temp-123',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Typed-Temp-123', $dealer->fresh()->password));
        $this->assertFalse(session('temp_password')['generated']);
    }

    public function test_a_weak_typed_temporary_password_is_refused(): void
    {
        $admin = $this->make('ADMIN', 'boss');
        $dealer = $this->make('DEALER', 'dealer1');
        $this->post('/forgot-password', ['username' => 'dealer1']);

        $this->as($admin)->post(route('admin.profile.password-requests.resolve', PasswordResetRequest::first()->id), [
            'current_password' => 'Old-Passw0rd-x', 'temporary_password' => 'short1A',
        ])->assertSessionHasErrorsIn('resetRequest', 'temporary_password');

        $this->assertTrue(Hash::check('Old-Passw0rd-x', $dealer->fresh()->password));
    }

    public function test_the_wrong_own_password_changes_nothing_and_five_misses_lock_it(): void
    {
        $admin = $this->make('ADMIN', 'boss');
        $dealer = $this->make('DEALER', 'dealer1');
        $this->post('/forgot-password', ['username' => 'dealer1']);
        $url = route('admin.profile.password-requests.resolve', PasswordResetRequest::first()->id);

        for ($i = 0; $i < 5; $i++) {
            $this->as($admin)->post($url, ['current_password' => 'wrong'])->assertSessionHasErrorsIn('resetRequest', 'current_password');
        }
        // right password now, still locked
        $this->as($admin)->post($url, ['current_password' => 'Old-Passw0rd-x'])->assertSessionHasErrorsIn('resetRequest', 'current_password');

        $this->assertTrue(Hash::check('Old-Passw0rd-x', $dealer->fresh()->password));
        $this->assertSame('PENDING', PasswordResetRequest::first()->status);
        $this->assertSame(5, AuditLog::where('action', 'auth.reauth_failed')->count());
    }

    public function test_an_administrator_cannot_resolve_a_request_for_their_own_account(): void
    {
        $admin = $this->make('ADMIN', 'boss');
        PasswordResetRequest::create(['admin_id' => $admin->admin_id, 'username' => 'boss', 'status' => 'PENDING', 'requested_at' => now()]);

        $this->as($admin)->post(route('admin.profile.password-requests.resolve', PasswordResetRequest::first()->id), [
            'current_password' => 'Old-Passw0rd-x',
        ])->assertSessionHasErrorsIn('resetRequest', 'request');

        $this->assertFalse((bool) $admin->fresh()->must_change_password);
    }

    public function test_dismissing_closes_the_request_without_touching_the_password(): void
    {
        $admin = $this->make('ADMIN', 'boss');
        $dealer = $this->make('DEALER', 'dealer1');
        $this->post('/forgot-password', ['username' => 'dealer1']);
        $req = PasswordResetRequest::first();

        $this->as($admin)->post(route('admin.profile.password-requests.dismiss', $req->id))->assertRedirect(route('admin.profile'));

        $this->assertSame('DISMISSED', $req->fresh()->status);
        $this->assertTrue(Hash::check('Old-Passw0rd-x', $dealer->fresh()->password));
        $this->assertNotNull($this->audit('account.password_reset_dismissed'));
    }

    // ---------- add an administrator ----------

    private function newAdminForm(array $over = []): array
    {
        return array_merge([
            'full_name' => 'New Person', 'username' => 'new.person', 'email' => 'new@example.com', 'phone_number' => '0771234567',
            'password' => self::STRONG, 'password_confirmation' => self::STRONG, 'current_password' => 'Old-Passw0rd-x',
        ], $over);
    }

    public function test_an_administrator_adds_an_administrator_who_must_change_the_password(): void
    {
        $boss = $this->make('ADMIN', 'boss');

        $this->as($boss)->post(route('admin.profile.admins.store'), $this->newAdminForm())
            ->assertRedirect(route('admin.profile'))->assertSessionHasNoErrors();

        $new = Admin::where('username', 'new.person')->first();
        $this->assertNotNull($new);
        $this->assertSame('ADMIN', $new->role);
        $this->assertSame('ACTIVE', $new->status);
        $this->assertTrue($new->must_change_password);
        $this->assertTrue(Hash::check(self::STRONG, $new->password));
        $this->assertNotSame(self::STRONG, $new->password);
        $this->assertNotEmpty($new->admin_id);

        $row = $this->audit('admin.created');
        $this->assertSame('new.person', $row->subject_label);
        $this->assertSame('boss', $row->actor_name === 'Boss' ? 'boss' : $row->actor_name);
        $this->assertStringNotContainsString(self::STRONG, json_encode($row->meta));
    }

    public function test_a_posted_role_or_status_is_ignored_when_adding_an_administrator(): void
    {
        $boss = $this->make('ADMIN', 'boss');

        $this->as($boss)->post(route('admin.profile.admins.store'), $this->newAdminForm(['role' => 'DEALER', 'status' => 'INACTIVE', 'must_change_password' => 0]));

        $new = Admin::where('username', 'new.person')->first();
        $this->assertSame('ADMIN', $new->role);
        $this->assertSame('ACTIVE', $new->status);
        $this->assertTrue($new->must_change_password);
    }

    public function test_adding_an_administrator_needs_the_signed_in_admins_own_password(): void
    {
        $boss = $this->make('ADMIN', 'boss');

        $this->as($boss)->post(route('admin.profile.admins.store'), $this->newAdminForm(['current_password' => 'nope']))
            ->assertSessionHasErrorsIn('addAdmin', 'current_password');

        $this->assertNull(Admin::where('username', 'new.person')->first());
    }

    public function test_adding_an_administrator_validates_username_email_and_password(): void
    {
        $boss = $this->make('ADMIN', 'boss');
        $this->make('DEALER', 'taken');

        $go = fn (array $over) => $this->as($boss)->post(route('admin.profile.admins.store'), $this->newAdminForm($over));

        $go(['username' => 'TAKEN'])->assertSessionHasErrorsIn('addAdmin', 'username');          // same name, other case
        $go(['username' => 'has space'])->assertSessionHasErrorsIn('addAdmin', 'username');
        $go(['username' => 'ab'])->assertSessionHasErrorsIn('addAdmin', 'username');
        $go(['email' => 'TAKEN@example.com'])->assertSessionHasErrorsIn('addAdmin', 'email');
        $go(['email' => 'not-an-email'])->assertSessionHasErrorsIn('addAdmin', 'email');
        $go(['password' => 'short1A', 'password_confirmation' => 'short1A'])->assertSessionHasErrorsIn('addAdmin', 'password');
        $go(['password' => 'alllowercase123', 'password_confirmation' => 'alllowercase123'])->assertSessionHasErrorsIn('addAdmin', 'password');
        $go(['password_confirmation' => 'different'])->assertSessionHasErrorsIn('addAdmin', 'password');
        $go(['phone_number' => 'call me'])->assertSessionHasErrorsIn('addAdmin', 'phone_number');

        $this->assertSame(2, Admin::count()); // boss + taken, nothing added
    }

    public function test_only_administrators_reach_the_administration_actions(): void
    {
        foreach (['DEALER', 'FINANCE', 'TECHNICIAN', 'SUPPLIER'] as $role) {
            $user = $this->make($role, strtolower($role));
            $this->as($user)->post(route('admin.profile.admins.store'), $this->newAdminForm())->assertForbidden();
            $this->as($user)->post(route('admin.profile.password-requests.resolve', 1), ['current_password' => 'x'])->assertForbidden();
            $this->as($user)->post(route('admin.profile.password-requests.dismiss', 1))->assertForbidden();
        }
        $this->assertNull(Admin::where('username', 'new.person')->first());
    }

    // ---------- the profile page ----------

    public function test_only_administrators_see_the_administration_section(): void
    {
        $this->as($this->make('ADMIN', 'boss'))->get('/admin/profile')->assertOk()
            ->assertSee('Administration')->assertSee('Add an administrator')->assertSee('Edit my details')->assertSee('Change my password');

        $this->as($this->make('FINANCE', 'fin'))->get('/finance/profile')->assertOk()
            ->assertSee('Edit my details')->assertSee('Change my password')
            ->assertDontSee('Administration')->assertDontSee('Add an administrator');
    }

    public function test_an_unauthenticated_visitor_is_sent_to_the_login_page(): void
    {
        $this->get('/admin/profile')->assertRedirect(route('login'));
    }

    // ---------- edit my details ----------

    public function test_a_person_edits_their_own_name_email_and_phone_and_it_is_audited(): void
    {
        $boss = $this->make('ADMIN', 'boss');

        $this->as($boss)->put(route('admin.profile.update'), [
            'full_name' => 'Boss Person', 'email' => 'boss.new@example.com', 'phone_number' => '+94 77 123 4567',
            'username' => 'hacker', 'role' => 'DEALER', 'status' => 'INACTIVE',
        ])->assertSessionHasNoErrors();

        $boss->refresh();
        $this->assertSame('Boss Person', $boss->full_name);
        $this->assertSame('boss.new@example.com', $boss->email);
        $this->assertSame('+94 77 123 4567', $boss->phone_number);
        $this->assertSame('boss', $boss->username);
        $this->assertSame('ADMIN', $boss->role);
        $this->assertSame('ACTIVE', $boss->status);

        $row = $this->audit('account.profile_updated');
        $this->assertSame('boss.new@example.com', $row->changes['email']['to']);
    }

    public function test_profile_edit_rejects_bad_input_and_an_email_already_used(): void
    {
        $boss = $this->make('ADMIN', 'boss');
        $this->make('DEALER', 'other');

        $this->as($boss)->put(route('admin.profile.update'), ['full_name' => '', 'email' => 'x'])
            ->assertSessionHasErrorsIn('profile', ['full_name', 'email']);
        $this->as($boss)->put(route('admin.profile.update'), ['full_name' => 'B', 'email' => 'Other@example.com'])
            ->assertSessionHasErrorsIn('profile', 'email');
        $this->as($boss)->put(route('admin.profile.update'), ['full_name' => 'B', 'email' => 'b@example.com', 'phone_number' => 'abc'])
            ->assertSessionHasErrorsIn('profile', 'phone_number');

        $this->assertSame('Boss', $boss->fresh()->full_name);
    }

    public function test_finance_and_technician_use_their_own_profile_routes_and_others_are_refused(): void
    {
        $fin = $this->make('FINANCE', 'fin');
        $this->as($fin)->put(route('finance.profile.update'), ['full_name' => 'Fin New', 'email' => 'fin@example.com'])->assertSessionHasNoErrors();
        $this->assertSame('Fin New', $fin->fresh()->full_name);

        $this->as($fin)->put(route('admin.profile.update'), ['full_name' => 'x', 'email' => 'x@example.com'])->assertForbidden();
        $this->as($this->make('DEALER', 'd'))->put(route('admin.profile.update'), ['full_name' => 'x', 'email' => 'x@example.com'])->assertForbidden();
    }

    // ---------- change my password ----------

    public function test_change_password_needs_the_current_one_and_a_strong_different_new_one(): void
    {
        $boss = $this->make('ADMIN', 'boss');
        $url = route('account.password.update');
        $send = fn (array $over) => $this->as($boss)->put($url, array_merge([
            'current_password' => 'Old-Passw0rd-x', 'password' => self::STRONG, 'password_confirmation' => self::STRONG,
        ], $over));

        $send(['current_password' => 'wrong'])->assertSessionHasErrorsIn('changePassword', 'current_password');
        $send(['password' => 'weak', 'password_confirmation' => 'weak'])->assertSessionHasErrorsIn('changePassword', 'password');
        $send(['password' => 'Old-Passw0rd-x', 'password_confirmation' => 'Old-Passw0rd-x'])->assertSessionHasErrorsIn('changePassword', 'password');
        $send(['password_confirmation' => 'Different-123'])->assertSessionHasErrorsIn('changePassword', 'password');

        $this->assertTrue(Hash::check('Old-Passw0rd-x', $boss->fresh()->password));

        $send([])->assertSessionHasNoErrors()->assertSessionHas('success');

        $boss->refresh();
        $this->assertTrue(Hash::check(self::STRONG, $boss->password));
        $this->assertNotNull($boss->password_changed_at);
        $this->assertFalse($boss->must_change_password);
        $this->assertNotNull($this->audit('account.password_changed'));
    }

    public function test_a_browser_signed_in_before_the_password_changed_is_signed_out(): void
    {
        $boss = $this->make('ADMIN', 'boss');
        $oldHash = $boss->password;

        // this browser: signs in, opens a page (its password hash is remembered), changes the password
        $this->as($boss)->get('/admin/profile')->assertOk();
        $this->put(route('account.password.update'), [
            'current_password' => 'Old-Passw0rd-x', 'password' => self::STRONG, 'password_confirmation' => self::STRONG,
        ])->assertSessionHasNoErrors();

        // another browser still carries the OLD hash in its session
        $this->flushSession();
        $this->withSession(['password_hash_web' => $oldHash])->actingAs($boss->fresh())
            ->get('/admin/profile')->assertRedirect(route('login'));
    }

    // ---------- forced change at first sign-in ----------

    public function test_a_new_account_is_held_on_the_change_password_screen_until_it_chooses_a_password(): void
    {
        $boss = $this->make('ADMIN', 'boss');
        $this->as($boss)->post(route('admin.profile.admins.store'), $this->newAdminForm());
        $this->post('/logout');

        $this->post('/login', ['username' => 'new.person', 'password' => self::STRONG])->assertRedirect(route('admin.dashboard'));
        $new = Admin::where('username', 'new.person')->first();

        // any other page sends them to the change-password screen
        $this->as($new)->get('/admin/profile')->assertRedirect(route('account.password.edit'));
        $this->as($new)->get('/admin/audit-log')->assertRedirect(route('account.password.edit'));
        $this->as($new)->get(route('account.password.edit'))->assertOk()->assertSee('Choose your own password');

        // a weak or unchanged password is refused
        $send = fn (array $over) => $this->as($new)->put(route('account.password.update'), array_merge([
            'current_password' => self::STRONG, 'password' => 'Brand-New-Passw0rd', 'password_confirmation' => 'Brand-New-Passw0rd',
        ], $over));
        $send(['password' => 'weak', 'password_confirmation' => 'weak'])->assertSessionHasErrors('password');
        $send(['password' => self::STRONG, 'password_confirmation' => self::STRONG])->assertSessionHasErrors('password');
        $send(['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->assertTrue($new->fresh()->must_change_password);

        $send([])->assertRedirect(route('admin.dashboard'));

        $new->refresh();
        $this->assertFalse($new->must_change_password);
        $this->assertTrue(Hash::check('Brand-New-Passw0rd', $new->password));
        $this->as($new)->get('/admin/profile')->assertOk();
    }

    public function test_a_held_account_can_still_sign_out(): void
    {
        $held = $this->make('DEALER', 'held', ['must_change_password' => true]);

        $this->as($held)->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_a_held_account_gets_a_json_423_not_a_redirect_for_ajax(): void
    {
        $held = $this->make('ADMIN', 'held', ['must_change_password' => true]);

        $this->as($held)->getJson('/admin/profile')->assertStatus(423);
    }

    public function test_the_temporary_password_from_an_administrator_forces_a_change_at_the_next_sign_in(): void
    {
        $boss = $this->make('ADMIN', 'boss');
        $dealer = $this->make('DEALER', 'dealer1');
        $this->post('/forgot-password', ['username' => 'dealer1']);
        $this->as($boss)->post(route('admin.profile.password-requests.resolve', PasswordResetRequest::first()->id), [
            'current_password' => 'Old-Passw0rd-x', 'temporary_password' => 'Typed-Temp-123',
        ]);
        $this->post('/logout');

        $this->post('/login', ['username' => 'dealer1', 'password' => 'Old-Passw0rd-x'])->assertSessionHasErrors('username');
        $this->post('/login', ['username' => 'dealer1', 'password' => 'Typed-Temp-123'])->assertRedirect(route('dealer.dashboard'));
        $this->as($dealer->fresh())->get('/dealer/dashboard')->assertRedirect(route('account.password.edit'));
    }

    public function test_the_same_browser_stays_signed_in_after_changing_its_password_and_after_a_normal_sign_in(): void
    {
        $boss = $this->make('ADMIN', 'boss');

        $this->flushSession();
        $this->post('/login', ['username' => 'boss', 'password' => 'Old-Passw0rd-x'])->assertRedirect(route('admin.dashboard'));
        $this->get('/admin/profile')->assertOk();

        $this->put(route('account.password.update'), [
            'current_password' => 'Old-Passw0rd-x', 'password' => self::STRONG, 'password_confirmation' => self::STRONG,
        ])->assertSessionHasNoErrors();

        $this->get('/admin/profile')->assertOk();
        $this->assertAuthenticatedAs($boss->fresh());
    }

    public function test_a_failed_form_is_recorded_without_any_password_in_the_audit_log(): void
    {
        $boss = $this->make('ADMIN', 'boss');

        $this->as($boss)->post(route('admin.profile.admins.store'), $this->newAdminForm(['password' => 'Weak1', 'password_confirmation' => 'Weak1']));

        $log = json_encode(AuditLog::all()->map->only(['action', 'meta', 'changes'])->all());
        $this->assertStringNotContainsString('Weak1', $log);
        $this->assertStringNotContainsString('Old-Passw0rd-x', $log);
        $this->assertStringNotContainsString(self::STRONG, $log);
    }

    public function test_adding_an_administrator_writes_one_detailed_audit_entry_and_no_duplicate(): void
    {
        $boss = $this->make('ADMIN', 'boss');

        $this->as($boss)->post(route('admin.profile.admins.store'), $this->newAdminForm());

        $this->assertSame(1, AuditLog::where('action', 'admin.created')->count());
        $this->assertSame(0, AuditLog::where('action', 'request.write')->count());
    }

    // ---------- small pieces ----------

    public function test_generated_passwords_always_satisfy_the_rule(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $p = PasswordPolicy::generate();
            $this->assertTrue(Validator::make(['p' => $p], ['p' => PasswordPolicy::rule()])->passes(), $p);
            $this->assertDoesNotMatchRegularExpression('/[0O1lI]/', $p);
        }
    }

    public function test_every_new_audit_action_has_a_label_and_category(): void
    {
        foreach (['admin.created', 'account.password_changed', 'account.password_reset', 'account.password_reset_dismissed', 'auth.password_reset_requested', 'auth.reauth_failed'] as $action) {
            $this->assertArrayHasKey($action, AuditLog::ACTIONS);
        }
    }

    public function test_the_pending_count_is_shown_in_the_header_for_administrators(): void
    {
        $boss = $this->make('ADMIN', 'boss');
        $this->make('DEALER', 'dealer1');
        $this->post('/forgot-password', ['username' => 'dealer1']);

        $this->assertSame(1, PasswordResetRequest::pendingCount());
        $this->as($boss)->get('/admin/profile')->assertSee('People waiting for a new password');
    }
}