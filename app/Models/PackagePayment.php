<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One recorded package payment for a device, with the prices and people frozen as they were. */
class PackagePayment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'customer_price'     => 'decimal:2',
        'company_margin'     => 'decimal:2',
        'distributor_margin' => 'decimal:2',
        'retailer_margin'    => 'decimal:2',
        'subscription_start' => 'datetime',
        'subscription_end'   => 'datetime',
        'previous_expiry'    => 'datetime',
        'reversed_at'        => 'datetime',
    ];

    public function entries()
    {
        return $this->hasMany(CommissionEntry::class, 'payment_id');
    }
}