<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DealerTransferLedger extends Model
{
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
