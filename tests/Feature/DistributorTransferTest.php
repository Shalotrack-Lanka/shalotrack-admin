<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\DealerTransferItem;
use App\Models\DealerTransferLedger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Step 2: stock moves admin > distributor > dealer and admin > dealer, with the sender recorded and history locked. */
class DistributorTransferTest extends TestCase
{
    private int $distA;
    private int $distB;
    private int $dealerA1;
    private int $dealerA2;
    private int $dealerB1;
    private int $direct;
    private int $archivedA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
        Cache::flush();

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
        Schema::create('dealers', function (Blueprint $t) {
            $t->id();
            $t->string('full_name');
            $t->string('dealer_status')->nullable();
            $t->unsignedBigInteger('distributor_id')->nullable();
            $t->string('region')->nullable();
            $t->string('contact_email')->nullable();
            $t->string('status')->default('active');
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
            $t->unique(['ledger_id', 'device_id']);
        });
        Schema::create('dealer_customer_ads', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('dealer_id')->nullable();
            $t->string('name')->nullable();
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

        $this->distA     = $this->dealer('Dist Alpha', 'distributor');
        $this->distB     = $this->dealer('Dist Beta', 'distributor');
        $this->dealerA1  = $this->dealer('Alpha One', 'retailer', $this->distA);
        $this->dealerA2  = $this->dealer('Alpha Two', 'retailer', $this->distA);
        $this->dealerB1  = $this->dealer('Beta One', 'retailer', $this->distB);
        $this->direct    = $this->dealer('Direct Dan', 'retailer');
        $this->archivedA = $this->dealer('Alpha Old', 'retailer', $this->distA, 'archived');
    }

