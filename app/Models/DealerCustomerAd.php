<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DealerCustomerAd extends Model
{
    use HasFactory;

    protected $table = 'dealer_customer_ads';

    protected $fillable = [
        'dealer_id',
        'name',
        'nic_or_id',
        'contact',
        'no_of_devices',
        'imei_numbers',
        'address',
    ];

    protected $casts = [
    'imei_numbers' => 'array', //  auto cast array to JSON and vice versa 
];

    public function dealer()
    {
        return $this->belongsTo(Dealer::class);
    }
}