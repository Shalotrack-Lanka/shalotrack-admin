<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * must_change_password: set when an administrator creates an account or sets a temporary
     * password; the person is held on the "choose a new password" screen until they do.
     * password_changed_at: when the person last chose their own password (shown on the profile page).
     */
    public function up(): void
    {
        Schema::table('Admins', function (Blueprint $table) {
            if (! Schema::hasColumn('Admins', 'must_change_password')) {
                $table->boolean('must_change_password')->default(false);
            }
            if (! Schema::hasColumn('Admins', 'password_changed_at')) {
                $table->timestamp('password_changed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('Admins', function (Blueprint $table) {
            if (Schema::hasColumn('Admins', 'must_change_password')) {
                $table->dropColumn('must_change_password');
            }
            if (Schema::hasColumn('Admins', 'password_changed_at')) {
                $table->dropColumn('password_changed_at');
            }
        });
    }
};