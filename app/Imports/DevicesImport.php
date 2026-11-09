<?php

namespace App\Imports;

use App\Enums\DeviceStatus;
use App\Models\SetupShalotrackDevice;
use App\Models\DeviceType;
use App\Models\Sim;
use App\Models\Stock;
use App\Services\Delivery\DeliveryPolicy;
use App\Services\Delivery\SupplierDeliveryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsErrors;

class DevicesImport implements ToModel, WithHeadingRow, WithValidation, SkipsOnFailure, SkipsOnError
{
    use SkipsFailures, SkipsErrors;

    public array $created = [];

    /** Devices registered although they were not received on a supplier delivery (warning phase). */
    public int $unverified = 0;

    public function model(array $row)
    {
        // 1. Read the category and the model from separate Excel columns
        $categoryInput = trim($row['device_category']);
        $modelInput = trim($row['model']);

        // 2. Look up the device type in the database using those values
        $deviceType = DeviceType::whereRaw('LOWER(device_category) = ?', [strtolower($categoryInput)])
            ->whereRaw('LOWER(model) = ?', [strtolower($modelInput)])
            ->first();

        if (!$deviceType) {
            return null; // Device type is not in the system: skip this row
        }

        // 3. Remove "with" and build the name with a single space (e.g. "V10 Plus")
        $deviceCategoryLabel = $deviceType->device_category . ' ' . $deviceType->model;

        // Reduce the stock and save the device in one transaction
        $deliveries = app(SupplierDeliveryService::class);

        $device = DB::transaction(function () use ($row, $deviceType, $deviceCategoryLabel, $deliveries) {
            // Supplier delivery check under lock (no-op while the feature is off). The same rule was already
            // applied when the row was validated; this is the race-proof second look.
            $provenance = $deliveries->verify('DEVICE', trim((string) $row['imei_number']), $deviceType->id, 'imei_number');
            
            $stock = Stock::where('device_type_id', $deviceType->id)->lockForUpdate()->first();

            // If there is no stock, do not save the data
            if (!$stock || $stock->company_available_stock < 1) {
                return null;
            }

            $stock->decrement('company_available_stock');

            $simNumber = !empty($row['sim_number']) ? trim((string)$row['sim_number']) : null;

            // Lock the SIM first so its ICCID/IMSI can be copied onto the device
            // before the SIM leaves the pool (otherwise its identity is lost).
            $sim = null;
            if ($simNumber) {
                $sim = Sim::where('sim_number', $simNumber)
                    ->where('sim_status', 'Activated')
                    ->lockForUpdate()
                    ->first();
            }

            $newDevice = SetupShalotrackDevice::create([
                'device_type_id'  => $deviceType->id,
                'device_category' => $deviceCategoryLabel,
                'imei_number'     => trim((string)$row['imei_number']),
                'sim_number'      => $simNumber,
                'iccid'           => $sim?->iccid,
                'imsi'            => $sim?->imsi,
                'status'          => DeviceStatus::NotActivated->value,
                'dealer_id'       => null,
                'registered_by'   => auth()->user()?->admin_id,
                'intake_source'   => 'import',
            ]);

            // Remove the given SIM from the activated list
            // (its identity now lives on the device row: iccid/imsi above)
            $sim?->delete();

            $deliveries->stamp($provenance['unit'], (string) $newDevice->shdevice_id);

            if ($provenance['status'] === DeliveryPolicy::UNVERIFIED) {
                $this->unverified++;
            }

            return $newDevice;
        });

        if ($device) {
            $this->created[] = $device;
            return $device;
        }

        return null;
    }

    public function rules(): array
    {
        return [
            'imei_number'     => ['required', 'numeric', 'digits:15', 'unique:setup_shalotrack_devices,imei_number'],
            'sim_number'      => [
                'nullable', 
                'numeric', 
                'digits:10', 
                // The SIM must exist in the system and must be activated
                Rule::exists('sims', 'sim_number')->where('sim_status', 'Activated')
            ],
            'device_category' => ['required', 'string', 'max:100'],
            'model'           => ['required', 'string', 'max:100'], // Model is also required
        ];
    }

    /**
     * A row whose device type does not exist, or whose type has no company stock left, used to be skipped
     * silently (the admin saw "0 imported" with no reason). Now the row is reported as a failure with the reason.
     * Rows are checked one at a time and saved before the next is checked, so stock used by earlier rows counts.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Laravel hands us {excelRowNumber: rowValues}; errors must be added under "<row>.<field>".
            foreach ($validator->getData() as $rowNumber => $data) {
                if (! is_string($data['device_category'] ?? null) || ! is_string($data['model'] ?? null)
                    || trim($data['device_category']) === '' || trim($data['model']) === '') {
                    continue;
                }

                $type = DeviceType::whereRaw('LOWER(device_category) = ?', [strtolower(trim($data['device_category']))])
                    ->whereRaw('LOWER(model) = ?', [strtolower(trim($data['model']))])
                    ->first();

                if (! $type) {
                    $validator->errors()->add("{$rowNumber}.device_category", "Device type '{$data['device_category']} / {$data['model']}' does not exist.");

                    continue;
                }

                $stock = Stock::where('device_type_id', $type->id)->first();

                if (! $stock || $stock->company_available_stock < 1) {
                    $validator->errors()->add("{$rowNumber}.device_category", "No company stock left for '{$type->device_category} {$type->model}'.");
                }

                // Supplier delivery rule (no-op while the feature is off): wrong device type or, from the
                // enforcement date, an IMEI that never arrived on a delivery is reported as a failed row.
                if (is_scalar($data['imei_number'] ?? null) && preg_match('/^\d{15}$/', trim((string) $data['imei_number']))) {
                    $p = app(SupplierDeliveryService::class)->preview('DEVICE', trim((string) $data['imei_number']), $type->id);
                    if (in_array($p['status'], [DeliveryPolicy::BLOCKED, DeliveryPolicy::MISMATCH, DeliveryPolicy::REGISTERED], true)) {
                        $validator->errors()->add("{$rowNumber}.imei_number", $p['message']);
                    }
                }
            }
        });
    }

    public function customValidationMessages()
    {
        return [
            'sim_number.exists' => 'The SIM number (:input) is not in the system or is not activated yet.',
            'imei_number.unique' => 'The IMEI number (:input) already exists in the system.',
            'device_category.required' => 'The device category is required.',
            'model.required' => 'The model is required.',
        ];
    }
}