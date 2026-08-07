<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierInvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_invoice_id',
        'product_id',
        'type',
        'order_qty',
        'unit_price',
        'discount',
        'face_value',
        'net_amount',
    ];

    protected $casts = [
        'unit_price'  => 'decimal:2',
        'discount'    => 'decimal:2',
        'face_value'  => 'decimal:2',
        'net_amount'  => 'decimal:2',
    ];

    public function invoice()
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}