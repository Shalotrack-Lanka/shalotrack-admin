<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class StockImportTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return ['device_category', 'model', 'supplier_name', 'stock_in', 'stocked_in_date'];
    }

    public function array(): array
    {
        return [
            ['V7', 'Normal', 'Amoda Rashmika', '100', now()->toDateString()],
        ];
    }
}