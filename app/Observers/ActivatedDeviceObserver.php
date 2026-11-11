<?php

namespace App\Observers;

use App\Models\ActivatedDevice;
use App\Services\DeviceSync;

/** Binding a device to a vehicle (or undoing it) changes "bound" for the API's trial rule. */
class ActivatedDeviceObserver
{
    public bool $afterCommit = true;

    public function created(ActivatedDevice $row): void
    {
        DeviceSync::markPendingByImei($row->imei_number);
    }

    public function deleted(ActivatedDevice $row): void
    {
        DeviceSync::markPendingByImei($row->imei_number);
    }

    public function updated(ActivatedDevice $row): void
    {
        if ($row->wasChanged('imei_number')) {
            DeviceSync::markPendingByImei($row->imei_number);
            DeviceSync::markPendingByImei($row->getOriginal('imei_number'));
        }
    }
}
