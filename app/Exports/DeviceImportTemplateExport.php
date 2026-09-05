<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class DeviceImportTemplateExport implements FromArray, WithHeadings, WithColumnFormatting
{
    public function headings(): array
    {
        return ['imei_number', 'sim_number', 'device_category', 'model'];
    }

    public function array(): array
    {
        // One real example row so the expected format is obvious from
        // opening the file, not just a guess from bare column headers.
        return [
            ['355172106043787', '0712345678', 'V7', 'Normal'],
        ];
    }

    /**
     * Without this, Excel auto-detects these long digit strings as numbers
     * and silently renders them in scientific notation (3.55E+14) — which
     * corrupts the real value the moment someone edits that cell without
     * first knowing to reformat it as Text themselves.
     */
    public function columnFormats(): array
    {
        return [
            'A' => NumberFormat::FORMAT_TEXT, // imei_number
            'B' => NumberFormat::FORMAT_TEXT, // sim_number
        ];
    }
}