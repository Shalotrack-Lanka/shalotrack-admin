<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockTransfer extends Model
{
    protected $fillable = ['stock_id', 'dealer_id', 'quantity', 'remarks'];

    public function stock() { return $this->belongsTo(Stock::class); }
    public function dealer() { return $this->belongsTo(Dealer::class); }
}