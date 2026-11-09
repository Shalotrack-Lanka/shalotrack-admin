<?php

namespace App\Imports;

use App\Http\Controllers\Admin\MasterPages\AddDeviceTypeController;
use App\Models\DeviceType;
use App\Models\Stock;
use App\Models\Feature;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsErrors;

class DeviceTypesImport implements ToModel, WithHeadingRow, WithValidation, SkipsOnFailure, SkipsOnError
{
    use SkipsFailures, SkipsErrors;

    public array $created = [];

    public function model(array $row)
    {
        $featureIds = [];

        // If the Features column has data, split it (e.g. "Ignition Alert, Speed alert")
        if (!empty($row['features'])) {
            // Split on commas to make an array
            $featureNames = array_map('trim', explode(',', $row['features']));
            
            // Find the ID for each feature name
            foreach ($featureNames as $name) {
                if (!empty($name)) {
                    // Check whether the feature exists in the database (case-insensitive)
                    $feature = Feature::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
                    
                    // If the feature does not exist, create it and take its ID
                    if (!$feature) {
                        $feature = Feature::create(['name' => $name]);
                    }
                    
                    $featureIds[] = (string) $feature->id; // Keep it as a string so it matches the JSON
                }
            }
        }

        // Insert the data into the database
        $deviceType = DeviceType::create([
            'device_category' => trim(preg_replace('/\s+/', ' ', (string) $row['device_category'])),
            'model'           => trim($row['model']),
            'protocol'        => trim($row['protocol']),
            'features'        => $featureIds, // Saved as an array like [ "1", "2" ]
        ]);

        // Its company stock row starts at 0 (same as adding one on the page).
        Stock::firstOrCreate(
            ['device_type_id' => $deviceType->id],
            ['company_available_stock' => 0, 'device_category_type' => trim($deviceType->device_category . ' ' . $deviceType->model)]
        );

        $this->created[] = $deviceType->id;

        return $deviceType;
    }

    public function rules(): array
    {
        return [
            'device_category' => ['required', 'string', 'max:255'],
            'model'           => ['required', 'string', 'in:Basic,Plus,Customize'],
            'protocol'        => ['required', 'string', 'max:255'],
            'features'        => ['nullable', 'string', 'max:500'],
        ];
    }

    // Prevent the same category and model appearing twice
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Laravel hands us {excelRowNumber: rowValues}; errors must be added under "<row>.<field>".
            // (Before this fix the code looked for 'device_category' at the top level, never found it,
            // and so the duplicate check never ran.)
            $inFile = [];
            foreach ($validator->getData() as $rowNumber => $data) {
                if (! is_string($data['device_category'] ?? null) || ! is_string($data['model'] ?? null)
                    || trim($data['device_category']) === '' || trim($data['model']) === '') {
                    continue;
                }

                // Same rule as the page: capitals and extra spaces do not make a different device type.
                $key = mb_strtolower(trim(preg_replace('/\s+/', ' ', $data['device_category']))) . '|' . mb_strtolower(trim($data['model']));
                if (isset($inFile[$key])) {
                    $validator->errors()->add("{$rowNumber}.device_category", "'{$data['device_category']} / {$data['model']}' is in the file twice (row {$inFile[$key]}).");
                    continue;
                }
                $inFile[$key] = $rowNumber;

                $exists = AddDeviceTypeController::nameTaken($data['device_category'], $data['model']);

                if ($exists) {
                    $validator->errors()->add(
                        "{$rowNumber}.device_category",
                        "A Device Type '{$data['device_category']} / {$data['model']}' already exists in the system."
                    );
                }
            }
        });
    }
}