<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A payment received. Never deleted; only its status (and the approval / reversal columns) may move.
 */
class PaymentReceipt extends Model
{
    public const UPDATED_AT = null;

    public const PENDING = 'pending';
    public const VALID = 'valid';
    public const REJECTED = 'rejected';
    public const REVERSED = 'reversed';

    public const METHODS = [
        'bank_transfer' => 'Bank transfer',
        'cash'          => 'Cash',
        'cheque'        => 'Cheque',
        'online'        => 'Online payment',
    ];

    private const MUTABLE = [
        'status', 'approved_by', 'approved_by_name', 'approved_at', 'rejection_reason',
        'reversed_at', 'reversed_by', 'reversal_reason',
    ];

    protected $guarded = [];

    protected $casts = [
        'received_date' => 'date',
        'approved_at'   => 'datetime',
        'reversed_at'   => 'datetime',
        'snapshot'      => 'array',
        'created_at'    => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $r) {
            $illegal = array_diff(array_keys($r->getDirty()), self::MUTABLE);
            if ($illegal !== []) {
                throw new LogicException('Only the status of a payment receipt can change.');
            }
        });
        static::deleting(function () {
            throw new LogicException('A payment receipt cannot be deleted; reverse it instead.');
        });
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class, 'receipt_id');
    }

    public function buyer()
    {
        return $this->belongsTo(Dealer::class, 'buyer_dealer_id');
    }
}
