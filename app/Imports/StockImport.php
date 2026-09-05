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

/**
 * Expected columns:
 *   device_category | model | supplier_name | stock_in | stocked_in_date
 *
 * stocked_in_date is optional — defaults to today, same as the single form
 * (which has no date field at all and always uses now()).
 *
 * Each row is the bulk equivalent of one "Add Raw Devices" submission:
 * bumps Stock.company_available_stock for that device type AND creates a
 * matching StockTransferLedger row, same as store() does. This is
 * deliberately NOT the same thing as the Add Device import — that one
 * consumes stock per physical IMEI, this one is what CREATES the stock
 * those IMEIs get consumed from.
 */
class StockImport implements ToModel, WithHeadingRow, WithValidation, SkipsOnFailure, SkipsOnError
{
    use Importable, SkipsFailures, SkipsErrors;

    public array $created = [];
    public int $totalStockIn = 0;

    public function model(array $row)
    {
        $deviceType = DeviceType::where('device_category', trim($row['device_category']))
            ->where('model', trim($row['model']))
            ->first();

        $supplier = Supplier::where('name', trim($row['supplier_name']))
            ->where('status', 'Active')
            ->first();

        // withValidator() below already guarantees both exist — defensive
        // fallback only, same pattern as the other imports.
        if (! $deviceType || ! $supplier) {
            throw new \RuntimeException('Could not resolve device type or supplier for this row.');
        }

        $deviceLabel = "{$deviceType->device_category} with {$deviceType->model}";
        $qty = (int) $row['stock_in'];

        $stockedInDate = ! empty($row['stocked_in_date'])
            ? Carbon::parse($row['stocked_in_date'])->toDateString()
            : now()->toDateString();

        DB::transaction(function () use ($deviceType, $deviceLabel, $supplier, $qty, $stockedInDate) {
            $stock = Stock::firstOrCreate(
                ['device_type_id' => $deviceType->id],
                ['company_available_stock' => 0]
            );

            $stock->device_category_type = $deviceLabel;
            $stock->company_available_stock += $qty;
            $stock->save();

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

        // Already persisted manually above — returning null so the
        // package doesn't attempt a second save.
        return null;
    }

    public function rules(): array
    {
        return [
            'device_category'  => ['required', 'string'],
            'model'            => ['required', 'string'],
            'supplier_name'    => ['required', 'string'],
            'stock_in'         => ['required', 'integer', 'min:1'],
            'stocked_in_date'  => ['nullable', 'date'],
        ];
    }

    /**
     * Cross-field checks: device_category+model must match a real device
     * type, and supplier_name must match a real, Active supplier.
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

            if (! empty($data['supplier_name'])) {
                $exists = Supplier::where('name', trim($data['supplier_name']))
                    ->where('status', 'Active')
                    ->exists();

                if (! $exists) {
                    $validator->errors()->add(
                        'supplier_name',
                        "No Active supplier found named '{$data['supplier_name']}'."
                    );
                }
            }
        });
    }
}