<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use ImmutableRecord;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'issue_date' => 'date',
        'due_date'   => 'date',
        'snapshot'   => 'array',
        'created_at' => 'datetime',
    ];

    public function lines()
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function creditNotes()
    {
        return $this->hasMany(CreditNote::class);
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function buyer()
    {
        return $this->belongsTo(Dealer::class, 'buyer_dealer_id');
    }
}
