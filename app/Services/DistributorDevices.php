<?php

namespace App\Services;

use App\Enums\DeviceStatus;
use App\Http\Controllers\Admin\Customer\CustomerDeviceManagementController as Admin;
use App\Models\ActivatedDevice;
use App\Models\DealerCustomerAd;
use App\Models\RenewalPackage;
use App\Models\SetupShalotrackDevice;
use App\Models\VehicleAd;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What a distributor may do with devices: bind a device in its network to a customer vehicle, choose a
 * plan, record the subscription payment and renew it later.
 *
 * Boundaries (every method enforces them, nothing relies on the screen hiding things):
 *  - a device is in the network only when the distributor itself or one of ITS dealers holds it;
 *  - a dealer's unsold stock is the dealer's to sell: only devices a dealer has already assigned to a
 *    customer (or the distributor's own stock) can be bound;
 *  - the vehicle must belong to the customer the device was sold to (own stock: one of the distributor's
 *    own registered customers), matched by email/phone through DealerCustomerScope, which fails closed;
 *  - the price, margins and warranty come from the pricing master, the distributor types no amount;
 *  - the period always starts today (Sri Lanka) or at the current expiry, so nothing can be backdated, and a
 *    renewal never resets the original activation date or shortens the warranty;
 *  - the payment goes through PackagePayments, so the retailer / distributor margin rules are unchanged.
 * Anything outside the network behaves as "not found". Correcting or reversing a payment, replacing a
 * device and handing out discounts stay with the company.
 */
class DistributorDevices
{
    public function __construct(private PackagePayments $payments, private DealerCustomerScope $scope)
    {
    }

    /** @return list<int> distributor + its dealers */
    public function holderIds(DistributorNetwork $n): array
    {
        return array_merge([(int) $n->distributor()->id], $n->dealerIds());
    }

    /** Devices that can be bound right now, with the lead each one was sold to (if any). */
    public function readyDevices(DistributorNetwork $n)
    {
        $me = (int) $n->distributor()->id;

        return SetupShalotrackDevice::query()
            ->whereIn('dealer_id', $this->holderIds($n))
            ->whereNotIn('imei_number', fn ($q) => $q->select('imei_number')->from('activated_devices'))
            ->where(function ($q) use ($me) {
                $q->where('status', DeviceStatus::AssignedToCustomer->value)
                    ->orWhere(function ($own) use ($me) {
                        $own->where('dealer_id', $me)
                            ->where('status', DeviceStatus::Activated->value)
                            ->where(fn ($u) => $u->whereNull('assigned_customer_id')->orWhere('assigned_customer_id', 0));
                    });
            });
    }

    public function findReady(DistributorNetwork $n, int|string $shdeviceId, bool $lock = false): SetupShalotrackDevice
    {
        $q = $this->readyDevices($n)->where('shdevice_id', $shdeviceId);
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->first() ?? throw (new ModelNotFoundException())->setModel(SetupShalotrackDevice::class);
    }

    /** The customer leads a device may be bound for. */
    public function leadsFor(DistributorNetwork $n, SetupShalotrackDevice $device): Collection
    {
        $me = (int) $n->distributor()->id;

        if ((int) $device->assigned_customer_id > 0) {
            // Sold device: only the customer it was sold to, and only if that lead belongs to the holder.
            return DealerCustomerAd::query()
                ->whereKey($device->assigned_customer_id)
                ->where('dealer_id', $device->dealer_id)
                ->get();
        }

        return DealerCustomerAd::query()->where('dealer_id', $me)->get();
    }

    /** Vehicles (not yet bound) of the customers this device may be bound for. */
    public function vehiclesFor(DistributorNetwork $n, SetupShalotrackDevice $device)
    {
        $customerIds = $this->scope->customerIdsForLeads($this->leadsFor($n, $device));

        return VehicleAd::query()
            ->whereIn('customer_id', $customerIds ?: ['__none__'])
            ->whereNotIn('vehicle_id', fn ($q) => $q->select('vehicle_id')->from('activated_devices'))
            ->orderBy('vehicle_number');
    }

    /** Bound devices of the network (own stock sold directly + dealers' sales). */
    public function activeDevices(DistributorNetwork $n)
    {
        return ActivatedDevice::query()->whereIn('imei_number', function ($q) use ($n) {
            $q->select('imei_number')->from('setup_shalotrack_devices')->whereIn('dealer_id', $this->holderIds($n));
        });
    }

    public function findActive(DistributorNetwork $n, int|string $activatedId): ActivatedDevice
    {
        return $this->activeDevices($n)->where('activated_device_id', $activatedId)->first()
            ?? throw (new ModelNotFoundException())->setModel(ActivatedDevice::class);
    }

    /** Plans a distributor may sell: active, priced, and known to the subscription code. */
    public function plans(): Collection
    {
        return RenewalPackage::query()
            ->where('is_active', true)->whereNotNull('customer_price')
            ->whereIn('model', Admin::SUBSCRIPTION_MODELS)
            ->orderBy('sort_order')->get();
    }

    private function plan(string $model): RenewalPackage
    {
        $plan = $this->plans()->firstWhere('model', $model);
        if (! $plan) {
            throw ValidationException::withMessages(['plan' => 'This plan is not on offer. Choose one from the list.']);
        }

        return $plan;
    }

    private function today(): string
    {
        return now()->setTimezone(PackagePayments::TZ)->format('Y-m-d');
    }

    private function endOf(string $start, string $model): Carbon
    {
        return Carbon::parse($start)->startOfDay()->addDays(Admin::SUBSCRIPTION_DAYS[$model] ?? 0);
    }

    /** Bind a ready device to a vehicle, with the plan paid for. Returns the new activated_devices row. */
    public function activate(DistributorNetwork $n, int|string $shdeviceId, string $vehicleId, array $in): ActivatedDevice
    {
        $plan = $this->plan($in['plan']);

        $bound = DB::transaction(function () use ($n, $shdeviceId, $vehicleId, $in, $plan) {
            $device = $this->findReady($n, $shdeviceId, lock: true);

            $vehicle = $this->vehiclesFor($n, $device)->where('vehicle_id', $vehicleId)->first()
                ?? throw (new ModelNotFoundException())->setModel(VehicleAd::class);

            if (ActivatedDevice::where('vehicle_id', $vehicle->vehicle_id)->exists()) {
                throw ValidationException::withMessages(['vehicle_id' => "{$vehicle->vehicle_number} already has a device."]);
            }

            $start = $this->today();

            $row = ActivatedDevice::create([
                'vehicle_id'      => $vehicle->vehicle_id,
                'customer_id'     => $vehicle->customer_id,
                'customer_name'   => $vehicle->customer_name,
                'vehicle_number'  => $vehicle->vehicle_number,
                'model'           => $vehicle->model,
                'has_gps_device'  => $vehicle->has_gps_device,
                'imei_number'     => $device->imei_number,
                'sim_number'      => $device->sim_number,
                'device_category' => $device->device_category,
                'status'          => DeviceStatus::Activated->value,
                'installed_on'    => $in['installed_on'] ?? null,
                'installed_by'    => $in['installed_by'] ?? null,
                // The warranty clock starts here and nothing later moves it.
                'original_activated_at'   => now(),
                'warranty_months'         => (int) $plan->warranty_months,
                'payment_status'          => 'Paid',
                'subscription_model'      => $plan->model,
                'subscription_start_date' => Carbon::parse($start)->startOfDay(),
                'subscription_end_date'   => $this->endOf($start, $plan->model),
                'bank_invoice'            => $in['bank_invoice'],
            ]);

            $device->status = DeviceStatus::Activated->value;
            $device->save();

            // Same transaction: never Paid without its payment record and commission.
            $this->payments->record($row, PackagePayments::SOURCE_DISTRIBUTOR);

            return $row;
        });

        Audit::record('distributor.device_activated', 'vehicle', $bound->vehicle_id, $bound->vehicle_number, [], [
            'imei'         => $bound->imei_number,
            'customer'     => $bound->customer_name,
            'plan'         => $plan->model,
            'price_lkr'    => $plan->customer_price,
            'bank_invoice' => $bound->bank_invoice,
            'distributor'  => $n->distributor()->full_name,
            'start'        => $bound->subscription_start_date?->format('Y-m-d'),
            'end'          => $bound->subscription_end_date?->format('Y-m-d'),
        ]);

        return $bound;
    }

    /** Record a new paid period on a bound device of the network. Never touches the activation date or shrinks the warranty. */
    public function renew(DistributorNetwork $n, int|string $activatedId, array $in): ActivatedDevice
    {
        $plan = $this->plan($in['plan']);

        [$row, $previousExpiry] = DB::transaction(function () use ($n, $activatedId, $in, $plan) {
            $this->findActive($n, $activatedId);
            $row = ActivatedDevice::whereKey($activatedId)->lockForUpdate()->firstOrFail();

            $previousExpiry = $row->payment_status === 'Paid' ? $row->subscription_end_date : null;

            // A running subscription keeps the days already paid for: the new period starts at its expiry.
            $start = $this->today();
            if ($previousExpiry && $previousExpiry->isFuture()) {
                $start = $previousExpiry->copy()->startOfDay()->format('Y-m-d');
            }

            $row->update([
                'payment_status'          => 'Paid',
                'subscription_model'      => $plan->model,
                'subscription_start_date' => Carbon::parse($start)->startOfDay(),
                'subscription_end_date'   => $this->endOf($start, $plan->model),
                'bank_invoice'            => $in['bank_invoice'],
                'bank_slip'               => null,
                // Warranty only ever grows (Renewal Plans guideline, section 3).
                'warranty_months'         => max((int) $row->warranty_months, (int) $plan->warranty_months),
            ]);

            $this->payments->record($row, PackagePayments::SOURCE_DISTRIBUTOR_RENEWAL, $previousExpiry);

            return [$row, $previousExpiry];
        });

        Audit::record('distributor.subscription_renewed', 'vehicle', $row->vehicle_id, $row->vehicle_number, [], [
            'imei'            => $row->imei_number,
            'customer'        => $row->customer_name,
            'plan'            => $plan->model,
            'price_lkr'       => $plan->customer_price,
            'bank_invoice'    => $row->bank_invoice,
            'previous_expiry' => $previousExpiry?->format('Y-m-d'),
            'new_expiry'      => $row->subscription_end_date?->format('Y-m-d'),
            'distributor'     => $n->distributor()->full_name,
        ]);

        return $row;
    }

    /** What the customer pays and when the period would run, for the confirmation line on the form. */
    public function preview(string $model, ?Carbon $currentEnd = null): array
    {
        $plan = $this->plan($model);
        $start = ($currentEnd && $currentEnd->isFuture()) ? $currentEnd->copy()->startOfDay()->format('Y-m-d') : $this->today();

        return ['plan' => $plan, 'start' => $start, 'end' => $this->endOf($start, $model)->format('Y-m-d')];
    }
}