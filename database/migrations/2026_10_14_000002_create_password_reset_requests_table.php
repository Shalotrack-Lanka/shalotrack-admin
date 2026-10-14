<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Forgot password" without email: the person asks on the login page, an administrator sees the
     * request in the portal and sets a temporary password. One row per request; only for real, active
     * accounts, so the table cannot be filled with made-up usernames.
     */
    public function up(): void
    {
        if (Schema::hasTable('password_reset_requests')) {
            return;
        }

        Schema::create('password_reset_requests', function (Blueprint $table) {
            $table->id();
            $table->string('admin_id', 64)->index();
            $table->string('username', 100);
            $table->string('status', 12)->default('PENDING')->index(); // PENDING | DONE | DISMISSED
            $table->string('requested_ip', 45)->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->string('resolved_by', 64)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_requests');
    }
};