<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerAd extends Model
{
    protected $table = 'Customer-ad';
    protected $primaryKey = 'customer_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $attributes = [
        'payment_status' => 'not_paid',
    ];

    protected $fillable = [
        'customer_id','full_name','email','phone_number','nic_number','address',
        'profile_image','vehicle_count','source_account_status',
        'imei_number','sim_number','payment_status','device_type',
        'subscription_period','subscription_start_date','subscription_end_date',
        'bank_invoice_path','last_synced_at',
    ];

    protected $casts = [
        'subscription_start_date' => 'date',
        'subscription_end_date'   => 'date',
        'last_synced_at'          => 'datetime',
    ];

    public static function expireOverdueSubscriptions(): int
    {
        return static::where('payment_status', 'paid')
            ->whereDate('subscription_end_date', '<', now()->toDateString())
            ->update([
                'payment_status' => 'not_paid',
                'subscription_start_date' => null,
                'subscription_end_date' => null,
                'updated_at' => now(),
            ]);
    }
}