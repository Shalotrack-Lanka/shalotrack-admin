<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
       Schema::create('stocks', function (Blueprint $table) {
            $table->id(); // Primary Key (ID එක)
            $table->string('product_name'); // Product එකේ නම (e.g., Letstrack Basic)
            $table->string('product_model'); // Model එකේ නම (e.g., Letstrack Basic Series)
            $table->integer('stock_in')->default(0); // මුළු Stock ප්‍රමාණය
            $table->integer('company_available_stock')->default(0); // සමාගම සතුව ඇති Stock ප්‍රමාණය
            $table->integer('dealer_available_stock')->default(0); // ඩීලර්ලා සතුව ඇති Stock ප්‍රමාණය
            $table->integer('sold_to_customer')->default(0); // පාරිභෝගිකයින්ට විකුණා ඇති ප්‍රමාණය
            $table->timestamps(); // Created_at සහ Updated_at (දිනය සහ වේලාව සටහන් වීමට)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
