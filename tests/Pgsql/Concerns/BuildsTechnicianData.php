<?php

namespace Tests\Pgsql\Concerns;

use App\Enums\TechnicianJobStatus;
use App\Enums\TechnicianJobType;
use App\Models\ActivatedDevice;
use App\Models\Technician;
use App\Models\TechnicianJob;
use App\Models\VehicleAd;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Shared helpers for the technician and technician-job suites. A class that uses this trait must also use
 * BuildsIntakeData (for nextIntakeNumber and validImei). Every row is rolled back by DatabaseTransactions.
 */
trait BuildsTechnicianData
{
    protected function quietApi(): void
    {
        // The admin sidebar asks the app server for the complaint count on every page.
        Http::fake(['*' => Http::response(['data' => []], 200)]);
    }

    /** Today in Sri Lanka, the same day the screens and the rules use. */
    protected function slToday(int $plusDays = 0): string
    {
        return now()->local()->addDays($plusDays)->toDateString();
    }

    protected function makeTechnician(array $overrides = []): Technician
    {
        $n = $this->nextIntakeNumber();

        return Technician::create(array_merge([
            'name'      => "Autotest Technician {$n}",
            'phone'     => '+94 77 123 4567',
            'is_active' => true,
        ], $overrides));
    }

    protected function makeVehicle(array $overrides = []): VehicleAd
    {
        $n = $this->nextIntakeNumber();

        return VehicleAd::create(array_merge([
            'vehicle_id'     => (string) Str::uuid(),
            'customer_id'    => (string) Str::uuid(),
            'customer_name'  => "Autotest Customer {$n}",
            'vehicle_number' => "AUTO-T{$n}",
            'make'           => 'Toyota',
            'model'          => 'Axio',
            'has_gps_device' => false,
        ], $overrides));
    }

    /** Binds a device to the vehicle (what makes Repair, Replace and Removal possible). */
    protected function bindDevice(VehicleAd $vehicle, array $overrides = []): ActivatedDevice
    {
        return ActivatedDevice::create(array_merge([
            'vehicle_id'     => $vehicle->vehicle_id,
            'customer_id'    => $vehicle->customer_id,
            'customer_name'  => $vehicle->customer_name,
            'vehicle_number' => $vehicle->vehicle_number,
            'model'          => 'Autotest Model',
            'has_gps_device' => true,
            'imei_number'    => $this->validImei(),
            'status'         => 'Activated',
        ], $overrides));
    }

    protected function makeJob(VehicleAd $vehicle, Technician $technician, array $overrides = []): TechnicianJob
    {
        return TechnicianJob::create(array_merge([
            'job_type'       => TechnicianJobType::Install->value,
            'status'         => TechnicianJobStatus::Scheduled->value,
            'vehicle_id'     => $vehicle->vehicle_id,
            'vehicle_number' => $vehicle->vehicle_number,
            'customer_id'    => $vehicle->customer_id,
            'customer_name'  => $vehicle->customer_name,
            'technician_id'  => $technician->id,
            'scheduled_for'  => $this->slToday(1),
        ], $overrides));
    }
}