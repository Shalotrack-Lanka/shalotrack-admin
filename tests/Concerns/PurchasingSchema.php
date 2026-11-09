<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tables the supplier / purchasing / stock features touch, for SQLite feature tests. The full migration
 * history cannot build a fresh SQLite database, so the older tables are created here (columns mirror the real
 * migrations) and the NEW tables/columns come from the real migration files, so those are exercised too.
 */
trait PurchasingSchema
{
    protected function buildPurchasingSchema(): void
    {
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
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->text('address')->nullable();
            $t->string('country')->nullable();
            $t->string('state')->nullable();
            $t->string('phone_number')->nullable();
            $t->string('email')->nullable();
            $t->string('website')->nullable();
            $t->string('gstin_number')->nullable();
            $t->string('status')->default('Active');
            $t->timestamps();
        });
        Schema::create('device_types', function (Blueprint $t) {
            $t->id();
            $t->string('device_category');
            $t->string('model');
            $t->string('protocol')->nullable();
            $t->text('features')->nullable();
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('product_name');
            $t->text('description')->nullable();
            $t->unsignedBigInteger('device_type_id')->nullable();
            $t->timestamps();
        });
        Schema::create('supplier_products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('supplier_id');
            $t->unsignedBigInteger('product_id');
            $t->decimal('price', 10, 2)->default(0);
            $t->decimal('discount', 10, 2)->default(0);
            $t->integer('qty')->default(0);
            $t->timestamps();
            $t->unique(['supplier_id', 'product_id']);
        });
        Schema::create('supplier_invoices', function (Blueprint $t) {
            $t->id();
            $t->string('invoice_number')->unique();
            $t->unsignedBigInteger('supplier_id');
            $t->date('invoice_date');
            $t->decimal('grand_total', 12, 2)->default(0);
            $t->timestamps();
        });
        Schema::create('supplier_invoice_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('supplier_invoice_id');
            $t->unsignedBigInteger('product_id');
            $t->string('type');
            $t->integer('order_qty');
            $t->decimal('unit_price', 12, 2);
            $t->decimal('discount', 5, 2)->default(0);
            $t->decimal('face_value', 12, 2)->default(0);
            $t->decimal('net_amount', 12, 2);
            $t->timestamps();
        });
        Schema::create('stocks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('device_type_id');
            $t->string('device_category_type')->nullable();
            $t->integer('company_available_stock')->default(0);
            $t->timestamps();
        });
        Schema::create('stock_transfer_ledgers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('stock_id');
            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->string('supplier')->nullable();
            $t->string('device_category_type')->nullable();
            $t->integer('stock_in');
            $t->text('description')->nullable();
            $t->date('stocked_in_date');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('features', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });
        Schema::create('sims', function (Blueprint $t) {
            $t->id();
            $t->string('sim_number', 10)->unique();
            $t->string('sim_type');
            $t->string('imsi', 15)->unique();
            $t->string('iccid', 20)->unique();
            $t->boolean('activation_required')->default(false);
            $t->string('sim_status')->default('Not Activated');
            $t->timestamps();
        });
        Schema::create('setup_shalotrack_devices', function (Blueprint $t) {
            $t->id('shdevice_id');
            $t->string('device_category');
            $t->string('imei_number')->unique();
            $t->string('sim_number')->nullable();
            $t->string('iccid', 20)->nullable()->unique();
            $t->string('imsi', 15)->nullable();
            $t->string('registered_by')->nullable();
            $t->string('intake_source', 16)->nullable();
            $t->string('status')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->timestamp('canceled_date')->nullable();
            $t->unsignedBigInteger('device_type_id')->nullable();
            $t->unsignedBigInteger('dealer_id')->nullable();
            $t->unsignedBigInteger('transfer_id')->nullable();
            $t->timestamp('allocated_at')->nullable();
            $t->timestamps();
        });
        Schema::create('device_prices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('device_type_id')->unique();
            $t->decimal('landing_cost', 14, 2);
            $t->decimal('company_margin', 14, 2);
            $t->decimal('distributor_margin', 14, 2);
            $t->decimal('retailer_margin', 14, 2);
            $t->date('effective_from');
            $t->string('updated_by', 64)->nullable();
            $t->timestamps();
        });

        // The new tables and columns, from the real migration files.
        foreach (['2026_11_09_000002_create_supplier_delivery_tables', '2026_11_09_000003_add_stock_link_to_supplier_deliveries', '2026_11_09_000004_link_purchase_orders_deliveries_and_sim_numbers', '2026_11_09_000006_create_supplier_payments_table'] as $m) {
            (require database_path("migrations/{$m}.php"))->up();
        }
    }
}
