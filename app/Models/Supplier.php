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

    // A Supplier can have many Products, through the supplier_products pivot
    // table, which also carries price and discount for each attachment.
    public function products()
    {
        return $this->belongsToMany(Product::class, 'supplier_products')
                     ->withPivot('price', 'discount')
                     ->withTimestamps();
    }

    // Inverse of Stock::supplier(). This IS the "Supply / Stock History"
    // requirement — every stock-in row received from this supplier already
    // carries device_type_id, stock_in (quantity), and created_at (date)
    // with zero new columns. There is no unit_price / total_amount on
    // `stocks` yet (see ManageStockController) — don't fake those here,
    // the UI layer just labels them "not tracked yet."
    public function stocks()
    {
        return $this->hasMany(Stock::class);
    }
}