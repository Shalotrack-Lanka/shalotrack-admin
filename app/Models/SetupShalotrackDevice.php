<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SetupShalotrackDevice extends Model
{
    protected $table = 'setup_shalotrack_devices';
    protected $primaryKey = 'shdevice_id';

    protected $fillable = [
        'device_category',
        'imei_number',
        'sim_number',
    ];
}