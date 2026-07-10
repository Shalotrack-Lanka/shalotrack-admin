<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Stock extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_type_id',
        'supplier_id',
        'stock_in',
        'company_available_stock',
        'total_available',
        'description',
        'sort_order',
    ];

    public function deviceType()
    {
        return $this->belongsTo(DeviceType::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function sims()
    {
        return $this->hasMany(Sim::class);
    }
}