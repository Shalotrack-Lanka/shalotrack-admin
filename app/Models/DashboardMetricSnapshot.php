<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DashboardMetricSnapshot extends Model
{
    protected $fillable = [
        'snapshot_date',
        'open_complaints_count',
        'devices_online',
        'devices_offline',
        'stock_units_available',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
    ];
}