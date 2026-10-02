<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Field technicians and the jobs scheduled for them. Technicians are a list the admin keeps, not
     * login accounts (the portal has no technician role in use). A job is about one vehicle; the
     * vehicle number and customer name are copied at creation, like activated_devices does, so the
     * job sheet still reads correctly if the vehicle is later renamed or removed.
     */
    public function up(): void
    {
        Schema::create('technicians', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('phone', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('technician_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_type', 20);                   // install | repair | replace | removal
            $table->string('status', 20)->default('scheduled'); // scheduled | completed | cancelled

            $table->string('vehicle_id');
            $table->string('vehicle_number')->nullable();
            $table->string('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('imei_number')->nullable();        // the bound device at scheduling time, if any

            // A technician with jobs can be deactivated but never deleted.
            $table->foreignId('technician_id')->constrained('technicians')->restrictOnDelete();
            $table->date('scheduled_for');
            $table->string('time_slot', 20)->nullable();      // morning | afternoon | evening
            $table->string('location_note', 255)->nullable();
            $table->string('instructions', 500)->nullable();

            $table->date('completed_on')->nullable();
            $table->string('completion_notes', 500)->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->string('created_by', 64)->nullable();
            $table->string('decided_by', 64)->nullable();     // who completed or cancelled it
            $table->timestamps();

            $table->index(['status', 'scheduled_for']);
            $table->index('technician_id');
            $table->index('vehicle_id');
        });

        // One open job per vehicle and type, enforced by the database so two admins clicking at once
        // cannot double-book. Partial indexes work on Postgres (production) and SQLite (tests).
        DB::statement("CREATE UNIQUE INDEX technician_jobs_one_open_per_vehicle_type ON technician_jobs (vehicle_id, job_type) WHERE status = 'scheduled'");
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_jobs');
        Schema::dropIfExists('technicians');
    }
};