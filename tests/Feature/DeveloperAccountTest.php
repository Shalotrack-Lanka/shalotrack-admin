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

/** The developer account (admin001): account directory, temporary passwords for every role, audit scoping. */
class DeveloperAccountTest extends TestCase
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

    private function dev(): Admin
    {
        return $this->make('ADMIN', 'admin001');
    }

    private function log(string $actorId, string $action = 'auth.login', ?string $subjectId = null, string $subjectType = 'admin'): void
    {
        AuditLog::create([
            'occurred_at' => now(), 'actor_id' => $actorId, 'actor_name' => $actorId, 'actor_role' => 'ADMIN',
            'category' => 'security', 'action' => $action, 'subject_type' => $subjectType,
            'subject_id' => $subjectId ?? $actorId, 'subject_label' => $subjectId ?? $actorId,
        ]);
    }

    public function test_is_developer_rules(): void
    {
        $this->assertTrue($this->dev()->isDeveloper());
        $this->assertFalse($this->make('ADMIN', 'boss')->isDeveloper());
        $this->assertFalse($this->make('ADMIN', 'admin001x')->isDeveloper());
    }

    public function test_developer_name_is_case_insensitive_and_needs_admin_role_and_active(): void
    {
        $this->assertTrue($this->make('ADMIN', 'ADMIN001')->isDeveloper());
        $this->assertFalse($this->make('DEALER', 'admin001')->isDeveloper());
        $this->assertFalse($this->make('ADMIN', 'admin001', ['admin_id' => 'x2', 'status' => 'INACTIVE'])->isDeveloper());
    }

    public function test_config_list_overrides_the_default_name(): void
    {
        config(['erp.developer_usernames' => ['founder']]);
        $this->assertTrue($this->make('ADMIN', 'founder')->isDeveloper());
        $this->assertFalse($this->make('ADMIN', 'admin001')->isDeveloper());
    }

    public function test_directory_and_reset_are_forbidden_to_everyone_but_the_developer(): void
    {
        $boss = $this->make('ADMIN', 'boss');
        $dealer = $this->make('DEALER', 'dealer1');

        $this->as($boss)->get(route('admin.accounts.index'))->assertForbidden();
        $this->as($boss)->post(route('admin.accounts.temporary-password', $dealer->admin_id), ['current_password' => 'Old-Passw0rd-x'])->assertForbidden();
        $this->as($dealer)->get(route('admin.accounts.index'))->assertForbidden();
        $this->assertSame(false, (bool) $dealer->fresh()->must_change_password);
    }

    public function test_developer_sees_every_role_and_can_search_and_filter(): void
    {
        $this->make('DEALER', 'dealerjoe', ['full_name' => 'Joe Dealer', 'phone_number' => '0771234567']);
        $this->make('FINANCE', 'fin1');
        $this->make('SUPPLIER', 'sup1', ['status' => 'INACTIVE']);

        $this->as($this->dev());
        $this->get(route('admin.accounts.index'))->assertOk()
            ->assertSee('dealerjoe')->assertSee('fin1')->assertSee('sup1')->assertSee('admin001');
        $this->get(route('admin.accounts.index', ['q' => 'joe']))->assertSee('dealerjoe')->assertDontSee('fin1');
        $this->get(route('admin.accounts.index', ['q' => '077123']))->assertSee('dealerjoe')->assertDontSee('fin1');
        $this->get(route('admin.accounts.index', ['role' => 'FINANCE']))->assertSee('fin1')->assertDontSee('dealerjoe');
        $this->get(route('admin.accounts.index', ['status' => 'INACTIVE']))->assertSee('sup1')->assertDontSee('fin1');
        $this->get(route('admin.accounts.index', ['q' => '%']))->assertOk()->assertDontSee('fin1');
        $this->get(route('admin.accounts.index', ['q' => ['x']]))->assertOk();
    }

    public function test_developer_sets_a_temporary_password_without_a_request_and_nothing_secret_is_logged(): void
    {
        $dealer = $this->make('DEALER', 'dealer1');
        $this->as($this->dev());

        $res = $this->post(route('admin.accounts.temporary-password', $dealer->admin_id), [
            'current_password' => 'Old-Passw0rd-x', 'temporary_password' => 'Temp-Passw0rd-77',
        ]);
        $res->assertRedirect()->assertSessionHas('temp_password', fn ($t) => $t['password'] === 'Temp-Passw0rd-77' && $t['username'] === 'dealer1');

        $d = $dealer->fresh();
        $this->assertTrue(Hash::check('Temp-Passw0rd-77', $d->password));
        $this->assertTrue((bool) $d->must_change_password);
        $this->assertNull($d->password_changed_at);

        $log = $this->audit('account.password_reset');
        $this->assertSame('dealer1', $log->subject_label);
        $this->assertSame('id-admin001', $log->actor_id);
        $this->assertStringNotContainsString('Temp-Passw0rd-77', json_encode($log->toArray()));
        $this->assertFalse(collect(AuditLog::all())->contains(fn ($r) => str_contains(json_encode($r->toArray()), 'Temp-Passw0rd-77')));
    }

    public function test_generated_password_works_and_pending_request_is_closed(): void
    {
        $fin = $this->make('FINANCE', 'fin1');
        PasswordResetRequest::create(['admin_id' => $fin->admin_id, 'username' => 'fin1', 'status' => 'PENDING', 'requested_at' => now()]);
        $this->as($this->dev());

        $res = $this->post(route('admin.accounts.temporary-password', $fin->admin_id), ['current_password' => 'Old-Passw0rd-x']);
        $tmp = $res->getSession()->get('temp_password')['password'];
        $this->assertGreaterThanOrEqual(PasswordPolicy::MIN_LENGTH, strlen($tmp));
        $this->assertTrue(Hash::check($tmp, $fin->fresh()->password));
        $this->assertSame(0, PasswordResetRequest::where('status', 'PENDING')->count());
    }

    public function test_wrong_own_password_weak_password_self_and_inactive_are_refused(): void
    {
        $dev = $this->dev();
        $dealer = $this->make('DEALER', 'dealer1');
        $off = $this->make('DEALER', 'off1', ['status' => 'INACTIVE']);
        $this->as($dev);
        $before = $dealer->password;

        $this->post(route('admin.accounts.temporary-password', $dealer->admin_id), ['current_password' => 'nope'])->assertSessionHasErrors([], null, 'resetAccount');
        $this->post(route('admin.accounts.temporary-password', $dealer->admin_id), ['current_password' => 'Old-Passw0rd-x', 'temporary_password' => 'weak'])->assertSessionHasErrors([], null, 'resetAccount');
        $this->post(route('admin.accounts.temporary-password', $dev->admin_id), ['current_password' => 'Old-Passw0rd-x'])->assertSessionHasErrors([], null, 'resetAccount');
        $this->post(route('admin.accounts.temporary-password', $off->admin_id), ['current_password' => 'Old-Passw0rd-x'])->assertSessionHasErrors([], null, 'resetAccount');
        $this->post(route('admin.accounts.temporary-password', 'no-such-id'), ['current_password' => 'Old-Passw0rd-x'])->assertNotFound();

        $this->assertSame($before, $dealer->fresh()->password);
        $this->assertFalse((bool) $dealer->fresh()->must_change_password);
        $this->assertNull($this->audit('account.password_reset'));
    }

    public function test_the_person_must_choose_their_own_password_after_signing_in_with_the_temporary_one(): void
    {
        $dealer = $this->make('DEALER', 'dealer1');
        $this->as($this->dev());
        $this->post(route('admin.accounts.temporary-password', $dealer->admin_id), ['current_password' => 'Old-Passw0rd-x', 'temporary_password' => 'Temp-Passw0rd-77']);

        $this->as($dealer->fresh())->get('/dealer/dashboard')->assertRedirect(route('account.password.edit'));
    }

    public function test_regular_admin_sees_only_own_entries_and_entries_about_own_account(): void
    {
        $boss = $this->make('ADMIN', 'boss');
        $dev = $this->dev();
        $this->log($boss->admin_id, 'auth.login');
        $this->log($dev->admin_id, 'auth.login');
        $this->log($dev->admin_id, 'account.password_reset', $boss->admin_id);
        $this->log($dev->admin_id, 'account.password_reset', 'id-someoneelse');

        $this->as($boss);
        $page = $this->get(route('admin.audit-log'))->assertOk()->assertSee('You see your own activity');
        $this->assertCount(2, $page->viewData('rows')->items());

        // filters can only narrow, never widen
        $this->get(route('admin.audit-log', ['actor' => 'admin001']))->assertOk();
        $this->assertCount(1, $this->get(route('admin.audit-log', ['actor' => 'id-admin001']))->viewData('rows')->items());
        $this->assertCount(2, $this->get(route('admin.audit-log', ['q' => 'id-']))->viewData('rows')->items());

        $csv = $this->get(route('admin.audit-log', ['export' => 'csv']))->streamedContent();
        $this->assertStringNotContainsString('id-someoneelse', $csv);
    }

    public function test_developer_sees_everything(): void
    {
        $boss = $this->make('ADMIN', 'boss');
        $dev = $this->dev();
        $this->log($boss->admin_id);
        $this->log($dev->admin_id);
        $this->log($dev->admin_id, 'account.password_reset', 'id-someoneelse');

        $this->as($dev);
        $page = $this->get(route('admin.audit-log'))->assertOk()->assertSee('Developer view');
        $this->assertCount(3, $page->viewData('rows')->items());
        $this->assertStringContainsString('id-someoneelse', $this->get(route('admin.audit-log', ['export' => 'csv']))->streamedContent());
    }

    public function test_profile_shows_the_directory_link_only_to_the_developer(): void
    {
        $this->as($this->dev())->get(route('admin.profile'))->assertOk()->assertSee(route('admin.accounts.index'), false);
        $this->as($this->make('ADMIN', 'boss'))->get(route('admin.profile'))->assertOk()->assertDontSee(route('admin.accounts.index'), false);
    }
}