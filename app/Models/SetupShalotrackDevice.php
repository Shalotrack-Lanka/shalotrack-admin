<?php

namespace App\Models;

use App\Enums\DeviceStatus;
use Illuminate\Database\Eloquent\Model;

class SetupShalotrackDevice extends Model
{
    protected $table = 'setup_shalotrack_devices';
    protected $primaryKey = 'shdevice_id';

    protected $fillable = [
        'device_category',
        'imei_number',
        'sim_number',
        'iccid',
        'imsi',
        'registered_by',
        'intake_source',
        'status',
        'cancel_reason',
        'canceled_date',
        'dealer_id',
        'device_type_id',
        'allocated_at',
        'transfer_id',
    ];

    protected $casts = [
        'canceled_date' => 'datetime',
        'allocated_at'  => 'datetime',
    ];

    /**
     * Devices a dealer can actually hand to a customer: theirs, not yet bound to
     * a customer, and still 'Not Activated'. The assign actions require exactly
     * that status, so every dealer stock list and count MUST use this scope;
     * a looser filter shows devices (e.g. ones an admin already Activated) with an
     * Assign button that can only fail.
     */
    public function scopeAvailableForDealer($query, $dealerId)
    {
        return $query->where('dealer_id', $dealerId)
            ->where(function ($q) {
                $q->whereNull('assigned_customer_id')->orWhere('assigned_customer_id', 0);
            })
            ->where('status', DeviceStatus::NotActivated->value);
    }

    public function dealer()
    {
        return $this->belongsTo(Dealer::class);
    }

    public function deviceType()
    {
        return $this->belongsTo(DeviceType::class, 'device_type_id');
    }

    public function transferLedger()
    {
        return $this->belongsTo(DealerTransferLedger::class, 'transfer_id');
    }

        public function assignedCustomer()
    {
        return $this->belongsTo(DealerCustomerAd::class, 'assigned_customer_id', 'id');
    }
}