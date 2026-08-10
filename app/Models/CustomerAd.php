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
        'cus_status',
        'last_synced_at',
    ];

    protected $casts = [
        'vehicle_count'            => 'integer',
        'source_account_status'    => 'integer',
        'last_synced_at'           => 'datetime',
    ];
}
