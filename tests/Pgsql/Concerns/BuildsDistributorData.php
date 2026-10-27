<?php

namespace Tests\Pgsql\Concerns;

use App\Enums\DeviceStatus;
use App\Models\Admin;
use App\Models\Dealer;
use App\Models\DealerCustomerAd;
use App\Models\DealerTransferItem;
use App\Models\DealerTransferLedger;
use App\Models\SetupShalotrackDevice;
use Illuminate\Support\Carbon;

/**
 * Rows for the distributor portal page tests. Everything is tagged (names start with "Autotest", IMEI starts with
 * 99900000 7xx) and rolled back by DatabaseTransactions in the test class.
 * One login per test: a second login in the same test has another password hash and the session is dropped.
 */
trait BuildsDistributorData
{
    private int $seq = 0;
    private int $imeiSeq = 700;
    private ?Admin $login = null;

    private function distributor(string $name, string $status = 'active'): Dealer
    {
        return $this->makeDealerRow($name, 'distributor', null, $status);
    }

    private function dealerUnder(Dealer $distributor, string $name, string $status = 'active', string $region = 'colombo'): Dealer
    {
        return $this->makeDealerRow($name, 'retailer', $distributor, $status, $region);
    }

    private function makeDealerRow(string $name, string $type, ?Dealer $parent, string $status, string $region = 'colombo'): Dealer
    {
        $k = ++$this->seq;

        return Dealer::create([
            'full_name'      => $name,
            'dealer_status'  => $type,
            'distributor_id' => $parent?->id,
            'region'         => $region,
            'country'        => 'Sri Lanka',
            'contact_email'  => "autotest+dp-{$k}-" . substr(md5(uniqid('', true)), 0, 6) . '@example.test',
            'status'         => $status,
            'created_by'     => 'autotest',
        ]);
    }

    private function asDistributor(Dealer $distributor): Admin
    {
        return $this->login = $this->makeAdmin('DISTRIBUTOR', ['dealer_id' => $distributor->id]);
    }

    private function lead(Dealer $holder, string $name = null): DealerCustomerAd
    {
        $k = ++$this->seq;

        return DealerCustomerAd::create([
            'dealer_id' => $holder->id, 'name' => $name ?? 'Autotest Lead ' . $k,
            'email' => "autotest+lead-{$k}-" . substr(md5(uniqid('', true)), 0, 6) . '@example.test',
            'contact' => '07' . str_pad((string) (60000000 + $k), 8, '0', STR_PAD_LEFT),
            'no_of_devices' => 0, 'imei_numbers' => [],
        ]);
    }

    /** A device held by $holder. $sold = a lead it was sold to. */
    private function device(?Dealer $holder, string $category = 'AUTOTEST-CAT-X', string $status = null, ?DealerCustomerAd $sold = null): SetupShalotrackDevice
    {
        $n = (string) $this->imeiSeq++;

        $device = SetupShalotrackDevice::create([
            'device_category' => $category,
            'imei_number'     => '99900000' . $n,
            'sim_number'      => '07200000' . $n,
            'iccid'           => '8994200000' . $n,
            'status'          => $status ?? ($sold ? DeviceStatus::AssignedToCustomer->value : DeviceStatus::Activated->value),
            'dealer_id'       => $holder?->id,
        ]);

        if ($sold) {
            $device->forceFill(['assigned_customer_id' => $sold->id])->save();
        }

        return $device;
    }

    /** A transfer record. $from = null means the company sent it. */
    private function ledger(Dealer $to, ?Dealer $from, string $category = 'AUTOTEST-CAT-X', int $qty = 1, ?string $when = null, array $devices = []): DealerTransferLedger
    {
        $ledger = DealerTransferLedger::create([
            'dealer_id' => $to->id, 'from_dealer_id' => $from?->id, 'device_category' => $category,
            'quantity' => $qty, 'created_by_admin_id' => 'autotest',
        ]);

        if ($when !== null) {
            $ledger->forceFill(['created_at' => Carbon::parse($when), 'updated_at' => Carbon::parse($when)])->save();
        }

        foreach ($devices as $d) {
            DealerTransferItem::create(['ledger_id' => $ledger->id, 'device_id' => $d->shdevice_id, 'imei_number' => $d->imei_number, 'sim_number' => $d->sim_number]);
        }

        return $ledger;
    }

    /** @return list<string> the IMEI numbers on the paginated "devices" of a stock page response */
    private function imeis($response): array
    {
        return collect($response->viewData('devices')->items())->pluck('imei_number')->all();
    }
}