<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceLine extends Model
{
    use ImmutableRecord;

    public const UPDATED_AT = null;

    protected $guarded = [];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function transfer()
    {
        return $this->belongsTo(DealerTransferLedger::class, 'transfer_ledger_id')->withTrashed();
    }
}
