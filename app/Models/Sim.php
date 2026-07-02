<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sim extends Model
{
    use HasFactory;

    // සියලුම fields වලට දත්ත ඇතුළත් කිරීමට අවසර දීම
    protected $guarded = [];

    // Master Stock එක සමඟ ඇති සබඳතාවය
    public function stock()
    {
        return $this->belongsTo(Stock::class, 'stock_id');
    }
}