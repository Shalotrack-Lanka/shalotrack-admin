<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DealerCommissionRate extends Model
{
    protected $fillable = [
        'dealer_status',
        'device_type_id',
        'rate',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
    ];

    public function deviceType()
    {
        return $this->belongsTo(DeviceType::class);
    }
}