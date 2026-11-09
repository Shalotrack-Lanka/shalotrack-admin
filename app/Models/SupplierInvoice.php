<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_number',
        'supplier_id',
        'invoice_date',
        'grand_total',
    ];

    // status, cancelled_at, cancel_reason and updated_by are set only by App\Services\Supplier\PurchaseOrders.

    protected $casts = [
        'invoice_date' => 'date',
        'grand_total'  => 'decimal:2',
        'cancelled_at' => 'datetime',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items()
    {
        return $this->hasMany(SupplierInvoiceItem::class);
    }

    /** Deliveries received against this purchase order. */
    public function deliveries()
    {
        return $this->hasMany(SupplierDelivery::class, 'supplier_invoice_id');
    }

    public function isCancelled(): bool
    {
        return $this->status === 'CANCELLED';
    }
}