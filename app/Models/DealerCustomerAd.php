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
        'email',
        'nic_or_id',
        'contact',
        'no_of_devices',
        'imei_numbers',
        'address',
    ];

    protected $casts = [
        'imei_numbers' => 'array',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────────────────

    public function dealer()
    {
        return $this->belongsTo(Dealer::class, 'dealer_id');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Phone normalisation helper
    //
    // The dealer stores phone as a local 10-digit Sri Lankan number: 0771234567
    // The API (and CustomerAd) stores phone in E.164 format: +94771234567
    //
    // Strip everything except digits, remove the leading country code (94)
    // or leading zero, leaving the 9-digit subscriber number: 771234567
    // This normalised form is safe to compare across both formats.
    // ─────────────────────────────────────────────────────────────────────────

    public function getNormalisedContactAttribute(): string
    {
        $digits = preg_replace('/\D/', '', $this->contact ?? '');

        // Remove leading 94 (international prefix without +)
        if (str_starts_with($digits, '94') && strlen($digits) === 11) {
            return substr($digits, 2);
        }

        // Remove leading 0 (local prefix)
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return substr($digits, 1);
        }

        return $digits;
    }
}