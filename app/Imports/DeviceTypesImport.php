<?php

namespace App\Imports;

use App\Models\DeviceType;
use App\Models\Feature;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\Importable;

/**
 * Expected columns:
 *   device_category | model | protocol | features
 *
 * "model" must be exactly one of Basic / Plus / Customize — same
 * restriction as the dropdown in the single-entry form.
 *
 * "features" is optional, comma-separated feature NAMES (e.g.
 * "Geofencing, Ignition Alert") — resolved to feature IDs here, since
 * admins filling in a spreadsheet won't know internal feature IDs. Any
 * name that doesn't match an existing Feature fails that row rather than
 * silently being dropped.
 */
class DeviceTypesImport implements ToModel, WithHeadingRow, WithValidation, SkipsOnFailure
{
    use Importable, SkipsFailures;

    public array $created = [];

    public function model(array $row)
    {
        $featureIds = [];

        if (! empty($row['features'])) {
            $names = array_filter(array_map('trim', explode(',', $row['features'])));
            $featureIds = Feature::whereIn('name', $names)->pluck('id')->toArray();
        }

        $deviceType = new DeviceType([
            'device_category' => trim($row['device_category']),
            'model'            => trim($row['model']),
            'protocol'         => trim($row['protocol']),
            'features'         => $featureIds,
        ]);

        $this->created[] = $deviceType;

        return $deviceType;
    }

    public function rules(): array
    {
        return [
            'device_category' => ['required', 'string', 'max:255'],
            'model'            => ['required', 'string', Rule::in(['Basic', 'Plus', 'Customize'])],
            'protocol'         => ['required', 'string', 'max:255'],
            'features'         => ['nullable', 'string'],
        ];
    }

    public function customValidationMessages()
    {
        return [
            'model.in' => 'Model must be exactly one of: Basic, Plus, Customize.',
        ];
    }

    /**
     * Two cross-field / cross-table checks that don't fit a single-column
     * rule: the category+model combo must not already exist (same rule
     * store() enforces), and every feature name listed must be real.
     */
    public function withValidator(Validator $validator)
    {
        $validator->after(function ($validator) {
            $data = $validator->getData();

            if (! empty($data['device_category']) && ! empty($data['model'])) {
                $exists = DeviceType::where('device_category', trim($data['device_category']))
                    ->where('model', trim($data['model']))
                    ->exists();

                if ($exists) {
                    $validator->errors()->add(
                        'device_category',
                        "'{$data['device_category']} / {$data['model']}' already exists."
                    );
                }
            }

            if (! empty($data['features'])) {
                $names = array_filter(array_map('trim', explode(',', $data['features'])));
                $found = Feature::whereIn('name', $names)->pluck('name')->toArray();
                $missing = array_diff($names, $found);

                if (! empty($missing)) {
                    $validator->errors()->add(
                        'features',
                        'Unknown feature name(s): ' . implode(', ', $missing) . '. Add them via Add Feature first.'
                    );
                }
            }
        });
    }
}