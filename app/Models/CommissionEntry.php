<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One commission line (earned or clawback) for a dealer or distributor, tied to a package payment. */
class CommissionEntry extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'amount'      => 'decimal:2',
        'occurred_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    public function payment()
    {
        return $this->belongsTo(PackagePayment::class, 'payment_id');
    }
}