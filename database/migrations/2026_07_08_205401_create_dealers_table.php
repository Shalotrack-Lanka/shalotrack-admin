<?php
// database/migrations/xxxx_xx_xx_create_dealers_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dealers', function (Blueprint $table) {
            $table->id();

            // Step 1
            $table->string('dealer_status');
            $table->string('upper_channel')->nullable();
            $table->string('company_name');
            $table->string('contact_person');
            $table->string('mobile_no');
            $table->text('address')->nullable();
            $table->string('district')->nullable();
            $table->string('country')->default('Sri Lanka');
            $table->string('state')->nullable();
            $table->string('pin_code')->nullable();

            // Step 2
            $table->date('commencement_date')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('tax_pan')->nullable();
            $table->string('cst_no')->nullable();
            $table->string('vat_tin')->nullable();
            $table->string('gst_pan')->nullable();
            $table->string('region');
            $table->string('area')->nullable();
            $table->string('sales_person')->nullable();
            $table->string('price_group')->nullable();
            $table->string('commission_type')->default('Global');
            $table->string('commission_group')->nullable();

            // Step 3
            $table->decimal('credit_amount', 12, 2)->default(0);
            $table->integer('credit_days')->default(0);
            $table->decimal('security_deposit', 12, 2)->default(0);
            $table->date('deposit_date')->nullable();
            $table->boolean('deliver_to_customer')->default(false);
            $table->string('network')->nullable();
            $table->string('login_id')->nullable();
            $table->string('password')->nullable(); // hashed before save

            // Step 4
            $table->string('business_entity')->nullable();
            $table->string('full_details_of')->nullable();
            $table->string('owner_name')->nullable();
            $table->text('home_address')->nullable();
            $table->string('qualification')->nullable();
            $table->string('ownership')->nullable();
            $table->string('involvement')->nullable();

            // Step 5
            $table->json('payment_modes')->nullable();
            $table->string('profile_photo')->nullable();
            $table->string('copy_of_ma')->nullable();
            $table->string('passport_front')->nullable();
            $table->string('passport_last')->nullable();

            // Meta
            $table->string('status')->default('active'); // active | archived
            $table->string('created_by')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dealers');
    }
};