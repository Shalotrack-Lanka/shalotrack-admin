<?php

namespace App\Imports;

use App\Models\DeviceType;
use App\Models\Stock;
use App\Models\StockTransferLedger;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsErrors;
use Maatwebsite\Excel\Concerns\Importable;

class StockImport implements ToModel, WithHeadingRow, WithValidation, SkipsOnFailure, SkipsOnError
{
    use Importable, SkipsFailures, SkipsErrors;

    public array $created = [];
    public int $totalStockIn = 0;

    public function model(array $row)
    {
        $deviceType = DeviceType::whereRaw('LOWER(device_category) = ?', [strtolower(trim($row['device_category']))])
            ->whereRaw('LOWER(model) = ?', [strtolower(trim($row['model']))])
            ->first();

        $supplier = Supplier::whereRaw('LOWER(name) = ?', [strtolower(trim($row['supplier_name']))])
            ->where('status', 'Active')
            ->first();

        if (! $deviceType || ! $supplier) {
            return null; 
        }

        $deviceLabel = trim("{$deviceType->device_category} {$deviceType->model}");
        $qty = (int) $row['stock_in'];

        $stockedInDate = ! empty($row['stocked_in_date'])
            ? Carbon::parse($row['stocked_in_date'])->toDateString()
            : now()->toDateString();

        DB::transaction(function () use ($deviceType, $deviceLabel, $supplier, $qty, $stockedInDate) {
            
            // Table එකේ තියෙන Columns වලට විතරක් දත්ත දැමීම
            $stock = Stock::firstOrCreate(
                ['device_type_id' => $deviceType->id],
                ['company_available_stock' => 0]
            );

            $stock->device_category_type = $deviceLabel;
            $stock->company_available_stock = ($stock->company_available_stock ?? 0) + $qty;
            $stock->updated_at = now();
            $stock->save();

            // අනෙකුත් සියලුම විස්තර Ledger එකට දැමීම
            StockTransferLedger::create([
                'stock_id'             => $stock->id,
                'device_category_type' => $deviceLabel,
                'supplier_id'          => $supplier->id,
                'supplier'             => $supplier->name,
                'stock_in'             => $qty,
                'stocked_in_date'      => $stockedInDate,
            ]);
        });

        $this->created[] = $deviceLabel . ' (+' . $qty . ')';
        $this->totalStockIn += $qty;

        return null;
    }

    public function rules(): array
    {
        return [
            'device_category'  => ['required', 'string'],
            'model'            => ['required', 'string'],
            'supplier_name'    => ['required', 'string'],
            'stock_in'         => ['required', 'integer', 'min:1'],
            'stocked_in_date'  => ['nullable'],
        ];
    }

    public function withValidator(Validator $validator)
    {
        $validator->after(function ($validator) {
            $data = $validator->getData();

            if (! empty($data['device_category']) && ! empty($data['model'])) {
                $exists = DeviceType::whereRaw('LOWER(device_category) = ?', [strtolower(trim($data['device_category']))])
                    ->whereRaw('LOWER(model) = ?', [strtolower(trim($data['model']))])
                    ->exists();

                if (! $exists) {
                    $validator->errors()->add(
                        'device_category',
                        "මෙම '{$data['device_category']} / {$data['model']}' නමින් Device Type එකක් පද්ධතියේ නොමැත."
                    );
                }
            }

            if (! empty($data['supplier_name'])) {
                $exists = Supplier::whereRaw('LOWER(name) = ?', [strtolower(trim($data['supplier_name']))])
                    ->where('status', 'Active')
                    ->exists();

                if (! $exists) {
                    $validator->errors()->add(
                        'supplier_name',
                        "'{$data['supplier_name']}' නමින් ක්‍රියාකාරී සැපයුම්කරුවෙක් පද්ධතියේ නොමැත."
                    );
                }
            }
        });
    }

    public function prepareForValidation($data, $index)
    {
        if (!empty($data['stocked_in_date']) && is_numeric($data['stocked_in_date'])) {
            try {
                $data['stocked_in_date'] = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($data['stocked_in_date'])->format('Y-m-d');
            } catch (\Exception $e) {
                // Ignore Invalid Dates
            }
        }
        return $data;
    }
}