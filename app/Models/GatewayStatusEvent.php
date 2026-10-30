<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One confirmed gateway state change (ok / degraded / down) -- written by the
 * gateway:check command, only when the state differs from the last recorded one.
 * Append-only: no updated_at, rows are never edited.
 */
class GatewayStatusEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'state',
        'reasons',
        'devices_connected',
        'public_path_ok',
        'recorded_at',
    ];

    protected $casts = [
        'reasons'           => 'array',
        'devices_connected' => 'integer',
        'public_path_ok'    => 'boolean',
        'recorded_at'       => 'datetime',
    ];
}