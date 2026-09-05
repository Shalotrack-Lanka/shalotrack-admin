<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use Maatwebsite\Excel\Concerns\FromArray;

class SimImportTemplateExport implements FromArray, WithHeadings, WithEvents
{
    public function headings(): array
    {
        return ['sim_type', 'sim_number', 'imsi', 'iccid', 'sim_status', 'activation_required'];
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

                // sim_number, imsi, iccid are all long digit strings — same
                // leading-zero/precision-loss risk as IMEI.
                foreach (['B', 'C', 'D'] as $column) {
                    $sheet->getStyle("{$column}2:{$column}1000")
                        ->getNumberFormat()
                        ->setFormatCode(NumberFormat::FORMAT_TEXT);
                }

                $this->addPlaceholder($sheet, 'A2:A1000', 'SIM Type', 'e.g. dialog');
                $this->addPlaceholder($sheet, 'B2:B1000', 'SIM Number', 'e.g. 0771234567 — exactly 10 digits');
                $this->addPlaceholder($sheet, 'C2:C1000', 'IMSI', 'e.g. 413010123456789 — exactly 15 digits');
                $this->addPlaceholder($sheet, 'D2:D1000', 'ICCID', 'e.g. 8944123456789012345 — 19 or 20 digits');
                $this->addPlaceholder($sheet, 'E2:E1000', 'SIM Status', 'Exactly "Activated" or "Not Activated"');
                $this->addPlaceholder($sheet, 'F2:F1000', 'Activation Required (optional)', 'yes or no — blank counts as no');
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