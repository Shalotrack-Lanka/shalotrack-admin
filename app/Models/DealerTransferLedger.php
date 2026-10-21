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
        'from_dealer_id',
        'device_category',
        'quantity',
        'created_by_admin_id',
    ];

    /** Receiver. */
    public function dealer()
    {
        return $this->belongsTo(Dealer::class);
    }

    /** Sender: null means the company (admin). */
    public function fromDealer()
    {
        return $this->belongsTo(Dealer::class, 'from_dealer_id');
    }

    public function devices()
    {
        return $this->hasMany(SetupShalotrackDevice::class, 'transfer_id');
    }

    public function items()
    {
        return $this->hasMany(DealerTransferItem::class, 'ledger_id');
    }

    /** "Company" or the sending distributor's name, for screens and reports. */
    public function senderName(): string
    {
        return $this->from_dealer_id ? ($this->fromDealer->full_name ?? 'Distributor #' . $this->from_dealer_id) : 'Company';
    }

    /**
     * True once any device of this transfer has left the receiver (passed on, sold, bound) or the transfer was
     * sent by a distributor. A locked transfer can no longer be edited or hidden: its history is what later
     * invoices and margins stand on. Corrections then go through a separate, audited return.
     */
    public function isLocked(): bool
    {
        if ($this->from_dealer_id !== null) {
            return true;
        }

        // Once a transfer is on an invoice, the invoice stands on it: corrections go through a credit note.
        if (\Illuminate\Support\Facades\Schema::hasTable('invoice_lines')
            && \Illuminate\Support\Facades\DB::table('invoice_lines')->where('transfer_ledger_id', $this->id)->exists()) {
            return true;
        }

        $moved = $this->items()
            ->join('setup_shalotrack_devices as d', 'd.shdevice_id', '=', 'dealer_transfer_items.device_id')
            ->where(function ($q) {
                $q->where('d.dealer_id', '!=', $this->dealer_id)
                    ->orWhereNull('d.dealer_id')
                    ->orWhere('d.transfer_id', '!=', $this->id)
                    ->orWhereNull('d.transfer_id')
                    ->orWhere(function ($q2) {
                        $q2->whereNotNull('d.assigned_customer_id')->where('d.assigned_customer_id', '>', 0);
                    });
            })
            ->exists();

        return $moved;
    }
}