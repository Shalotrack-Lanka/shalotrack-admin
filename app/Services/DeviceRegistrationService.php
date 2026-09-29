<?php

namespace App\Services;

use App\Enums\DeviceStatus;
use App\Models\DeviceType;
use App\Models\SetupShalotrackDevice;
use App\Models\Sim;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Single place that turns "an IMEI (+ optional SIM) for a device type" into a
 * registered SetupShalotrackDevice. Same rules AddDeviceController::store()
 * enforces: one unit of company stock is consumed per device (row-locked),
 * the SIM must be an Activated one, and the SIM leaves the available pool.
 *
 * On top of that it snapshots the SIM's ICCID/IMSI onto the device row before
 * the SIM master row is deleted, so the physical SIM's identity isn't lost.
 *
 * Everything runs in ONE transaction: either the device is fully registered
 * (stock decremented, SIM consumed) or nothing changed.
 *
 * Throws ValidationException with a human-readable message on any business
 * rule failure; the caller decides how to present it.
 */
class DeviceRegistrationService
{
    /**
     * @param  string      $source  'manual' | 'scan'
     * @param  string|null $adminId Admins.admin_id of whoever is registering
     */
    public function register(
        int $deviceTypeId,
        string $imei,
        ?string $iccid,
        string $source,
        ?string $adminId
    ): SetupShalotrackDevice {
        $deviceType = DeviceType::find($deviceTypeId);

        if (! $deviceType) {
            throw ValidationException::withMessages(['device_type_id' => 'The selected device type is invalid.']);
        }

        return DB::transaction(function () use ($deviceType, $imei, $iccid, $source, $adminId) {

            // Locked existence check gives a friendly message; the UNIQUE
            // index on imei_number remains the real guarantee under races.
            if (SetupShalotrackDevice::where('imei_number', $imei)->exists()) {
                throw ValidationException::withMessages(['imei' => 'This IMEI number is already registered.']);
            }

            $sim = null;
            if ($iccid !== null && $iccid !== '') {
                $sim = Sim::where('iccid', $iccid)->lockForUpdate()->first();

                if (! $sim) {
                    throw ValidationException::withMessages(['iccid' => 'This SIM (ICCID) is not registered in the system.']);
                }
                if ($sim->sim_status !== 'Activated') {
                    throw ValidationException::withMessages(['iccid' => 'This SIM is not in Activated status.']);
                }
                if (SetupShalotrackDevice::where('iccid', $iccid)->exists()) {
                    throw ValidationException::withMessages(['iccid' => 'This SIM is already attached to another device.']);
                }
            }

            $stock = Stock::where('device_type_id', $deviceType->id)->lockForUpdate()->first();

            if (! $stock || $stock->company_available_stock < 1) {
                throw ValidationException::withMessages([
                    'device_type_id' => 'No Company Available Stock left for this Device Category / Type.',
                ]);
            }

            $stock->decrement('company_available_stock');

            $device = SetupShalotrackDevice::create([
                'device_type_id'  => $deviceType->id,
                'device_category' => $deviceType->device_category . ' ' . $deviceType->model,
                'imei_number'     => $imei,
                'sim_number'      => $sim?->sim_number,
                'iccid'           => $sim?->iccid,
                'imsi'            => $sim?->imsi,
                'registered_by'   => $adminId,
                'intake_source'   => $source,
                'status'          => DeviceStatus::NotActivated->value,
                'dealer_id'       => null,
            ]);

            // The SIM is now inside a physical device, so it leaves the pool
            // of Activated SIMs (existing behaviour) — its identity now lives
            // on the device row snapshot above.
            $sim?->delete();

            return $device;
        });
    }
}