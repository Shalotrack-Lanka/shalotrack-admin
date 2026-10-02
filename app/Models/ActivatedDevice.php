<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivatedDevice extends Model
{
    protected $table = 'activated_devices';
    protected $primaryKey = 'activated_device_id';

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
        'bank_invoice',
        'bank_slip',
        'status',
        'installed_on',
        'installed_by',
        'install_notes',
        'original_activated_at',
        'warranty_months',
    ];

    protected $casts = [
        'has_gps_device'          => 'boolean',
        'installed_on'            => 'date',
        'original_activated_at'   => 'datetime',
        'warranty_months'         => 'integer',
        'subscription_start_date' => 'datetime',
        'subscription_end_date'   => 'datetime',
    ];
}