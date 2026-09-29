<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DealerCommissionLedger extends Model
{
    // FIX: migration created this table SINGULAR (dealer_commission_ledger).
    // Eloquent's default guess from this class name is the pluralized
    // "dealer_commission_ledgers", which doesn't exist -- caused a live
    // "relation does not exist" 500 on the dealer dashboard. Explicit
    // $table so the model and the actual schema can never drift again.
    protected $table = 'dealer_commission_ledger';

    protected $fillable = [
        'dealer_id',
        'shdevice_id',
        'device_type_id',
        'dealer_status_at_time',
        'rate_applied',
        'amount',
        'entry_type',
        'reason',
        'reversed_at',
    ];

    protected $casts = [
        'rate_applied' => 'decimal:2',
        'amount'       => 'decimal:2',
        'reversed_at'  => 'datetime',
    ];

    public function dealer()
    {
        return $this->belongsTo(Dealer::class);
    }

    public function device()
    {
        return $this->belongsTo(SetupShalotrackDevice::class, 'shdevice_id', 'shdevice_id');
    }
}