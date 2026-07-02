<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_id',
        'imei_number',
        'sim_number',
        'device_model',
        'branch_name',
        'status',
        'description'
    ];


    public function stock()
{
    return $this->belongsTo(Stock::class);
}

}