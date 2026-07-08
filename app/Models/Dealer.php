<?php
// app/Models/Dealer.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Dealer extends Model
{
    protected $fillable = [
        'dealer_status','upper_channel','company_name','contact_person','mobile_no',
        'address','district','country','state','pin_code',
        'commencement_date','email','tax_pan','cst_no','vat_tin','gst_pan',
        'region','area','sales_person','price_group','commission_type','commission_group',
        'credit_amount','credit_days','security_deposit','deposit_date','deliver_to_customer',
        'network','login_id','password',
        'business_entity','full_details_of','owner_name','home_address','qualification','ownership','involvement',
        'payment_modes','profile_photo','copy_of_ma','passport_front','passport_last',
        'status','created_by',
    ];

    protected $casts = [
        'payment_modes' => 'array',
        'deliver_to_customer' => 'boolean',
        'commencement_date' => 'date',
        'deposit_date' => 'date',
    ];

    protected $hidden = ['password'];
}