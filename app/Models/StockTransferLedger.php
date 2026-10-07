<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockTransferLedger extends Model
{
    // "Delete" hides the row but keeps it recoverable (history is never erased).
    use SoftDeletes;

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