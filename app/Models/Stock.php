<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Stock extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_type_id',
        'device_category_type',
        'company_available_stock',
    ];

    public function deviceType()
    {
        return $this->belongsTo(DeviceType::class);
    }

    public function sims()
    {
        return $this->hasMany(Sim::class);
    }

    public function ledgerEntries()
    {
        return $this->hasMany(StockTransferLedger::class);
    }
}
