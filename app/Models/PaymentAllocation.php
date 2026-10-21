<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentAllocation extends Model
{
    use ImmutableRecord;

    public const UPDATED_AT = null;

    protected $guarded = [];

    public function receipt()
    {
        return $this->belongsTo(PaymentReceipt::class, 'receipt_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
