<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who did what, to what, and when, for money, device and account actions.
     *
     * Append-only as far as the application is concerned: the model refuses update/delete, and the
     * only thing that ever removes rows is the scheduled `audit:prune` (default: older than 12 months).
     *
     * actor_* are SNAPSHOTS (id, display name, role at the time), not foreign keys: an admin or dealer
     * login can be renamed or removed later and the trail must still say who it was.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at');

            $table->string('actor_id', 64)->nullable();     // Admins.admin_id; null for the system / console
            $table->string('actor_name', 150)->nullable();
            $table->string('actor_role', 20)->nullable();

            $table->string('category', 20);                 // money | device | account
            $table->string('action', 60);                   // e.g. subscription.updated

            $table->string('subject_type', 40)->nullable(); // device | vehicle | dealer | payout | rate | admin
            $table->string('subject_id', 100)->nullable();
            $table->string('subject_label', 150)->nullable(); // IMEI / vehicle number / dealer name, as at the time

            $table->jsonb('changes')->nullable();           // {field: {from, to}}
            $table->jsonb('meta')->nullable();              // anything else worth knowing (never secrets)

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 200)->nullable();

            $table->index('occurred_at');
            $table->index(['category', 'occurred_at']);
            $table->index('action');
            $table->index('actor_id');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};