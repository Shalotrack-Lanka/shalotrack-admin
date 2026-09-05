<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class SimImportTemplateExport implements FromArray, WithHeadings, WithColumnFormatting
{
    public function headings(): array
    {
        return ['sim_type', 'sim_number', 'imsi', 'iccid', 'sim_status', 'activation_required'];
    }

    public function array(): array
    {
        return [
            ['dialog', '0771234567', '413010123456789', '8944123456789012345', 'Not Activated', 'no'],
        ];
    }

    public function columnFormats(): array
    {
        return [
            'B' => NumberFormat::FORMAT_TEXT, // sim_number
            'C' => NumberFormat::FORMAT_TEXT, // imsi
            'D' => NumberFormat::FORMAT_TEXT, // iccid — 19-20 digits, would definitely corrupt otherwise
        ];
    }
}