<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Old rows carried product identity as free text — there's no safe
        // automatic mapping to a device_types row. Export first if you need
        // the history, then this wipes it.
        DB::table('stocks')->truncate();

        Schema::table('stocks', function (Blueprint $table) {
            $table->dropColumn([
                'product_name',
                'product_model',
                'dealer_available_stock',
                'sold_to_customer',
            ]);
        });

        Schema::table('stocks', function (Blueprint $table) {
            $table->foreignId('device_type_id')
                  ->after('id')
                  ->constrained('device_types')
                  ->cascadeOnDelete();

            $table->integer('total_available')->default(0)->after('company_available_stock');
        });
    }

    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_type_id');
            $table->dropColumn('total_available');
            $table->string('product_name')->nullable();
            $table->string('product_model')->nullable();
            $table->integer('dealer_available_stock')->default(0);
            $table->integer('sold_to_customer')->default(0);
        });
    }
};