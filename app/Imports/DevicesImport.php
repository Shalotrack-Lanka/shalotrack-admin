<?php

namespace App\Imports;

use App\Enums\DeviceStatus;
use App\Models\SetupShalotrackDevice;
use App\Models\DeviceType;
use App\Models\Sim;
use App\Models\Stock;
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

    public function model(array $row)
    {
        // 1. Excel හි Columns වලින් Category එක සහ Model එක වෙන වෙනම ලබා ගැනීම
        $categoryInput = trim($row['device_category']);
        $modelInput = trim($row['model']);

        // 2. වෙන වෙනම ලබාගත් දත්ත අනුව Device Type එක Database එකෙන් සෙවීම
        $deviceType = DeviceType::whereRaw('LOWER(device_category) = ?', [strtolower($categoryInput)])
            ->whereRaw('LOWER(model) = ?', [strtolower($modelInput)])
            ->first();

        if (!$deviceType) {
            return null; // Device Type එක පද්ධතියේ නැත්නම් මේ පේළිය මඟහරියි (skip කරයි)
        }

        // 3. 'with' ඉවත් කර හිස්තැනක් පමණක් තබා නම සැකසීම (උදා: "V10 Plus")
        $deviceCategoryLabel = $deviceType->device_category . ' ' . $deviceType->model;

        // Transaction එකක් හරහා Stock එක අඩු කර Device එක Save කිරීම
        $device = DB::transaction(function () use ($row, $deviceType, $deviceCategoryLabel) {
            
            $stock = Stock::where('device_type_id', $deviceType->id)->lockForUpdate()->first();

            // Stock එකේ බඩු නැත්නම් දත්ත Save නොකරයි
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
            ]);

            // දුන් SIM එක පද්ධතියේ Activated ලැයිස්තුවෙන් ඉවත් කිරීම
            // (its identity now lives on the device row: iccid/imsi above)
            $sim?->delete();

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
                // SIM එක අනිවාර්යයෙන්ම පද්ධතියේ තිබිය යුතු අතර එය Activated එකක් විය යුතුය
                Rule::exists('sims', 'sim_number')->where('sim_status', 'Activated')
            ],
            'device_category' => ['required', 'string'],
            'model'           => ['required', 'string'], // Model එකත් අනිවාර්ය කර ඇත
        ];
    }

    public function customValidationMessages()
    {
        return [
            'sim_number.exists' => 'SIM අංකය (:input) පද්ධතියේ නොමැත හෝ එය තවමත් Activated තත්වයේ නොමැත.',
            'imei_number.unique' => 'IMEI අංකය (:input) දැනටමත් පද්ධතියේ පවතී.',
            'device_category.required' => 'Device Category එක ඇතුලත් කිරීම අනිවාර්ය වේ.',
            'model.required' => 'Model එක ඇතුලත් කිරීම අනිවාර්ය වේ.',
        ];
    }
}