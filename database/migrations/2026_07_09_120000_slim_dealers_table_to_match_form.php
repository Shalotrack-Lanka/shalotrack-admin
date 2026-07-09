<?php
// database/migrations/2026_07_09_120000_slim_dealers_table_to_match_form.php
//
// This is a new, additive migration on purpose — it does NOT touch
// 2026_07_08_205401_create_dealers_table.php, and it does NOT use
// migrate:rollback. It only runs `up()` once via `php artisan migrate`,
// which only executes pending migrations and cannot touch any other
// table's batch. This is what caused the customer_ad accident last time.
//
// Every operation is guarded with Schema::hasColumn()/hasTable() checks,
// so this migration is safe to run whether your dealers table is still
// in the old, bloated shape (current production state) or already matches
// the new create_dealers_table.php (e.g. on a fresh install elsewhere).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add full_name if it isn't there yet.
        if (Schema::hasTable('dealers') && !Schema::hasColumn('dealers', 'full_name')) {
            Schema::table('dealers', function (Blueprint $table) {
                $table->string('full_name')->nullable()->after('id');
            });
        }

        // 2. Rename email -> contact_email (only if old name still exists).
        //    NOTE: renameColumn requires doctrine/dbal on Laravel < 11.
        //    If this line throws a "Class Doctrine\DBAL... not found" error,
        //    run: composer require doctrine/dbal
        if (Schema::hasColumn('dealers', 'email') && !Schema::hasColumn('dealers', 'contact_email')) {
            Schema::table('dealers', function (Blueprint $table) {
                $table->renameColumn('email', 'contact_email');
            });
        }

        // 3. Drop every column tied to fields that are no longer on the form.
        //    address and qualification are NOT in this list — they already
        //    exist in the live table and are reused by the current form.
        $columnsToDrop = array_filter([
            'upper_channel', 'company_name', 'contact_person', 'mobile_no',
            'district', 'state', 'commencement_date', 'area', 'sales_person',
            'price_group', 'commission_type', 'commission_group',
            'credit_amount', 'credit_days', 'deliver_to_customer',
            'business_entity', 'full_details_of', 'owner_name', 'home_address',
            'ownership', 'involvement',
        ], fn ($col) => Schema::hasColumn('dealers', $col));

        if (!empty($columnsToDrop)) {
            Schema::table('dealers', function (Blueprint $table) use ($columnsToDrop) {
                $table->dropColumn($columnsToDrop);
            });
        }
    }

    public function down(): void
    {
        // Dropped column data is gone — this restores structure only, not data.
        if (Schema::hasColumn('dealers', 'contact_email') && !Schema::hasColumn('dealers', 'email')) {
            Schema::table('dealers', function (Blueprint $table) {
                $table->renameColumn('contact_email', 'email');
            });
        }

        if (Schema::hasColumn('dealers', 'full_name')) {
            Schema::table('dealers', function (Blueprint $table) {
                $table->dropColumn('full_name');
            });
        }

        Schema::table('dealers', function (Blueprint $table) {
            $table->string('upper_channel')->nullable();
            $table->string('company_name')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('mobile_no')->nullable();
            $table->string('district')->nullable();
            $table->string('state')->nullable();
            $table->date('commencement_date')->nullable();
            $table->string('area')->nullable();
            $table->string('sales_person')->nullable();
            $table->string('price_group')->nullable();
            $table->string('commission_type')->nullable();
            $table->string('commission_group')->nullable();
            $table->decimal('credit_amount', 12, 2)->default(0);
            $table->integer('credit_days')->default(0);
            $table->boolean('deliver_to_customer')->default(false);
            $table->string('business_entity')->nullable();
            $table->string('full_details_of')->nullable();
            $table->string('owner_name')->nullable();
            $table->text('home_address')->nullable();
            $table->string('ownership')->nullable();
            $table->string('involvement')->nullable();
        });
    }
};