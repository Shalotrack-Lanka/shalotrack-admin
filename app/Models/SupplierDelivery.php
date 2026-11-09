<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One delivery received from a supplier (devices or SIMs). See the create_supplier_delivery_tables migration. */
class SupplierDelivery extends Model
{
    public const KIND_DEVICE = 'DEVICE';
    public const KIND_SIM = 'SIM';

    protected $fillable = ['supplier_id', 'kind', 'device_type_id', 'reference', 'received_on', 'note', 'received_by', 'stocked_in_at', 'stock_ledger_id', 'supplier_invoice_id'];

    protected $casts = ['received_on' => 'date', 'stocked_in_at' => 'datetime'];

    /** A device delivery is "open" until it has been added to stock. */
    public function isStockedIn(): bool
    {
        return $this->stocked_in_at !== null;
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function deviceType()
    {
        return $this->belongsTo(DeviceType::class);
    }

    /** The purchase order this delivery was received against (optional). */
    public function purchaseOrder()
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    public function units()
    {
        return $this->hasMany(SupplierDeliveryUnit::class);
    }
}
