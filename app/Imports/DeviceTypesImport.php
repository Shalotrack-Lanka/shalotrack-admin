<?php

namespace App\Imports;

use App\Models\DeviceType;
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
            'device_category' => trim($row['device_category']),
            'model'           => trim($row['model']),
            'protocol'        => trim($row['protocol']),
            'features'        => $featureIds, // Saved as an array like [ "1", "2" ]
        ]);

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
            foreach ($validator->getData() as $rowNumber => $data) {
                if (! is_string($data['device_category'] ?? null) || ! is_string($data['model'] ?? null)
                    || trim($data['device_category']) === '' || trim($data['model']) === '') {
                    continue;
                }

                $exists = DeviceType::whereRaw('LOWER(device_category) = ?', [strtolower(trim($data['device_category']))])
                    ->whereRaw('LOWER(model) = ?', [strtolower(trim($data['model']))])
                    ->exists();

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