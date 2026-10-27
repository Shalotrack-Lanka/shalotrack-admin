<?php

namespace Tests\Pgsql\Concerns;

use App\Models\ActivatedDevice;
use App\Models\Admin;
use App\Models\CustomerAd;
use App\Models\Dealer;
use App\Models\ExpiredDevice;
use App\Models\SetupShalotrackDevice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Shared helpers for the report-page suites (renewals, installs and warranty, audit log, PDFs).
 * A class that uses this trait must also use BuildsIntakeData (nextIntakeNumber, validImei) and CreatesTestAdmins.
 * Every row is rolled back by DatabaseTransactions.
 */
trait BuildsReportData
{
    protected function quietApi(): void
    {
        // The admin sidebar asks the app server for the complaint count on every page.
        Http::fake(['*' => Http::response(['data' => []], 200)]);
    }

    protected function makeDealer(array $overrides = []): Dealer
    {
        $n = $this->nextIntakeNumber();

        return Dealer::create(array_merge([
            'full_name'     => "Autotest Report Dealer {$n}",
            'dealer_status' => 'retailer',
            'region'        => 'colombo',
            'country'       => 'Sri Lanka',
            'contact_email' => "autotest+reportdealer{$n}@example.test",
            'status'        => 'active',
            'created_by'    => 'autotest',
        ], $overrides));
    }

    protected function makeDealerLogin(?Dealer $dealer = null): Admin
    {
        return $this->makeAdmin('DEALER', ['dealer_id' => $dealer?->id]);
    }

    /**
     * A device bound to a vehicle. When a dealer is given, the physical device row says that dealer holds it
     * (this is the link the renewals page uses). Dates are given as Carbon values or null.
     */
    protected function makeBound(array $overrides = [], ?Dealer $dealer = null): ActivatedDevice
    {
        $n = $this->nextIntakeNumber();
        $imei = $overrides['imei_number'] ?? $this->validImei();

        if ($dealer) {
            SetupShalotrackDevice::create([
                'device_category' => 'AUTOTEST-CAT',
                'imei_number'     => $imei,
                'sim_number'      => '07' . str_pad((string) $n, 9, '0', STR_PAD_LEFT),
                'status'          => 'Activated',
                'dealer_id'       => $dealer->id,
            ]);
        }

        return ActivatedDevice::create(array_merge([
            'vehicle_id'         => (string) Str::uuid(),
            'customer_id'        => (string) Str::uuid(),
            'customer_name'      => "Autotest Customer {$n}",
            'vehicle_number'     => "AUTO-R{$n}",
            'model'              => 'Autotest Model',
            'has_gps_device'     => true,
            'imei_number'        => $imei,
            'device_category'    => 'AUTOTEST-CAT',
            'status'             => 'Activated',
            'payment_status'     => 'not-Paid',
            'warranty_months'    => 0,
        ], $overrides, ['imei_number' => $imei]));
    }

    protected function makeCustomerRecord(ActivatedDevice $device, string $phone): CustomerAd
    {
        return CustomerAd::create([
            'customer_id'  => $device->customer_id,
            'full_name'    => $device->customer_name,
            'phone_number' => $phone,
        ]);
    }

    protected function makeExpiredRecord(ActivatedDevice $device, string $date): ExpiredDevice
    {
        return ExpiredDevice::create([
            'vehicle_id'     => $device->vehicle_id,
            'customer_id'    => $device->customer_id,
            'customer_name'  => $device->customer_name,
            'vehicle_number' => $device->vehicle_number,
            'imei_number'    => $device->imei_number,
            'payment_status' => 'not-Paid',
            'expired_date'   => $date,
        ]);
    }

    /** Reads the whole body of a streamed CSV download. */
    protected function csvBody($response): array
    {
        ob_start();
        $response->sendContent();
        $text = ob_get_clean();

        return array_map('str_getcsv', array_values(array_filter(preg_split('/\r?\n/', $text), fn ($l) => $l !== '')));
    }
}