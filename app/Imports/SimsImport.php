<?php

namespace App\Imports;

use App\Models\Sim;
use App\Services\Delivery\DeliveryPolicy;
use App\Services\Delivery\SupplierDeliveryService;
use Illuminate\Support\Facades\DB;
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

    /** SIMs added although they were not received on a supplier delivery (warning phase). */
    public int $unverified = 0;

    public function model(array $row)
    {
        // Convert 'yes', 'y', '1' etc. to true and everything else to false
        $activationInput = strtolower(trim($row['activation_required'] ?? 'no'));
        $isActivationRequired = in_array($activationInput, ['yes', 'y', '1', 'true']);

        $deliveries = app(SupplierDeliveryService::class);

        // Cast to string so Excel numbers do not turn into scientific notation
        $sim = DB::transaction(function () use ($row, $isActivationRequired, $deliveries) {
            // Supplier delivery check under lock (no-op while the feature is off); see DeliveryPolicy.
            $provenance = $deliveries->verify('SIM', trim((string) $row['iccid']), null, 'iccid', trim((string) $row['sim_number']));

            $sim = Sim::create([
                'sim_type'            => trim($row['sim_type']),
                'sim_number'          => trim((string)$row['sim_number']),
                'imsi'                => trim((string)$row['imsi']),
                'iccid'               => trim((string)$row['iccid']),
                'sim_status'          => trim($row['sim_status']),
                'activation_required' => $isActivationRequired,
            ]);

            $deliveries->stamp($provenance['unit'], (string) $sim->id);

            if ($provenance['status'] === DeliveryPolicy::UNVERIFIED) {
                $this->unverified++;
            }

            return $sim;
        });

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
            'activation_required' => ['nullable', 'string', 'max:10'],
        ];
    }
    
    /**
     * From the enforcement date, a SIM that was not received on a supplier delivery is reported as a failed row
     * (no-op while the feature is off or before that date).
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            foreach ($validator->getData() as $rowNumber => $data) {
                if (! is_scalar($data['iccid'] ?? null) || ! preg_match('/^\d{19,20}$/', trim((string) $data['iccid']))) {
                    continue;
                }

                $number = is_scalar($data['sim_number'] ?? null) ? trim((string) $data['sim_number']) : null;
                $p = app(SupplierDeliveryService::class)->preview('SIM', trim((string) $data['iccid']), null, $number);
                if ($p['status'] === DeliveryPolicy::MISMATCH) {
                    // The SIM was received with a different SIM number.
                    $validator->errors()->add("{$rowNumber}.sim_number", $p['message']);
                } elseif (in_array($p['status'], [DeliveryPolicy::BLOCKED, DeliveryPolicy::REGISTERED], true)) {
                    $validator->errors()->add("{$rowNumber}.iccid", $p['message']);
                }
            }
        });
    }

    // Extra custom error messages can be given here if needed
    public function customValidationMessages()
    {
        return [
            'sim_number.unique' => 'The SIM number (:input) already exists in the system.',
            'imsi.unique'       => 'The IMSI number (:input) already exists in the system.',
            'iccid.unique'      => 'The ICCID number (:input) already exists in the system.',
        ];
    }
}