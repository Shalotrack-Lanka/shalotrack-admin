<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A payment made to a supplier against one purchase order. Written only by App\Services\Supplier\SupplierPayments. */
class SupplierPayment extends Model
{
    protected $casts = [
        'amount'    => 'decimal:2',
        'paid_on'   => 'date',
        'voided_at' => 'datetime',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
