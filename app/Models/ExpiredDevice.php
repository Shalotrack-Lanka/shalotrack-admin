<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpiredDevice extends Model
{
    protected $table = 'expired_devices';
    protected $primaryKey = 'expired_device_id';

    protected $fillable = [
        'vehicle_id',
        'customer_id',
        'customer_name',
        'vehicle_number',
        'model',
        'has_gps_device',
        'imei_number',
        'sim_number',
        'device_category',
        'payment_status',
        'subscription_model',
        'subscription_start_date',
        'subscription_end_date',
        'expired_date',
        'bank_invoice',
        'bank_slip',
        'status',
    ];

    protected $casts = [
        'has_gps_device'          => 'boolean',
        'subscription_start_date' => 'datetime',
        'subscription_end_date'   => 'datetime',
        'expired_date'            => 'datetime',
    ];
}
