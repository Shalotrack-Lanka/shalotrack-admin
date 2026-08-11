<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockTransferLedger extends Model
{
    protected $fillable = [
        'stock_id',
        'device_category_type',
        'supplier_id',
        'supplier',
        'stock_in',
        'description',
        'stocked_in_date',
    ];

    protected $casts = [
        'stocked_in_date' => 'date',
    ];

    public function stock()
    {
        return $this->belongsTo(Stock::class);
    }

    public function supplierRecord()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
