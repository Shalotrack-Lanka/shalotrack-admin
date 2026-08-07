<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_name',
        'description',
        'device_type_id',
    ];

    // The inverse of Supplier::products() — "which suppliers offer this product"
    public function suppliers()
    {
        return $this->belongsToMany(Supplier::class, 'supplier_products')
                     ->withPivot('price', 'discount')
                     ->withTimestamps();
    }

    // Nullable — only set when this product represents a physical,
    // stock-tracked device. Products like SIM packages stay unlinked
    // and never touch the stocks table.
    public function deviceType()
    {
        return $this->belongsTo(DeviceType::class);
    }
}