<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One device inside one transfer: the permanent record of what a transfer contained. */
class DealerTransferItem extends Model
{
    protected $fillable = ['ledger_id', 'device_id', 'imei_number', 'sim_number'];

    public function ledger()
    {
        return $this->belongsTo(DealerTransferLedger::class, 'ledger_id');
    }

    public function device()
    {
        return $this->belongsTo(SetupShalotrackDevice::class, 'device_id', 'shdevice_id');
    }
}