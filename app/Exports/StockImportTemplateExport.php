<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use Maatwebsite\Excel\Concerns\FromArray;

class StockImportTemplateExport implements FromArray, WithHeadings, WithEvents
{
    public function headings(): array
    {
        return ['device_category', 'model', 'supplier_name', 'stock_in', 'stocked_in_date'];
    }

    public function array(): array
    {
        return [];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $this->addPlaceholder($sheet, 'A2:A1000', 'Device Category', 'e.g. V7');
                $this->addPlaceholder($sheet, 'B2:B1000', 'Model', 'e.g. Normal — must match an existing Device Type combo');
                $this->addPlaceholder($sheet, 'C2:C1000', 'Supplier Name', 'Must match an existing, Active supplier exactly');
                $this->addPlaceholder($sheet, 'D2:D1000', 'Stock In', 'e.g. 100');
                $this->addPlaceholder($sheet, 'E2:E1000', 'Stocked-In Date (optional)', 'e.g. 2026-09-05 — defaults to today if left blank');
            },
        ];
    }

    private function addPlaceholder($sheet, string $range, string $title, string $text): void
    {
        $validation = $sheet->getCell(explode(':', $range)[0])->getDataValidation();
        $validation->setType(DataValidation::TYPE_NONE);
        $validation->setShowInputMessage(true);
        $validation->setPromptTitle($title);
        $validation->setPrompt($text);
        $validation->setSqref($range);
    }
}