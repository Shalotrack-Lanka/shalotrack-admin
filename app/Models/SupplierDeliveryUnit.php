<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One scanned IMEI (device) or ICCID (SIM, with its 10-digit SIM number) that arrived on a supplier delivery. */
class SupplierDeliveryUnit extends Model
{
    protected $fillable = ['supplier_delivery_id', 'kind', 'identifier', 'sim_number', 'registered_at', 'registered_ref'];

    protected $casts = ['registered_at' => 'datetime'];

    public function delivery()
    {
        return $this->belongsTo(SupplierDelivery::class, 'supplier_delivery_id');
    }
}
