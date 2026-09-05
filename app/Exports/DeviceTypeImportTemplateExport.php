<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use Maatwebsite\Excel\Concerns\FromArray;

class DeviceTypeImportTemplateExport implements FromArray, WithHeadings, WithEvents
{
    public function headings(): array
    {
        return ['device_category', 'model', 'protocol', 'features'];
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

                $this->addPlaceholder($sheet, 'A2:A1000', 'Device Category', 'e.g. V8 — a new category name');
                $this->addPlaceholder($sheet, 'B2:B1000', 'Model', 'Must be exactly Basic, Plus, or Customize');
                $this->addPlaceholder($sheet, 'C2:C1000', 'Protocol', 'e.g. GT06');
                $this->addPlaceholder($sheet, 'D2:D1000', 'Features (optional)', 'Comma-separated existing feature names, e.g. Geofencing, Ignition Alert');
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