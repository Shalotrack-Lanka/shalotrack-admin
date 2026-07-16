<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerAd extends Model
{
    // The migration created this table with a hyphenated, mixed-case name —
    // unlike every other table in this project. Eloquent's default guess
    // (customer_ads) would be wrong, so this must be explicit.
    protected $table = 'Customer-ad';

    // Primary key is a UUID (customer_id), not an auto-incrementing id.
    protected $primaryKey = 'customer_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'customer_id',
        'full_name',
        'email',
        'phone_number',
        'nic_number',
        'address',
        'profile_image',
        'vehicle_count',
        'source_account_status',
        'imei_number',
        'sim_number',
        'payment_status',
        'device_type',
        'subscription_period',
        'subscription_start_date',
        'subscription_end_date',
        'bank_invoice_path',
        'last_synced_at',
    ];

    protected $casts = [
        'vehicle_count'            => 'integer',
        'source_account_status'    => 'integer',
        'subscription_start_date'  => 'date',
        'subscription_end_date'    => 'date',
        'last_synced_at'           => 'datetime',
    ];

    /**
     * Flip any customer whose subscription has lapsed from 'paid' back to
     * 'not_paid', so they fall into the inactive list on the next query.
     * Assumption: "overdue" means subscription_end_date is in the past
     * AND they're currently marked paid — a customer who was never paid
     * in the first place isn't "overdue," they're just inactive already.
     */
    public static function expireOverdueSubscriptions(): int
    {
        return static::where('payment_status', 'paid')
            ->whereNotNull('subscription_end_date')
            ->where('subscription_end_date', '<', now())
            ->update(['payment_status' => 'not_paid']);
    }
}