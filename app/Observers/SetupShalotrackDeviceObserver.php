<?php

namespace App\Observers;

use App\Models\SetupShalotrackDevice;
use App\Services\DeviceSync;

/** Queues a push to the API whenever a device row changes in a way the API cares about. */
class SetupShalotrackDeviceObserver
{
    /** Run only after the surrounding transaction commits, so a rolled-back change is never queued. */
    public bool $afterCommit = true;

    private const WATCHED = ['imei_number', 'sim_number', 'device_category', 'status', 'cancel_reason',
        'canceled_date', 'dealer_id', 'device_type_id'];

    public function saved(SetupShalotrackDevice $device): void
    {
        if ($device->wasRecentlyCreated || $device->wasChanged(self::WATCHED)) {
            DeviceSync::markPending($device->shdevice_id, $device->imei_number);
        }
    }
}
