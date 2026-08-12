<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SetupShalotrackDevice extends Model
{
    protected $table = 'setup_shalotrack_devices';
    protected $primaryKey = 'shdevice_id';

    protected $fillable = [
        'device_category',
        'imei_number',
        'sim_number',
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
}