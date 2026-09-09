<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerAd extends Model
{
    // The migration created this table with a hyphenated, mixed-case name.
    protected $table = 'Customer-ad';

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
        'vehicle_count'         => 'integer',
        'source_account_status' => 'integer',
        'last_synced_at'        => 'datetime',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Vehicles belonging to this customer (synced from API via VehicleAd).
     */
    public function vehicles()
    {
        return $this->hasMany(VehicleAd::class, 'customer_id', 'customer_id');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Phone normalisation helper
    //
    // The API stores phone in E.164: +94771234567
    // Normalise to 9-digit subscriber number: 771234567
    // ─────────────────────────────────────────────────────────────────────────

    public function getNormalisedPhoneAttribute(): string
    {
        $digits = preg_replace('/\D/', '', $this->phone_number ?? '');

        if (str_starts_with($digits, '94') && strlen($digits) === 11) {
            return substr($digits, 2);
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return substr($digits, 1);
        }

        return $digits;
    }
}