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
    ];

    // The inverse of Supplier::products() — "which suppliers offer this product"
    public function suppliers()
    {
        return $this->belongsToMany(Supplier::class, 'supplier_products')
                     ->withPivot('price', 'discount')
                     ->withTimestamps();
    }
}