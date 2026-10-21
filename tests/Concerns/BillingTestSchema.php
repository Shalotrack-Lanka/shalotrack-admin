<?php

namespace Tests\Concerns;

use App\Models\Admin;
use App\Models\BillingParty;
use App\Models\DealerTransferLedger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Builds just enough of the schema (plus the real billing migration) for billing tests on sqlite. */
trait BillingTestSchema
{
    protected function buildBillingSchema(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // only ever run against a throwaway database (see BillingPostgresGuardsTest)
            $this->assertSame('yes-drop-billing-tables', getenv('BILLING_PG_GUARD_TEST'), 'Refusing to drop tables on Postgres.');
            DB::unprepared('DROP SCHEMA public CASCADE; CREATE SCHEMA public');
        }

        Schema::create('Admins', function (Blueprint $t) {
            $t->string('admin_id')->primary()->default(DB::raw(DB::getDriverName() === 'pgsql' ? '(gen_random_uuid()::text)' : '(lower(hex(randomblob(16))))'));
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
            $t->string('country')->nullable();
            $t->string('pin_code')->nullable();
            $t->string('contact_email')->nullable();
            $t->string('vat_tin')->nullable();
            $t->string('tax_pan')->nullable();
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
        });
        Schema::create('setup_shalotrack_devices', function (Blueprint $t) {
            $t->id('shdevice_id');
            $t->string('imei_number')->nullable();
            $t->string('sim_number')->nullable();
            $t->unsignedBigInteger('dealer_id')->nullable();
            $t->unsignedBigInteger('assigned_customer_id')->nullable();
            $t->unsignedBigInteger('transfer_id')->nullable();
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

        (include database_path('migrations/2026_10_22_000001_create_wholesale_billing_tables.php'))->up();
        (include database_path('migrations/2026_10_22_000002_add_postgres_guards_to_billing_tables.php'))->up();   // no-op on sqlite
    }

    protected function seedCompany(array $over = []): BillingParty
    {
        return BillingParty::create($over + [
            'kind' => 'company', 'legal_name' => 'Shalotrack Lanka (Pvt) Ltd', 'address' => '1 Galle Road, Colombo',
            'tax_number' => 'TIN-123', 'bank_details' => 'Bank X 000111',
        ]);
    }

    protected function makeDealer(string $name, string $type = 'retailer', ?int $parent = null): int
    {
        return DB::table('dealers')->insertGetId([
            'full_name' => $name, 'dealer_status' => $type, 'distributor_id' => $parent, 'region' => 'western',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function makeLedger(int $toDealer, string $category = 'GT06', int $qty = 2, ?int $from = null): DealerTransferLedger
    {
        $ledger = DealerTransferLedger::create([
            'dealer_id' => $toDealer, 'from_dealer_id' => $from, 'device_category' => $category, 'quantity' => $qty,
        ]);
        for ($i = 1; $i <= $qty; $i++) {
            DB::table('dealer_transfer_items')->insert([
                'ledger_id' => $ledger->id, 'device_id' => $ledger->id * 100 + $i,
                'imei_number' => '8681' . str_pad((string) ($ledger->id * 100 + $i), 8, '0', STR_PAD_LEFT),
                'sim_number' => 'SIM' . ($ledger->id * 100 + $i), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $ledger;
    }

    protected function admin(string $name = 'boss', string $role = 'ADMIN', ?int $dealerId = null): Admin
    {
        return Admin::forceCreate(['admin_id' => 'adm-' . $name, 'username' => $name, 'full_name' => ucfirst($name), 'role' => $role, 'status' => 'ACTIVE', 'dealer_id' => $dealerId]);
    }
}
