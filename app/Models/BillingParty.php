<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Legal identity printed on documents. Editable; issued documents keep a frozen copy. */
class BillingParty extends Model
{
    protected $guarded = [];

    public const KIND_COMPANY = 'company';
    public const KIND_DEALER = 'dealer';

    public function dealer()
    {
        return $this->belongsTo(Dealer::class);
    }

    /** What is frozen into a document's snapshot. */
    public function toSnapshot(): array
    {
        return [
            'legal_name'   => $this->legal_name,
            'address'      => $this->address,
            'tax_number'   => $this->tax_number,
            'phone'        => $this->phone,
            'email'        => $this->email,
            'bank_details' => $this->kind === self::KIND_COMPANY ? $this->bank_details : null,
        ];
    }
}
