<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleAd extends Model
{
    protected $table = 'vehicle_ad';

    protected $primaryKey = 'vehicle_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'vehicle_id',
        'customer_id',
        'customer_name',
        'vehicle_number',
        'chassis_number',
        'engine_number',
        'make',
        'model',
        'year',
        'color',
        'vehicle_type',
        'fuel_type',
        'has_gps_device',
        'imei',
        'last_synced_at',
    ];

    protected $casts = [
        'year'           => 'integer',
        'has_gps_device' => 'boolean',
        'last_synced_at' => 'datetime',
    ];
}