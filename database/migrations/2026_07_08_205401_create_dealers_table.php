<?php
// database/migrations/2026_07_08_205401_create_dealers_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dealers', function (Blueprint $table) {
            $table->id();

            // Basics
            $table->string('full_name');
            $table->text('address')->nullable();
            $table->string('qualification')->nullable();
            $table->string('dealer_status');
            $table->string('region');
            $table->string('country')->default('Sri Lanka');
            $table->string('pin_code')->nullable();

            // Compliance & Access
            $table->string('contact_email')->nullable()->unique();
            $table->string('tax_pan')->nullable();
            $table->string('cst_no')->nullable();
            $table->string('vat_tin')->nullable();
            $table->string('gst_pan')->nullable();
            $table->decimal('security_deposit', 12, 2)->default(0);
            $table->date('deposit_date')->nullable();
            $table->string('network')->nullable();
            $table->string('login_id')->nullable();
            $table->string('password')->nullable(); // hashed before save

            // Documents & Payment
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