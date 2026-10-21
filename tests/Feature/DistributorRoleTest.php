<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AuditLog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Step 1 of the distributor role: login, portal scoping (fails closed), dealer-type sync, conversion command. */
class DistributorRoleTest extends TestCase
{
    private const STRONG = 'Str0ng-Passw0rd';

    private int $distA;
    private int $distB;
    private int $dealerA1;
    private int $dealerA2;
    private int $dealerB1;
    private int $direct;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
        Cache::flush();
        RateLimiter::clear('x');

        Schema::create('Admins', function (Blueprint $t) {
            $t->string('admin_id')->primary()->default(DB::raw('(lower(hex(randomblob(16))))'));
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
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->timestamp('email_verified_at')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('dealers', function (Blueprint $t) {
            $t->id();
            $t->string('full_name');
            $t->string('qualification')->nullable();
            $t->string('dealer_status')->nullable();
            $t->unsignedBigInteger('distributor_id')->nullable();
            $t->string('region')->nullable();
            $t->string('country')->nullable();
            $t->string('pin_code')->nullable();
            $t->string('contact_email')->nullable()->unique();
            $t->string('tax_pan')->nullable();
            $t->string('cst_no')->nullable();
            $t->string('vat_tin')->nullable();
            $t->string('gst_pan')->nullable();
            $t->decimal('security_deposit', 12, 2)->nullable();
            $t->date('deposit_date')->nullable();
            $t->string('network')->nullable();
            $t->string('login_id')->nullable();
            $t->string('password')->nullable();
            $t->text('payment_modes')->nullable();
            $t->string('profile_photo')->nullable();
            $t->string('copy_of_ma')->nullable();
            $t->string('passport_front')->nullable();
            $t->string('passport_last')->nullable();
            $t->string('status')->default('active');
            $t->string('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('dealer_transfer_ledgers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('dealer_id');
            $t->unsignedBigInteger('from_dealer_id')->nullable();
            $t->string('device_category');
            $t->integer('quantity');
            $t->string('created_by_admin_id', 64)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('dealer_transfer_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('ledger_id');
            $t->unsignedBigInteger('device_id');
            $t->string('imei_number')->nullable();
            $t->string('sim_number')->nullable();
            $t->timestamps();
        });
        Schema::create('dealer_customer_ads', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('dealer_id')->nullable();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('contact')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('setup_shalotrack_devices', function (Blueprint $t) {
            $t->id('shdevice_id');
            $t->string('device_category')->nullable();
            $t->string('imei_number')->unique();
            $t->string('sim_number')->nullable();
            $t->string('iccid')->nullable();
            $t->string('imsi')->nullable();
            $t->string('status')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->timestamp('canceled_date')->nullable();
            $t->unsignedBigInteger('dealer_id')->nullable();
            $t->unsignedBigInteger('assigned_customer_id')->nullable();
            $t->unsignedBigInteger('device_type_id')->nullable();
            $t->timestamp('allocated_at')->nullable();
            $t->unsignedBigInteger('transfer_id')->nullable();
            $t->timestamps();
        });
        Schema::create('activated_devices', function (Blueprint $t) {
            $t->id('activated_device_id');
            $t->string('vehicle_id')->unique();
            $t->string('customer_name')->nullable();
            $t->string('vehicle_number')->nullable();
            $t->string('imei_number')->nullable();
            $t->string('sim_number')->nullable();
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

        // Network: distributor A (dealers A1, A2), distributor B (dealer B1), one direct dealer.
        $this->distA    = $this->dealer('Dist Alpha', 'distributor');
        $this->distB    = $this->dealer('Dist Beta', 'distributor');
        $this->dealerA1 = $this->dealer('Alpha One', 'retailer', $this->distA);
        $this->dealerA2 = $this->dealer('Alpha Two', 'retailer', $this->distA);
        $this->dealerB1 = $this->dealer('Beta One', 'retailer', $this->distB);
        $this->direct   = $this->dealer('Direct Dan', 'retailer');
    }

    private function as(Admin $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($user);
    }

    private function dealer(string $name, string $type, ?int $distributorId = null, string $status = 'active'): int
    {
        return DB::table('dealers')->insertGetId([
            'full_name' => $name, 'dealer_status' => $type, 'distributor_id' => $distributorId,
            'region' => 'western', 'contact_email' => strtolower(str_replace(' ', '', $name)) . '@example.com',
            'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function login(string $role, string $username, ?int $dealerId = null, array $extra = []): Admin
    {
        return Admin::forceCreate(array_merge([
            'admin_id' => 'id-' . $username, 'username' => $username, 'full_name' => ucfirst($username),
            'email' => $username . '@example.com', 'password' => Hash::make('Old-Passw0rd-x'),
            'role' => $role, 'status' => 'ACTIVE', 'dealer_id' => $dealerId,
        ], $extra));
    }

    private function device(string $imei, ?int $holder, string $status = 'Activated', ?int $customer = null): void
    {
        DB::table('setup_shalotrack_devices')->insert([
            'imei_number' => $imei, 'dealer_id' => $holder, 'status' => $status,
            'assigned_customer_id' => $customer, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function lead(int $dealerId, string $name): void
    {
        DB::table('dealer_customer_ads')->insert(['dealer_id' => $dealerId, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
    }

    // ---- model helpers --------------------------------------------------------------------------------

    public function test_distributor_dealer_resolves_only_for_a_valid_active_login(): void
    {
        $ok = $this->login('DISTRIBUTOR', 'dist_a', $this->distA);
        $this->assertTrue($ok->isDistributor());
        $this->assertSame($this->distA, $ok->distributorDealer()->id);

        // Login points at a retailer-type dealers row: no network.
        $this->assertNull($this->login('DISTRIBUTOR', 'bad_type', $this->dealerA1)->distributorDealer());
        // No dealer link at all.
        $this->assertNull($this->login('DISTRIBUTOR', 'no_link', null)->distributorDealer());
        // Dealers row archived.
        $archived = $this->dealer('Dist Old', 'distributor', null, 'archived');
        $this->assertNull($this->login('DISTRIBUTOR', 'old_dist', $archived)->distributorDealer());
        // Inactive login.
        $this->assertNull($this->login('DISTRIBUTOR', 'inactive', $this->distB, ['status' => 'INACTIVE'])->distributorDealer());
        // Other roles never resolve, even when linked to a distributor row.
        $this->assertNull($this->login('DEALER', 'dealer_x', $this->distA)->distributorDealer());
        $this->assertNull($this->login('ADMIN', 'admin_x', $this->distA)->distributorDealer());
    }

    // ---- login + access -------------------------------------------------------------------------------

    public function test_distributor_signs_in_and_lands_on_the_distributor_dashboard(): void
    {
        $this->login('DISTRIBUTOR', 'dist_a', $this->distA, ['password' => Hash::make(self::STRONG)]);

        $this->post('/login', ['username' => 'dist_a', 'password' => self::STRONG])
            ->assertRedirect(route('distributor.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_distributor_cannot_open_admin_dealer_or_finance_areas(): void
    {
        $this->actingAs($this->login('DISTRIBUTOR', 'dist_a', $this->distA));

        foreach ([route('admin.dashboard'), route('admin.dealer-management'), '/dealer/dashboard', '/dealer/device-commands'] as $uri) {
            $this->get($uri)->assertForbidden();
        }
    }

    public function test_other_roles_cannot_open_the_distributor_portal(): void
    {
        foreach (['ADMIN', 'DEALER', 'FINANCE', 'TECHNICIAN', 'SUPPLIER'] as $role) {
            $this->as($this->login($role, strtolower($role) . '_u', $this->distA));
            foreach (['/distributor/dashboard', '/distributor/dealers', '/distributor/stock', '/distributor/profile'] as $uri) {
                $this->get($uri)->assertForbidden();
            }
        }
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/distributor/dashboard')->assertRedirect(route('login'));
    }

    public function test_a_distributor_login_not_linked_to_a_distributor_sees_nothing(): void
    {
        $this->actingAs($this->login('DISTRIBUTOR', 'orphan', null));
        foreach (['/distributor/dashboard', '/distributor/dealers', '/distributor/stock'] as $uri) {
            $this->get($uri)->assertForbidden();
        }

        $this->as($this->login('DISTRIBUTOR', 'wrongtype', $this->direct));
        $this->get('/distributor/dealers')->assertForbidden();
    }

    // ---- scoping --------------------------------------------------------------------------------------

    public function test_dealers_page_lists_only_own_dealers_with_their_counts(): void
    {
        $this->device('A1-STOCK', $this->dealerA1);
        $this->device('A1-SOLD', $this->dealerA1, 'Assigned to Customer', 7);
        $this->device('B1-STOCK', $this->dealerB1);
        $this->device('DIRECT-STOCK', $this->direct);
        $this->lead($this->dealerA1, 'Lead of A1');
        $this->lead($this->dealerB1, 'Lead of B1');

        $this->actingAs($this->login('DISTRIBUTOR', 'dist_a', $this->distA));

        $res = $this->get('/distributor/dealers')->assertOk();
        $res->assertSee('Alpha One')->assertSee('Alpha Two');
        $res->assertDontSee('Beta One')->assertDontSee('Direct Dan')->assertDontSee('Dist Beta');
    }

    public function test_dashboard_counts_cover_only_own_network(): void
    {
        $this->device('OWN-1', $this->distA);
        $this->device('OWN-2', $this->distA);
        $this->device('OWN-SOLD', $this->distA, 'Assigned to Customer', 5);
        $this->device('A1-STOCK', $this->dealerA1);
        $this->device('A2-SOLD', $this->dealerA2, 'Assigned to Customer', 9);
        $this->device('B1-STOCK', $this->dealerB1);
        $this->device('B-OWN', $this->distB);
        $this->device('DIRECT-STOCK', $this->direct);
        $this->lead($this->dealerA1, 'x');
        $this->lead($this->dealerA2, 'y');
        $this->lead($this->dealerB1, 'z');

        $this->actingAs($this->login('DISTRIBUTOR', 'dist_a', $this->distA));
        $res = $this->get('/distributor/dashboard');
        $this->assertSame(2, $res->viewData('ownStock'));
        $this->assertSame(1, $res->viewData('ownSold'));
        $this->assertSame(2, $res->viewData('dealerCount'));
        $this->assertSame(1, $res->viewData('dealerStock'));
        $this->assertSame(1, $res->viewData('dealerSold'));
        $this->assertSame(2, $res->viewData('dealerCustomers'));
    }

    public function test_stock_page_shows_only_the_distributors_own_devices(): void
    {
        $this->device('MINE-111', $this->distA);
        $this->device('THEIRS-222', $this->distB);
        $this->device('DEALERS-333', $this->dealerA1);
        $this->device('DIRECT-444', $this->direct);

        $this->actingAs($this->login('DISTRIBUTOR', 'dist_a', $this->distA));
        $this->get('/distributor/stock')->assertOk()
            ->assertSee('MINE-111')
            ->assertDontSee('THEIRS-222')->assertDontSee('DEALERS-333')->assertDontSee('DIRECT-444');
    }

    public function test_two_distributors_never_see_each_other(): void
    {
        $this->device('A-ONLY', $this->distA);
        $this->device('B-ONLY', $this->distB);

        $this->actingAs($this->login('DISTRIBUTOR', 'dist_b', $this->distB));
        $this->get('/distributor/stock')->assertOk()->assertSee('B-ONLY')->assertDontSee('A-ONLY');
        $this->get('/distributor/dealers')->assertOk()->assertSee('Beta One')->assertDontSee('Alpha One');
    }

    // ---- creating / editing dealers keeps the login role in step -----------------------------------------

    public function test_creating_a_distributor_makes_a_distributor_login_and_a_retailer_makes_a_dealer_login(): void
    {
        $this->actingAs($this->login('ADMIN', 'boss'));

        $this->post(route('admin.dealer.store'), [
            'full_name' => 'New Distro', 'dealer_status' => 'distributor', 'region' => 'western',
            'contact_email' => 'newdistro@example.com',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->post(route('admin.dealer.store'), [
            'full_name' => 'New Shop', 'dealer_status' => 'retailer', 'region' => 'western',
            'contact_email' => 'newshop@example.com',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $d = DB::table('dealers')->where('contact_email', 'newdistro@example.com')->first();
        $s = DB::table('dealers')->where('contact_email', 'newshop@example.com')->first();
        $this->assertSame('DISTRIBUTOR', Admin::where('dealer_id', $d->id)->value('role'));
        $this->assertSame('DEALER', Admin::where('dealer_id', $s->id)->value('role'));

        $this->post(route('admin.dealer.store'), [
            'full_name' => 'Bogus', 'dealer_status' => 'emperor', 'region' => 'western',
            'contact_email' => 'bogus@example.com',
        ])->assertSessionHasErrors('dealer_status');
    }

    private function profilePayload(int $id, array $over = []): array
    {
        $d = DB::table('dealers')->find($id);

        return array_merge([
            'full_name' => $d->full_name, 'dealer_status' => $d->dealer_status, 'region' => 'western',
            'contact_email' => $d->contact_email, 'distributor_id' => $d->distributor_id,
        ], $over);
    }

    public function test_changing_dealer_type_switches_the_login_role_and_audits_it(): void
    {
        $this->actingAs($this->login('ADMIN', 'boss'));
        $dealerLogin = $this->login('DEALER', 'shop', $this->direct);

        $this->put(route('admin.dealer.profile.update', $this->direct), $this->profilePayload($this->direct, ['dealer_status' => 'distributor']))
            ->assertSessionHasNoErrors();

        $this->assertSame('DISTRIBUTOR', $dealerLogin->fresh()->role);
        $log = AuditLog::where('action', 'account.role_changed')->first();
        $this->assertNotNull($log);
        $this->assertSame(['from' => 'DEALER', 'to' => 'DISTRIBUTOR'], $log->changes['role']);
    }

    public function test_a_distributor_cannot_report_to_another_distributor_and_demotion_is_blocked_while_it_has_dealers(): void
    {
        $this->actingAs($this->login('ADMIN', 'boss'));

        // distributor_id is forced to null for a distributor
        $this->put(route('admin.dealer.profile.update', $this->distB), $this->profilePayload($this->distB, ['distributor_id' => $this->distA]))
            ->assertSessionHasNoErrors();
        $this->assertNull(DB::table('dealers')->where('id', $this->distB)->value('distributor_id'));

        // distributor A still has two dealers: cannot become a retailer
        $this->from('/x')->put(route('admin.dealer.profile.update', $this->distA), $this->profilePayload($this->distA, ['dealer_status' => 'retailer']))
            ->assertSessionHasErrors('dealer_status');
        $this->assertSame('distributor', DB::table('dealers')->where('id', $this->distA)->value('dealer_status'));
    }

    public function test_demoting_an_empty_distributor_switches_its_login_back_to_dealer(): void
    {
        $this->actingAs($this->login('ADMIN', 'boss'));
        $login = $this->login('DISTRIBUTOR', 'dist_b', $this->distB);
        DB::table('dealers')->where('distributor_id', $this->distB)->update(['distributor_id' => null]);

        $this->put(route('admin.dealer.profile.update', $this->distB), $this->profilePayload($this->distB, ['dealer_status' => 'retailer']))
            ->assertSessionHasNoErrors();

        $this->assertSame('DEALER', $login->fresh()->role);
    }

    public function test_dealer_type_change_never_touches_a_non_dealer_login(): void
    {
        $this->actingAs($this->login('ADMIN', 'boss'));
        $finance = $this->login('FINANCE', 'fin', $this->direct);

        $this->put(route('admin.dealer.profile.update', $this->direct), $this->profilePayload($this->direct, ['dealer_status' => 'distributor']))
            ->assertSessionHasNoErrors();

        $this->assertSame('FINANCE', $finance->fresh()->role);
    }

    // ---- conversion command ---------------------------------------------------------------------------

    public function test_sync_logins_is_a_dry_run_unless_applied(): void
    {
        $legacy = $this->login('DEALER', 'legacy', $this->distA);
        $this->login('DEALER', 'plain', $this->dealerA1);

        Artisan::call('distributors:sync-logins');
        $this->assertSame('DEALER', $legacy->fresh()->role);
        $this->assertSame(0, AuditLog::where('action', 'account.role_changed')->count());

        Artisan::call('distributors:sync-logins', ['--apply' => true]);
        $this->assertSame('DISTRIBUTOR', $legacy->fresh()->role);
        $this->assertSame('DEALER', Admin::where('username', 'plain')->value('role'));
        $this->assertSame(1, AuditLog::where('action', 'account.role_changed')->count());
    }

    // ---- profile and password -------------------------------------------------------------------------

    public function test_distributor_can_edit_own_profile_and_change_password(): void
    {
        $me = $this->login('DISTRIBUTOR', 'dist_a', $this->distA, ['password' => Hash::make(self::STRONG)]);
        $this->actingAs($me);

        $this->get('/distributor/profile')->assertOk();

        $this->put('/distributor/profile', ['full_name' => 'Alpha Boss', 'email' => 'alpha@example.com', 'phone_number' => '0771234567'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Alpha Boss', $me->fresh()->full_name);

        $this->put('/account/password', [
            'current_password' => self::STRONG, 'password' => 'Brand-New-Passw0rd', 'password_confirmation' => 'Brand-New-Passw0rd',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('Brand-New-Passw0rd', $me->fresh()->password));
        $this->assertSame(1, AuditLog::where('action', 'account.password_changed')->count());
    }
}