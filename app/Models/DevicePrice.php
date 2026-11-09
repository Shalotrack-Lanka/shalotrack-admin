<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The four decided amounts of a device type's price chain (see the create_device_prices_table migration).
 * Prices and percentages are calculated by App\Services\DevicePriceChain, never stored here.
 */
class DevicePrice extends Model
{
    protected $fillable = [
        'device_type_id', 'landing_cost', 'company_margin', 'distributor_margin', 'retailer_margin',
        'effective_from', 'updated_by',
    ];

    protected $casts = [
        'landing_cost'       => 'decimal:2',
        'company_margin'     => 'decimal:2',
        'distributor_margin' => 'decimal:2',
        'retailer_margin'    => 'decimal:2',
        'effective_from'     => 'date',
    ];

    public function deviceType()
    {
        return $this->belongsTo(DeviceType::class);
    }
}
