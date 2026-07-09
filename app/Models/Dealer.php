<?php
// app/Models/Dealer.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Dealer extends Model
{
    protected $fillable = [
        'dealer_status','region','country','pin_code',
        'contact_email','tax_pan','cst_no','vat_tin','gst_pan',
        'security_deposit','deposit_date','network','login_id','password',
        'payment_modes','profile_photo','copy_of_ma','passport_front','passport_last',
        'status','created_by',
    ];

    protected $casts = [
        'payment_modes' => 'array',
        'deposit_date' => 'date',
    ];

    protected $hidden = ['password'];
}