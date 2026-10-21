<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditNote extends Model
{
    use ImmutableRecord;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'issue_date' => 'date',
        'snapshot'   => 'array',
        'created_at' => 'datetime',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function lines()
    {
        return $this->hasMany(CreditNoteLine::class);
    }
}
