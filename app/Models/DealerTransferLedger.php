<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DealerTransferLedger extends Model
{
    // "Delete" hides the row but keeps it recoverable (history is never erased).
    use SoftDeletes;

    protected $fillable = [
        'dealer_id',
        'device_category',
        'quantity',
    ];

    public function dealer()
    {
        return $this->belongsTo(Dealer::class);
    }

    public function devices()
    {
        return $this->hasMany(SetupShalotrackDevice::class, 'transfer_id');
    }
}