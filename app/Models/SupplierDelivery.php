<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One delivery received from a supplier (devices or SIMs). See the create_supplier_delivery_tables migration. */
class SupplierDelivery extends Model
{
    public const KIND_DEVICE = 'DEVICE';
    public const KIND_SIM = 'SIM';

    protected $fillable = ['supplier_id', 'kind', 'device_type_id', 'reference', 'received_on', 'note', 'received_by'];

    protected $casts = ['received_on' => 'date'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function deviceType()
    {
        return $this->belongsTo(DeviceType::class);
    }

    public function units()
    {
        return $this->hasMany(SupplierDeliveryUnit::class);
    }
}
