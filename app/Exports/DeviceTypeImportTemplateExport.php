<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class DeviceTypeImportTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return ['device_category', 'model', 'protocol', 'features'];
    }

    public function array(): array
    {
        return [
            ['V8', 'Basic', 'GT06', 'Geofencing, Ignition Alert'],
        ];
    }
}