<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use Maatwebsite\Excel\Concerns\FromArray;

/**
 * No literal sample data anywhere in this file — the cells are genuinely
 * empty. Instead, each column gets an Excel "input message": click any
 * cell in that column and a small tooltip pops up showing the expected
 * format, exactly like an HTML placeholder. It's just a hint, not real
 * content, so there's nothing to accidentally leave in and upload, and
 * nothing that can succeed-once-then-permanently-block-reuse the way a
 * real-looking example row used to.
 */
class DeviceImportTemplateExport implements FromArray, WithHeadings, WithEvents
{
    public function headings(): array
    {
        return ['imei_number', 'sim_number', 'device_category', 'model'];
    }

    public function array(): array
    {
        // Genuinely no data rows.
        return [];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Text format pre-applied across 1000 rows BEFORE any data
                // is typed — this is what actually prevents Excel from
                // stripping leading zeros or silently rounding a 15-digit
                // IMEI due to its 15-significant-digit precision limit.
                $sheet->getStyle('A2:A1000')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
                $sheet->getStyle('B2:B1000')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

                $this->addPlaceholder($sheet, 'A2:A1000', 'IMEI Number', 'e.g. 355172106043787 — exactly 15 digits');
                $this->addPlaceholder($sheet, 'B2:B1000', 'SIM Number (optional)', 'e.g. 89144000000000000001');
                $this->addPlaceholder($sheet, 'C2:C1000', 'Device Category', 'e.g. V7 — must match an existing Device Type');
                $this->addPlaceholder($sheet, 'D2:D1000', 'Model', 'e.g. Normal — must match that category\'s existing model');
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