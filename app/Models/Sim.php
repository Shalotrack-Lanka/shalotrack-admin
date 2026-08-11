<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sim extends Model
{
    use HasFactory;

    protected $fillable = [
        'sim_number',
        'sim_type',
        'imsi',
        'iccid',
        'activation_required',
        'sim_status',
    ];

    protected $casts = [
        'activation_required' => 'boolean',
    ];
}
