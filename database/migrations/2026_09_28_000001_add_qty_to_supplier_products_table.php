<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The Supplier model's products() relation has always declared
// ->withPivot('price', 'qty'), and both the Add Supplier and Edit
// Supplier forms (supplier_management.blade.php) collect a qty per
// product and use it for the on-screen total-price calculation. But
// no migration in this repo's history
// (2026_07_06_000003_create_supplier_products_table only defines
// `price` and `discount`) ever added a `qty` column.
//
// Running this against the real production database
// (aws-1-ap-southeast-1.pooler.supabase.com) failed with "column qty
// already exists" -- meaning qty was added directly to production at
// some point, outside of Git/migration history entirely. So the app
// code and the live column already agree; it's the migration history
// itself that's out of sync with reality, on this environment at
// least. Guarding with hasColumn() makes this migration a no-op
// wherever qty already exists (prod) while still creating it on any
// fresh database that only has what's in this repo's migrations
// (new dev setups, CI, a rebuilt staging DB) -- otherwise the next
// person who runs `migrate` from scratch gets the exact fatal
// "column does not exist" error this was meant to prevent.
//
// Worth chasing separately: find out how/when qty was added to prod
// outside of a migration, since that's a process gap, not just a
// missing file.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('supplier_products', 'qty')) {
            return;
        }

        Schema::table('supplier_products', function (Blueprint $table) {
            $table->integer('qty')->default(0)->after('price');
        });
    }

    public function down(): void
    {
        // Intentionally not dropping qty here: on production this
        // migration never created the column (it already existed), so
        // rolling back must not destroy real data other code depends
        // on. Only databases where THIS migration actually created the
        // column should ever lose it, and there's no reliable way to
        // tell those apart after the fact -- so this is a deliberate
        // no-op. Drop the column by hand if you're certain it's safe.
    }
};