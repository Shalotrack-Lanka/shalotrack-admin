<?php

namespace App\Models;

use App\Enums\TechnicianJobStatus;
use App\Enums\TechnicianJobType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicianJob extends Model
{
    protected $fillable = [
        'job_type', 'status', 'vehicle_id', 'vehicle_number', 'customer_id', 'customer_name', 'imei_number',
        'technician_id', 'scheduled_for', 'time_slot', 'location_note', 'instructions',
        'completed_on', 'completion_notes', 'cancel_reason', 'created_by', 'decided_by',
    ];

    protected $casts = [
        'job_type'      => TechnicianJobType::class,
        'status'        => TechnicianJobStatus::class,
        'scheduled_for' => 'date',
        'completed_on'  => 'date',
    ];

    public const TIME_SLOTS = [
        'morning'   => 'Morning',
        'afternoon' => 'Afternoon',
        'evening'   => 'Evening',
    ];

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    /** Still open and its day has passed (Sri Lanka calendar). */
    public function isOverdue(): bool
    {
        return $this->status === TechnicianJobStatus::Scheduled
            && $this->scheduled_for->format('Y-m-d') < now()->local()->toDateString();
    }
}