    private function dealer(string $name, string $type, ?int $parent = null, string $status = 'active'): int
    {
        return DB::table('dealers')->insertGetId([
            'full_name' => $name, 'dealer_status' => $type, 'distributor_id' => $parent, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function login(string $role, string $username, ?int $dealerId = null): Admin
    {
        return Admin::find('id-' . $username) ?? Admin::forceCreate([
            'admin_id' => 'id-' . $username, 'username' => $username, 'full_name' => ucfirst($username),
            'email' => $username . '@example.com', 'password' => Hash::make('x'), 'role' => $role,
            'status' => 'ACTIVE', 'dealer_id' => $dealerId,
        ]);
    }

    private function as(Admin $u): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($u);
    }

    private function device(string $imei, ?int $holder, string $category = 'GT06', string $status = 'Activated', ?int $customer = null, ?string $sim = 'auto'): int
    {
        return DB::table('setup_shalotrack_devices')->insertGetId([
            'imei_number' => $imei, 'sim_number' => $sim === 'auto' ? 'SIM-' . $imei : $sim, 'device_category' => $category,
            'dealer_id' => $holder, 'status' => $status, 'assigned_customer_id' => $customer,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function holder(int $deviceId): ?int
    {
        $h = DB::table('setup_shalotrack_devices')->where('shdevice_id', $deviceId)->value('dealer_id');

        return $h === null ? null : (int) $h;
    }

    private function sendAsDistA(array $payload)
    {
        $this->as($this->login('DISTRIBUTOR', 'dist_a', $this->distA));

        return $this->post(route('distributor.transfer.store'), $payload);
    }

    // ---- distributor > dealer -------------------------------------------------------------------------

    public function test_distributor_passes_own_devices_to_its_dealer_and_the_sender_is_recorded(): void
    {
        $a = $this->device('111', $this->distA);
        $b = $this->device('222', $this->distA);

        $this->sendAsDistA(['dealer_id' => $this->dealerA1, 'device_ids' => [$a, $b]])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame($this->dealerA1, $this->holder($a));
        $this->assertSame($this->dealerA1, $this->holder($b));

        $ledger = DealerTransferLedger::first();
        $this->assertSame($this->distA, (int) $ledger->from_dealer_id);
        $this->assertSame($this->dealerA1, (int) $ledger->dealer_id);
        $this->assertSame(2, (int) $ledger->quantity);
        $this->assertSame('id-dist_a', $ledger->created_by_admin_id);
        $this->assertSame(2, DealerTransferItem::where('ledger_id', $ledger->id)->count());
        $this->assertSame($ledger->id, (int) DB::table('setup_shalotrack_devices')->where('shdevice_id', $a)->value('transfer_id'));

        $log = AuditLog::where('action', 'stock.transferred')->first();
        $this->assertSame('id-dist_a', $log->actor_id);
        $this->assertSame('distributor', $log->meta['from']);
    }

    public function test_one_record_per_category(): void
    {
        $a = $this->device('111', $this->distA, 'GT06');
        $b = $this->device('222', $this->distA, 'JM-VL');

        $this->sendAsDistA(['dealer_id' => $this->dealerA2, 'device_ids' => [$a, $b]])->assertSessionHasNoErrors();

        $this->assertSame(2, DealerTransferLedger::count());
        $this->assertEqualsCanonicalizing(['GT06', 'JM-VL'], DealerTransferLedger::pluck('device_category')->all());
        $this->assertSame(2, AuditLog::where('action', 'stock.transferred')->count());
    }

    public function test_cannot_send_to_a_dealer_outside_its_network(): void
    {
        $a = $this->device('111', $this->distA);

        foreach ([$this->dealerB1, $this->direct, $this->distB, $this->distA, $this->archivedA, 999999] as $target) {
            $this->sendAsDistA(['dealer_id' => $target, 'device_ids' => [$a]])->assertNotFound();
            $this->assertSame($this->distA, $this->holder($a), "moved to $target");
        }
        $this->assertSame(0, DealerTransferLedger::count());
        $this->assertSame(0, DealerTransferItem::count());
    }

    public function test_cannot_send_devices_it_does_not_hold_and_nothing_moves_when_any_is_foreign(): void
    {
        $own      = $this->device('111', $this->distA);
        $otherDist = $this->device('222', $this->distB);
        $company  = $this->device('333', null);
        $dealerHeld = $this->device('444', $this->dealerA1);
        $sold     = $this->device('555', $this->distA, 'GT06', 'Assigned to Customer', 9);
        $inactive = $this->device('666', $this->distA, 'GT06', 'Not Activated');
        $bound    = $this->device('777', $this->distA);
        DB::table('activated_devices')->insert(['vehicle_id' => 'v1', 'imei_number' => '777', 'created_at' => now(), 'updated_at' => now()]);

        foreach ([$otherDist, $company, $dealerHeld, $sold, $inactive, $bound] as $bad) {
            $this->sendAsDistA(['dealer_id' => $this->dealerA1, 'device_ids' => [$own, $bad]])
                ->assertSessionHasErrors('transfer');
            $this->assertSame($this->distA, $this->holder($own), 'own device moved although the request was refused');
        }

        $this->assertSame($this->distB, $this->holder($otherDist));
        $this->assertNull($this->holder($company));
        $this->assertSame(0, DealerTransferLedger::count());
    }

    public function test_the_same_device_cannot_be_sent_twice(): void
    {
        $a = $this->device('111', $this->distA);

        $this->sendAsDistA(['dealer_id' => $this->dealerA1, 'device_ids' => [$a]])->assertSessionHasNoErrors();
        $this->sendAsDistA(['dealer_id' => $this->dealerA2, 'device_ids' => [$a]])->assertSessionHasErrors('transfer');

        $this->assertSame($this->dealerA1, $this->holder($a));
        $this->assertSame(1, DealerTransferLedger::count());
        $this->assertSame(1, DealerTransferItem::count());
    }

    public function test_input_validation(): void
    {
        $a = $this->device('111', $this->distA);

        $this->sendAsDistA(['dealer_id' => $this->dealerA1, 'device_ids' => []])->assertSessionHasErrors('device_ids');
        $this->sendAsDistA(['dealer_id' => $this->dealerA1])->assertSessionHasErrors('device_ids');
        $this->sendAsDistA(['dealer_id' => $this->dealerA1, 'device_ids' => 'x'])->assertSessionHasErrors('device_ids');
        $this->sendAsDistA(['dealer_id' => $this->dealerA1, 'device_ids' => [$a, $a]])->assertSessionHasErrors('device_ids.1');
        $this->sendAsDistA(['dealer_id' => $this->dealerA1, 'device_ids' => ['abc']])->assertSessionHasErrors('device_ids.0');
        $this->sendAsDistA(['device_ids' => [$a]])->assertSessionHasErrors('dealer_id');
        $this->assertSame($this->distA, $this->holder($a));
    }

    public function test_a_distributor_with_no_valid_link_cannot_transfer(): void
    {
        $a = $this->device('111', $this->distA);
        $this->as($this->login('DISTRIBUTOR', 'orphan', null));
        $this->post(route('distributor.transfer.store'), ['dealer_id' => $this->dealerA1, 'device_ids' => [$a]])->assertForbidden();
        $this->get(route('distributor.transfer'))->assertForbidden();
        $this->assertSame($this->distA, $this->holder($a));
    }

    public function test_page_lists_only_own_stock_and_own_dealers(): void
    {
        $this->device('MINE111', $this->distA);
        $this->device('THEIRS222', $this->distB);
        $this->device('COMPANY333', null);
        $this->device('DEALERS444', $this->dealerA1);

        $this->as($this->login('DISTRIBUTOR', 'dist_a', $this->distA));
        $this->get(route('distributor.transfer'))->assertOk()
            ->assertSee('MINE111')->assertSee('Alpha One')->assertSee('Alpha Two')
            ->assertDontSee('THEIRS222')->assertDontSee('COMPANY333')->assertDontSee('DEALERS444')
            ->assertDontSee('Beta One')->assertDontSee('Direct Dan')->assertDontSee('Alpha Old');
    }

    public function test_other_roles_cannot_use_the_distributor_transfer_and_distributor_cannot_use_admin_transfer(): void
    {
        $a = $this->device('111', $this->distA);
        foreach (['ADMIN', 'DEALER', 'FINANCE', 'TECHNICIAN', 'SUPPLIER'] as $role) {
            $this->as($this->login($role, strtolower($role) . '_u', $this->distA));
            $this->get(route('distributor.transfer'))->assertForbidden();
            $this->post(route('distributor.transfer.store'), ['dealer_id' => $this->dealerA1, 'device_ids' => [$a]])->assertForbidden();
        }
        $this->assertSame($this->distA, $this->holder($a));

        $company = $this->device('999', null);
        $this->as($this->login('DISTRIBUTOR', 'dist_a', $this->distA));
        $this->post(route('admin.stock_transfer.store'), ['device_category' => 'GT06', 'dealer_id' => $this->dealerA1, 'sim_numbers' => ['SIM-999']])->assertForbidden();
        $this->assertNull($this->holder($company));
    }

    // ---- admin > distributor / dealer ------------------------------------------------------------------

    public function test_admin_transfers_to_a_distributor_and_to_a_dealer_under_one_and_the_company_is_the_sender(): void
    {
        $d1 = $this->device('111', null);
        $d2 = $this->device('222', null);
        $this->as($this->login('ADMIN', 'boss'));

        $this->post(route('admin.stock_transfer.store'), ['device_category' => 'GT06', 'dealer_id' => $this->distA, 'sim_numbers' => ['SIM-111']])
            ->assertSessionHasNoErrors();
        $this->post(route('admin.stock_transfer.store'), ['device_category' => 'GT06', 'dealer_id' => $this->dealerA1, 'sim_numbers' => ['SIM-222']])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->distA, $this->holder($d1));
        $this->assertSame($this->dealerA1, $this->holder($d2)); // direct to a dealer that has a distributor
        $this->assertSame(2, DealerTransferLedger::whereNull('from_dealer_id')->count());
        $this->assertSame(2, DealerTransferItem::count());
        $this->assertSame(2, AuditLog::where('action', 'stock.transferred')->count());
        $log = AuditLog::where('meta->to_dealer_id', $this->dealerA1)->first();
        $this->assertSame($this->distA, (int) $log->meta['to_distributor_id']);
    }

    public function test_admin_cannot_transfer_to_an_archived_dealer(): void
    {
        $d = $this->device('111', null);
        $this->as($this->login('ADMIN', 'boss'));

        $this->post(route('admin.stock_transfer.store'), ['device_category' => 'GT06', 'dealer_id' => $this->archivedA, 'sim_numbers' => ['SIM-111']])
            ->assertSessionHasErrors('dealer_id');
        $this->assertNull($this->holder($d));
    }

    // ---- locking --------------------------------------------------------------------------------------

    private function adminSends(string $imei, int $to): DealerTransferLedger
    {
        $this->as($this->login('ADMIN', 'boss'));
        $this->post(route('admin.stock_transfer.store'), ['device_category' => 'GT06', 'dealer_id' => $to, 'sim_numbers' => ['SIM-' . $imei]])
            ->assertSessionHasNoErrors();

        return DealerTransferLedger::latest('id')->first();
    }

    public function test_untouched_admin_transfer_can_still_be_edited_and_its_items_follow(): void
    {
        $this->device('111', null);
        $this->device('222', null);
        $ledger = $this->adminSends('111', $this->distA);
        $this->assertFalse($ledger->isLocked());

        $this->put(route('admin.stock_transfer.update', $ledger), ['dealer_id' => $this->distA, 'sim_numbers' => ['SIM-222']])
            ->assertSessionHasNoErrors();

        $this->assertSame(['222'], DealerTransferItem::where('ledger_id', $ledger->id)->pluck('imei_number')->all());
        $this->assertSame(1, (int) $ledger->fresh()->quantity);
    }

    public function test_untouched_admin_transfer_can_be_removed_from_history(): void
    {
        $this->device('111', null);
        $ledger = $this->adminSends('111', $this->distA);

        $this->delete(route('admin.stock_transfer.destroy', $ledger))->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSoftDeleted('dealer_transfer_ledgers', ['id' => $ledger->id]);
    }

    public function test_admin_transfer_is_locked_once_the_distributor_passed_a_device_on(): void
    {
        $d = $this->device('111', null);
        $ledger = $this->adminSends('111', $this->distA);

        $this->sendAsDistA(['dealer_id' => $this->dealerA1, 'device_ids' => [$d]])->assertSessionHasNoErrors();
        $this->assertTrue($ledger->fresh()->isLocked());

        $this->as($this->login('ADMIN', 'boss'));
        $this->put(route('admin.stock_transfer.update', $ledger), ['dealer_id' => $this->distB, 'sim_numbers' => ['SIM-111']])
            ->assertSessionHasErrors('transfer_edit');
        $this->delete(route('admin.stock_transfer.destroy', $ledger))->assertSessionHasErrors('transfer');

        $this->assertSame($this->dealerA1, $this->holder($d));
        $this->assertSame((int) $this->distA, (int) $ledger->fresh()->dealer_id);
        $this->assertNull($ledger->fresh()->deleted_at);
        $this->assertSame(1, DealerTransferItem::where('ledger_id', $ledger->id)->count()); // original contents kept
    }

    public function test_admin_transfer_is_locked_when_a_device_was_sold(): void
    {
        $d = $this->device('111', null);
        $ledger = $this->adminSends('111', $this->dealerA1);
        DB::table('setup_shalotrack_devices')->where('shdevice_id', $d)->update(['assigned_customer_id' => 5, 'status' => 'Assigned to Customer']);

        $this->assertTrue($ledger->fresh()->isLocked());
    }

    public function test_distributor_sent_transfers_cannot_be_edited_or_hidden_even_by_admin(): void
    {
        $d = $this->device('111', $this->distA);
        $this->sendAsDistA(['dealer_id' => $this->dealerA1, 'device_ids' => [$d]])->assertSessionHasNoErrors();
        $ledger = DealerTransferLedger::first();

        $this->as($this->login('ADMIN', 'boss'));
        $this->put(route('admin.stock_transfer.update', $ledger), ['dealer_id' => $this->dealerA2, 'sim_numbers' => ['SIM-111']])->assertSessionHasErrors('transfer_edit');
        $this->delete(route('admin.stock_transfer.destroy', $ledger))->assertSessionHasErrors('transfer');
        $this->assertSame($this->dealerA1, $this->holder($d));
        $this->assertNull($ledger->fresh()->deleted_at);
    }

    public function test_the_admin_page_shows_sender_and_receiver(): void
    {
        $d = $this->device('111', $this->distA);
        $this->sendAsDistA(['dealer_id' => $this->dealerA1, 'device_ids' => [$d]]);
        $this->device('222', null);
        $this->adminSends('222', $this->distB);

        $res = $this->get(route('admin.stock_transfer'))->assertOk();
        $res->assertSee('Dist Alpha')->assertSee('Alpha One')->assertSee('Company')
            ->assertSee('Sent by distributor (locked)')->assertSee('(Distributor)')
            ->assertSee('dealer under Dist Alpha');
    }

    public function test_migration_backfills_items_for_existing_transfers(): void
    {
        // Rebuild the pre-migration shape of the two tables, with one legacy transfer.
        Schema::drop('dealer_transfer_items');
        Schema::drop('dealer_transfer_ledgers');
        Schema::create('dealer_transfer_ledgers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('dealer_id');
            $t->string('device_category');
            $t->integer('quantity');
            $t->timestamps();
            $t->softDeletes();
        });
        $ledgerId = DB::table('dealer_transfer_ledgers')->insertGetId(['dealer_id' => $this->dealerA1, 'device_category' => 'GT06', 'quantity' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $a = $this->device('111', $this->dealerA1);
        $b = $this->device('222', $this->dealerA1);
        $this->device('333', $this->dealerA1); // never transferred through a ledger
        DB::table('setup_shalotrack_devices')->whereIn('shdevice_id', [$a, $b])->update(['transfer_id' => $ledgerId]);

        (require base_path('database/migrations/2026_10_21_000002_add_source_and_items_to_dealer_transfers.php'))->up();

        $this->assertSame(['111', '222'], DealerTransferItem::where('ledger_id', $ledgerId)->orderBy('imei_number')->pluck('imei_number')->all());
        $this->assertNull(DB::table('dealer_transfer_ledgers')->where('id', $ledgerId)->value('from_dealer_id')); // legacy = company
    }

    // ---- portal screens (UI step) ---------------------------------------------------------------------

    private function ledger(int $to, ?int $from, int $qty = 1, string $cat = 'GT06', ?string $at = null): DealerTransferLedger
    {
        $l = DealerTransferLedger::create(['dealer_id' => $to, 'from_dealer_id' => $from, 'device_category' => $cat, 'quantity' => $qty]);
        if ($at) {
            DB::table('dealer_transfer_ledgers')->where('id', $l->id)->update(['created_at' => $at]);
        }

        return $l;
    }

    public function test_every_distributor_screen_renders_with_the_shared_shell(): void
    {
        $d = $this->device('111', $this->distA);
        $this->sendAsDistA(['dealer_id' => $this->dealerA1, 'device_ids' => [$d]]);
        $ledger = DealerTransferLedger::first();

        $this->as($this->login('DISTRIBUTOR', 'dist_a', $this->distA));
        foreach ([route('distributor.dashboard'), route('distributor.dealers'), route('distributor.stock'), route('distributor.transfer'),
                  route('distributor.transfers'), route('distributor.transfers.show', $ledger), route('distributor.profile')] as $url) {
            $this->get($url)->assertOk()->assertSee('Distributor Portal')->assertSee('Transfer History')->assertSee('Billing');
        }
    }

    public function test_dashboard_points_out_what_needs_attention_and_links_dealers_to_transfer(): void
    {
        $this->as($this->login('DISTRIBUTOR', 'dist_a', $this->distA));
        $this->get(route('distributor.dashboard'))->assertOk()
            ->assertSee('Needs your attention')->assertSee('no stock')
            ->assertSee(route('distributor.transfer', ['dealer' => $this->dealerA1]), false)
            ->assertDontSee(route('distributor.transfer', ['dealer' => $this->dealerB1]), false);
    }

    public function test_stock_filters_search_category_and_availability(): void
    {
        $this->device('AAA111', $this->distA, 'GT06');
        $this->device('BBB222', $this->distA, 'JM-VL');
        $this->device('CCC333', $this->distA, 'GT06', 'Not Activated');
        $this->device('DDD444', $this->distA, 'GT06', 'Assigned to Customer', 9);
        DB::table('setup_shalotrack_devices')->insert(['imei_number' => 'NOCAT55', 'sim_number' => 'S', 'device_category' => null, 'dealer_id' => $this->distA, 'status' => 'Activated', 'created_at' => now(), 'updated_at' => now()]);
        $this->device('OTHER999', $this->distB, 'GT06');

        $this->as($this->login('DISTRIBUTOR', 'dist_a', $this->distA));

        $this->get(route('distributor.stock', ['q' => 'AAA']))->assertSee('AAA111')->assertDontSee('BBB222');
        $this->get(route('distributor.stock', ['q' => 'SIM-BBB']))->assertSee('BBB222')->assertDontSee('AAA111');
        $this->get(route('distributor.stock', ['category' => 'JM-VL']))->assertSee('BBB222')->assertDontSee('AAA111');
        $this->get(route('distributor.stock', ['category' => 'Uncategorised']))->assertSee('NOCAT55')->assertDontSee('AAA111');
        $this->get(route('distributor.stock', ['availability' => 'available']))
            ->assertSee('AAA111')->assertSee('BBB222')->assertSee('NOCAT55')->assertDontSee('CCC333')->assertDontSee('DDD444');
        $this->get(route('distributor.stock', ['availability' => 'other']))
            ->assertSee('CCC333')->assertSee('DDD444')->assertDontSee('AAA111');
        // wildcard characters are searched literally, never as patterns
        $this->get(route('distributor.stock', ['q' => '%']))->assertOk()->assertDontSee('AAA111');
        // another distributor's device is never reachable by any filter
        $this->assertSame(0, $this->get(route('distributor.stock', ['q' => 'OTHER999']))->assertOk()->viewData('devices')->total());
        $this->get(route('distributor.stock', ['availability' => 'bogus']))->assertSessionHasErrors('availability');
    }

    public function test_history_lists_only_transfers_this_distributor_sent_or_received(): void
    {
        $mineIn   = $this->ledger($this->distA, null, 5, 'GT06');
        $mineOut  = $this->ledger($this->dealerA1, $this->distA, 3, 'JM-VL');
        $otherIn  = $this->ledger($this->distB, null, 7, 'OTHERCAT-IN');
        $otherOut = $this->ledger($this->dealerB1, $this->distB, 2, 'OTHERCAT-OUT');
        $direct   = $this->ledger($this->direct, null, 4, 'DIRECTCAT');
        $this->ledger($this->dealerA2, $this->distA, 1, 'GT06', '2020-01-05 10:00:00');

        $this->as($this->login('DISTRIBUTOR', 'dist_a', $this->distA));

        $res = $this->get(route('distributor.transfers'))->assertOk();
        $res->assertSee('JM-VL')->assertDontSee('OTHERCAT-IN')->assertDontSee('OTHERCAT-OUT')->assertDontSee('DIRECTCAT');
        $this->assertSame(3, (int) $res->viewData('totals')->records);
        $this->assertSame(9, (int) $res->viewData('totals')->devices); // 5 + 3 + 1

        $this->assertSame(2, (int) $this->get(route('distributor.transfers', ['direction' => 'sent']))->viewData('totals')->records);
        $this->assertSame(1, (int) $this->get(route('distributor.transfers', ['direction' => 'received']))->viewData('totals')->records);
        $this->assertSame(1, (int) $this->get(route('distributor.transfers', ['dealer_id' => $this->dealerA2]))->viewData('totals')->records);
        $this->assertSame(1, (int) $this->get(route('distributor.transfers', ['from' => '2020-01-01', 'to' => '2020-01-31']))->viewData('totals')->records);
        // asking for another network's dealer yields nothing, not their data
        $this->assertSame(0, (int) $this->get(route('distributor.transfers', ['dealer_id' => $this->dealerB1]))->viewData('totals')->records);
        $this->get(route('distributor.transfers', ['from' => 'not-a-date']))->assertSessionHasErrors('from');
    }

    public function test_transfer_detail_shows_exact_devices_and_is_private(): void
    {
        $d1 = $this->device('111', $this->distA);
        $d2 = $this->device('222', $this->distA);
        $this->sendAsDistA(['dealer_id' => $this->dealerA1, 'device_ids' => [$d1, $d2]]);
        $mine = DealerTransferLedger::first();

        $otherIn  = $this->ledger($this->distB, null);
        $otherOut = $this->ledger($this->dealerB1, $this->distB);
        $directIn = $this->ledger($this->direct, null);
        foreach ([$otherIn, $otherOut, $directIn] as $l) {
            DealerTransferItem::create(['ledger_id' => $l->id, 'device_id' => 99, 'imei_number' => 'SECRET-' . $l->id, 'sim_number' => 'x']);
        }

        $this->as($this->login('DISTRIBUTOR', 'dist_a', $this->distA));
        $this->get(route('distributor.transfers.show', $mine))->assertOk()
            ->assertSee('111')->assertSee('222')->assertSee('TR-' . str_pad((string) $mine->id, 6, '0', STR_PAD_LEFT))
            ->assertSee('Sent to a dealer');

        foreach ([$otherIn, $otherOut, $directIn] as $l) {
            $this->get(route('distributor.transfers.show', $l))->assertNotFound();
        }
        $this->get(route('distributor.transfers.show', 999999))->assertNotFound();

        // received from the company is visible to its receiver
        $in = $this->ledger($this->distA, null, 1);
        $this->get(route('distributor.transfers.show', $in))->assertOk()->assertSee('Received from the company');

        // other roles cannot open it
        $this->as($this->login('DEALER', 'dealer_u', $this->dealerA1));
        $this->get(route('distributor.transfers.show', $mine))->assertForbidden();
        $this->get(route('distributor.transfers'))->assertForbidden();
    }

    public function test_dealer_preselection_ignores_a_dealer_outside_the_network(): void
    {
        $this->device('111', $this->distA);
        $this->as($this->login('DISTRIBUTOR', 'dist_a', $this->distA));

        $this->assertSame($this->dealerA1, $this->get(route('distributor.transfer', ['dealer' => $this->dealerA1]))->viewData('preselected'));
        foreach ([$this->dealerB1, $this->direct, $this->archivedA, 'x', -1] as $bad) {
            $this->assertNull($this->get(route('distributor.transfer', ['dealer' => $bad]))->viewData('preselected'));
        }
    }

    public function test_names_are_escaped_in_attributes_and_markup(): void
    {
        $evil = 'Evil\' "><script>alert(1)</script>';
        DB::table('dealers')->where('id', $this->dealerA1)->update(['full_name' => $evil]);
        $this->device('111', $this->distA);

        $this->as($this->login('DISTRIBUTOR', 'dist_a', $this->distA));
        foreach ([route('distributor.dealers'), route('distributor.transfer'), route('distributor.dashboard')] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert(1)</script>', $html, $url);
        }
    }
}

