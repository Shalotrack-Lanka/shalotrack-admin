<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

    // Tells Laravel which columns are allowed to be filled via $request input.
    // Without this, Supplier::create($request->all()) silently does nothing —
    // this is Laravel's protection against someone submitting a field you didn't expect.
    protected $fillable = [
        'name',
        'address',
        'country',
        'state',
        'phone_number',
        'email',
        'website',
        'gstin_number',
        'status',
    ];

    // This defines the relationship: "a Supplier can have many Products,
    // through the supplier_products pivot table, and that pivot table
    // also carries price and discount for each attachment."
    public function products()
    {
        return $this->belongsToMany(Product::class, 'supplier_products')
                     ->withPivot('price', 'discount')
                     ->withTimestamps();
    }
}