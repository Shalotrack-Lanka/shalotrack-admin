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

        // Features කොලම් එකේ දත්ත තියෙනවා නම් ඒවා කඩා වෙන් කර ගැනීම (උදා: "Ignition Alert, Speed alert")
        if (!empty($row['features'])) {
            // කොමා (,) වලින් වෙන් කර Array එකක් සෑදීම
            $featureNames = array_map('trim', explode(',', $row['features']));
            
            // එක් එක් Feature නමට අදාළ ID එක සොයා ගැනීම
            foreach ($featureNames as $name) {
                if (!empty($name)) {
                    // Feature එක Database එකේ තියෙනවාද බලනවා (කැපිටල්/සිම්පල් නොසලකා)
                    $feature = Feature::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
                    
                    // Feature එක නැත්නම් අලුතින් සාදා එහි ID එක ගන්නවා
                    if (!$feature) {
                        $feature = Feature::create(['name' => $name]);
                    }
                    
                    $featureIds[] = (string) $feature->id; // JSON වලට ගැලපෙන්න String ලෙස ගන්නවා
                }
            }
        }

        // Database එකට දත්ත ඇතුළත් කිරීම
        $deviceType = DeviceType::create([
            'device_category' => trim($row['device_category']),
            'model'           => trim($row['model']),
            'protocol'        => trim($row['protocol']),
            'features'        => $featureIds, // [ "1", "2" ] වගේ Array එකක් ලෙස සේව් වේ
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
            'features'        => ['nullable', 'string'],
        ];
    }

    // එකම Category සහ Model එක දෙපාරක් ආවොත් වැළැක්වීම
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