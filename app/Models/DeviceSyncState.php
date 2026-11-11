<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceSyncState extends Model
{
    protected $table = 'device_sync_state';
    protected $primaryKey = 'shdevice_id';
    public $incrementing = false;
    protected $guarded = [];

    protected $casts = [
        'pending'         => 'boolean',
        'next_attempt_at' => 'datetime',
        'last_synced_at'  => 'datetime',
    ];
}
