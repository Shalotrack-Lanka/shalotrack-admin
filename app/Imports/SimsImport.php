<?php

namespace App\Imports;

use App\Models\Sim;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsErrors;

class SimsImport implements ToModel, WithHeadingRow, WithValidation, SkipsOnFailure, SkipsOnError
{
    use SkipsFailures, SkipsErrors;

    public array $created = [];

    public function model(array $row)
    {
        // 'yes', 'y', '1' ආදිය true බවටත්, අනෙකුත් ඒවා false බවටත් හැරවීම
        $activationInput = strtolower(trim($row['activation_required'] ?? 'no'));
        $isActivationRequired = in_array($activationInput, ['yes', 'y', '1', 'true']);

        // Excel හි අංක Scientific Notation වීම වැළැක්වීමට (string) ලෙස Cast කිරීම
        $sim = Sim::create([
            'sim_type'            => trim($row['sim_type']),
            'sim_number'          => trim((string)$row['sim_number']),
            'imsi'                => trim((string)$row['imsi']),
            'iccid'               => trim((string)$row['iccid']),
            'sim_status'          => trim($row['sim_status']),
            'activation_required' => $isActivationRequired,
        ]);

        $this->created[] = $sim->id;

        return $sim;
    }

    public function rules(): array
    {
        return [
            'sim_type'            => ['required', 'string', 'max:255'],
            'sim_number'          => ['required', 'numeric', 'digits:10', 'unique:sims,sim_number'],
            'imsi'                => ['required', 'numeric', 'digits:15', 'unique:sims,imsi'],
            'iccid'               => ['required', 'numeric', 'digits_between:19,20', 'unique:sims,iccid'],
            'sim_status'          => ['required', 'string', 'in:Activated,Not Activated'],
            'activation_required' => ['nullable', 'string'],
        ];
    }
    
    // අවශ්‍ය නම් අමතර Custom Error Messages මෙතනින් ලබා දිය හැක
    public function customValidationMessages()
    {
        return [
            'sim_number.unique' => 'SIM අංකය (:input) දැනටමත් පද්ධතියේ පවතී.',
            'imsi.unique'       => 'IMSI අංකය (:input) දැනටමත් පද්ධතියේ පවතී.',
            'iccid.unique'      => 'ICCID අංකය (:input) දැනටමත් පද්ධතියේ පවතී.',
        ];
    }
}