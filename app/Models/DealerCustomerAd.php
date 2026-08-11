<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DealerCustomerAd extends Model
{
    use HasFactory;

    protected $table = 'dealer_customer_ads';

    protected $fillable = [
        'dealer_id', // dealer_id එක mass assignment වලට allow කළා
        'name',
        'nic_or_id',
        'contact',
        'no_of_devices',
        'address',
    ];

    public function dealer()
    {
        return $this->belongsTo(Dealer::class);
    }
}