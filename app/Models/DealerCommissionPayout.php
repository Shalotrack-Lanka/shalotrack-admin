<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A monthly commission statement that has been paid out. Immutable by design: there is no edit or
 * delete path anywhere in the app. See the migration for how it claims ledger rows.
 */
class DealerCommissionPayout extends Model
{
    protected $fillable = [
        'dealer_id', 'period', 'period_end', 'payable_after_days',
        'gross_amount', 'clawback_amount', 'net_amount', 'line_count',
        'reference', 'paid_on', 'paid_by_admin_id', 'paid_by_name', 'notes',
    ];

    protected $casts = [
        'period_end'      => 'datetime',
        'paid_on'         => 'date',
        'gross_amount'    => 'decimal:2',
        'clawback_amount' => 'decimal:2',
        'net_amount'      => 'decimal:2',
    ];

    public function dealer()
    {
        return $this->belongsTo(Dealer::class);
    }
}