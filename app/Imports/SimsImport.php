<?php

namespace App\Imports;

use App\Models\Sim;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\Importable;

/**
 * Expected columns:
 *   sim_type | sim_number | imsi | iccid | sim_status | activation_required
 *
 * activation_required is optional — accepts 1/0, yes/no, true/false
 * (case-insensitive); anything else/blank is treated as false, same as
 * the single form's unchecked checkbox default.
 *
 * Same uniqueness rules as the single form: sim_number, imsi, and iccid
 * must each be unique across the whole sims table.
 */
class SimsImport implements ToModel, WithHeadingRow, WithValidation, SkipsOnFailure
{
    use Importable, SkipsFailures;

    public array $created = [];

    public function model(array $row)
    {
        $activationRequired = in_array(
            strtolower(trim((string) ($row['activation_required'] ?? ''))),
            ['1', 'yes', 'true'],
            true
        );

        $sim = new Sim([
            'sim_number'           => trim($row['sim_number']),
            'sim_type'             => trim($row['sim_type']),
            'imsi'                 => trim($row['imsi']),
            'iccid'                => trim($row['iccid']),
            'activation_required'  => $activationRequired,
            'sim_status'           => trim($row['sim_status']),
        ]);

        $this->created[] = $sim;

        return $sim;
    }

    public function rules(): array
    {
        return [
            'sim_type'   => ['required', 'string', 'max:255'],
            'sim_number' => ['required', 'digits:10', 'unique:sims,sim_number'],
            'imsi'       => ['required', 'digits:15', 'unique:sims,imsi'],
            'iccid'      => ['required', 'digits_between:19,20', 'unique:sims,iccid'],
            'sim_status' => ['required', 'string', Rule::in(['Activated', 'Not Activated'])],
        ];
    }

    public function customValidationMessages()
    {
        return [
            'sim_number.digits' => 'SIM number must be exactly 10 digits.',
            'sim_number.unique' => 'This SIM number is already registered.',
            'imsi.digits'       => 'IMSI must be exactly 15 digits.',
            'imsi.unique'       => 'This IMSI is already registered.',
            'iccid.digits_between' => 'ICCID must be 19 or 20 digits.',
            'iccid.unique'      => 'This ICCID is already registered.',
            'sim_status.in'     => 'SIM status must be exactly "Activated" or "Not Activated".',
        ];
    }
}