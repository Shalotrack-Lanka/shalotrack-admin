<?php

namespace App\Imports;

use App\Models\SetupShalotrackDevice;
use App\Models\DeviceType;
use App\Models\Sim;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsErrors;
use Maatwebsite\Excel\Concerns\Importable;

/**
 * Expected columns (matched by NAME via WithHeadingRow, order doesn't matter):
 *   imei_number | sim_number | device_category | model
 *
 * This deliberately mirrors AddDeviceController::store() rule-for-rule —
 * it is the bulk version of that exact form, not a separate simplified
 * path:
 *   - One unit of company_available_stock is consumed per device, same
 *     device_type. A row that would go past what's actually available
 *     fails with a clear reason instead of silently creating unbacked
 *     devices.
 *   - sim_number, if given, must already exist in `sims` as Activated,
 *     and gets deleted from that pool once attached — same as the single
 *     form. A sim_number that isn't a real Activated SIM fails the row.
 *   - device_category is stored as the same human-readable
 *     "{category} with {model}" label the single form builds.
 *
 * SkipsOnFailure covers validation rule failures (rules() below).
 * SkipsOnError covers real exceptions thrown from model() itself — like
 * an insufficient-stock RuntimeException — so ONE row running out of
 * stock doesn't abort every row after it in the same file.
 */
class DevicesImport implements ToModel, WithHeadingRow, WithValidation, SkipsOnFailure, SkipsOnError
{
    use Importable, SkipsFailures, SkipsErrors;

    // Every device actually created, populated as rows import. The
    // controller uses this afterward to push each one to the API, exactly
    // like the single-device flow already does via pushDeviceToApi().
    public array $created = [];

    public function model(array $row)
    {
        $deviceType = DeviceType::where('device_category', trim($row['device_category']))
            ->where('model', trim($row['model']))
            ->first();

        // withValidator() below already guarantees this exists — this is
        // a defensive fallback, not the primary safeguard.
        if (! $deviceType) {
            throw new \RuntimeException(
                "No device type found matching '{$row['device_category']} / {$row['model']}'."
            );
        }

        $deviceCategoryLabel = $deviceType->device_category . ' with ' . $deviceType->model;
        $imei = trim($row['imei_number']);
        $simNumber = ! empty($row['sim_number']) ? trim($row['sim_number']) : null;

        $device = DB::transaction(function () use ($deviceType, $deviceCategoryLabel, $imei, $simNumber) {

            $stock = Stock::where('device_type_id', $deviceType->id)->lockForUpdate()->first();

            if (! $stock || $stock->company_available_stock < 1) {
                throw new \RuntimeException(
                    "No available stock left for '{$deviceType->device_category} {$deviceType->model}' — row skipped."
                );
            }

            $stock->decrement('company_available_stock');

            $device = SetupShalotrackDevice::create([
                'device_type_id'  => $deviceType->id,
                'device_category' => $deviceCategoryLabel,
                'imei_number'     => $imei,
                'sim_number'      => $simNumber,
                'status'          => 'Not Activated',
                'dealer_id'       => null,
            ]);

            // Same as the single-device form: once a SIM is attached, it
            // no longer belongs in the pool of Activated SIMs available
            // for setup.
            if ($simNumber) {
                Sim::where('sim_number', $simNumber)
                    ->where('sim_status', 'Activated')
                    ->delete();
            }

            return $device;
        });

        $this->created[] = $device;

        // Returning null on purpose — the device is already fully created
        // and saved above (stock decrement + Sim consumption need to
        // happen atomically alongside it in one transaction). Returning
        // the model here as well would make the package attempt a second,
        // redundant save on an already-persisted row.
        return null;
    }

    public function rules(): array
    {
        return [
            'imei_number'      => ['required', 'digits:15', 'unique:setup_shalotrack_devices,imei_number'],
            'sim_number'       => [
                'nullable',
                'digits:10',
                Rule::exists('sims', 'sim_number')->where('sim_status', 'Activated'),
            ],
            'device_category'  => ['required', 'string'],
            'model'            => ['required', 'string'],
        ];
    }

    public function customValidationMessages()
    {
        return [
            'imei_number.digits' => 'IMEI must be exactly 15 digits.',
            'imei_number.unique' => 'This IMEI is already registered in the system.',
            'sim_number.digits'  => 'SIM number must be exactly 10 digits.',
            'sim_number.exists'  => 'This SIM number is not a registered, Activated SIM.',
        ];
    }

    /**
     * Cross-field check: device_category + model together must match a
     * real device_types row. Laravel's built-in rules can't express "these
     * two columns combined must exist" cleanly, so it's done here instead.
     */
    public function withValidator(Validator $validator)
    {
        $validator->after(function ($validator) {
            $data = $validator->getData();

            if (! empty($data['device_category']) && ! empty($data['model'])) {
                $exists = DeviceType::where('device_category', trim($data['device_category']))
                    ->where('model', trim($data['model']))
                    ->exists();

                if (! $exists) {
                    $validator->errors()->add(
                        'device_category',
                        "No device type found matching '{$data['device_category']} / {$data['model']}'."
                    );
                }
            }
        });
    }
}