<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Stock extends Model
{
    use HasFactory;

    // Database එකට එකවර ඇතුළත් කිරීමට ඉඩ දෙන Fields (Mass Assignment)
    protected $fillable = [
        'product_name',
        'product_model',
        'stock_in',
        'company_available_stock',
        'dealer_available_stock',
        'sold_to_customer'
    ];
}