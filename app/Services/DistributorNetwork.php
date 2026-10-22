<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Dealer;
use App\Models\DealerCustomerAd;
use App\Models\DealerTransferLedger;
use App\Models\SetupShalotrackDevice;

/**
 * The slice of the business a distributor may see: itself, the dealers that report to it
 * (dealers.distributor_id = its dealers row) and the stock of those. This is the ONLY place that
 * decides that, so every distributor screen asks here instead of building its own filter.
 *
 * Fails closed: a login that is not an active DISTRIBUTOR pointing at an active distributor-type dealers
 * row has no network at all (forUser() returns null), never "everything".
 */
class DistributorNetwork
{
    private function __construct(private Dealer $distributor)
    {
    }

    public static function forUser(?Admin $user): ?self
    {
        $dealer = $user?->distributorDealer();

        return $dealer ? new self($dealer) : null;
    }

    public function distributor(): Dealer
    {
        return $this->distributor;
    }

    /** Dealers directly under this distributor (never other distributors' dealers, never direct dealers). */
    public function dealers()
    {
        return Dealer::query()->where('distributor_id', $this->distributor->id);
    }

    /** @return list<int> */
    public function dealerIds(): array
    {
        return $this->dealers()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function owns(int $dealerId): bool
    {
        return $this->dealers()->whereKey($dealerId)->exists();
    }

    /** Every device row the distributor itself holds (any status). */
    public function ownDevices()
    {
        return SetupShalotrackDevice::query()->where('dealer_id', $this->distributor->id);
    }

    /** Activated, unsold devices in the distributor's own stock (what it can pass on). */
    public function ownAvailableStock()
    {
        return SetupShalotrackDevice::availableForDealer($this->distributor->id);
    }

    /** Devices held by the dealers under this distributor. */
    public function dealerDevices()
    {
        return SetupShalotrackDevice::query()->whereIn('dealer_id', $this->dealerIds() ?: [0]);
    }

    /** Customer leads registered by the dealers under this distributor (read-only). */
    public function dealerLeads()
    {
        return DealerCustomerAd::query()->whereIn('dealer_id', $this->dealerIds() ?: [0]);
    }

    /** The distributor's own customers plus those of its dealers. */
    public function customerLeads()
    {
        return DealerCustomerAd::query()->whereIn('dealer_id', array_merge([(int) $this->distributor->id], $this->dealerIds()));
    }

    /**
     * Transfer records this distributor may see: the ones it sent to its dealers, and the ones the company
     * sent to it. Never a transfer between anyone else.
     */
    public function transfers()
    {
        $me = $this->distributor->id;

        return DealerTransferLedger::query()->where(function ($q) use ($me) {
            $q->where('from_dealer_id', $me)
                ->orWhere(function ($q2) use ($me) {
                    $q2->where('dealer_id', $me)->whereNull('from_dealer_id');
                });
        });
    }

    public function mayView(DealerTransferLedger $ledger): bool
    {
        return $this->transfers()->whereKey($ledger->getKey())->exists();
    }
}