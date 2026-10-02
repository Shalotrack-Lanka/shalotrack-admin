<?php

namespace App\Services;

use App\Models\ActivatedDevice;
use App\Models\CommissionEntry;
use App\Models\Dealer;
use App\Models\PackagePayment;
use App\Models\RenewalPackage;
use App\Models\SetupShalotrackDevice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records package payments and the commission they earn (Renewal Plans guideline v1.0).
 *
 *  - The RETAILER margin goes to the dealer who sold the device (the device's dealer).
 *  - The DISTRIBUTOR margin goes to that dealer's distributor, if one is set.
 *  - Amounts are the price master AS IT WAS when the payment was recorded, frozen on the payment row.
 *  - A package with no price, or a device with no selling dealer, is still recorded (proof of payment)
 *    but earns no commission.
 *  - Undoing a payment never deletes anything: it marks the payment reversed and adds negative lines in
 *    the month of the reversal, so a month that was already paid out is never rewritten.
 *
 * Callers must run inside their own DB transaction so a failure here rolls back the device save too:
 * a payment must never be saved without its commission, or the other way round.
 */
class PackagePayments
{
    public const SOURCE_ACTIVATION = 'activation';
    public const SOURCE_EDIT = 'admin_edit';
    public const SOURCE_REACTIVATION = 'reactivation';

    public const TZ = 'Asia/Colombo';

    /** Records the device's current Paid package. Returns the existing payment if it was already recorded. */
    public function record(ActivatedDevice $device, string $source, ?Carbon $previousExpiry = null): ?PackagePayment
    {
        if ($device->payment_status !== 'Paid' || ! $device->subscription_model || ! $device->subscription_start_date) {
            return null;
        }

        $key = self::eventKey($device);
        $existing = PackagePayment::where('event_key', $key)->first();
        if ($existing) {
            return $existing;
        }

        $package = RenewalPackage::forModel($device->subscription_model);
        $priced = $package && $package->customer_price !== null;

        $retailer = $this->sellingDealer($device->imei_number);
        $distributor = $retailer && $retailer->distributor_id ? Dealer::find($retailer->distributor_id) : null;
        // A distributor link only counts while it points at a real distributor.
        if ($distributor && $distributor->dealer_status !== 'distributor') {
            $distributor = null;
        }

        $user = auth()->user();

        $payment = PackagePayment::create([
            'event_key'             => $key,
            'activated_device_id'   => $device->activated_device_id,
            'imei_number'           => (string) $device->imei_number,
            'vehicle_id'            => $device->vehicle_id,
            'vehicle_number'        => $device->vehicle_number,
            'customer_id'           => $device->customer_id,
            'customer_name'         => $device->customer_name,
            'source'                => $source,
            'package_model'         => $device->subscription_model,
            'package_label'         => $package?->label,
            'months'                => $package?->months,
            'customer_price'        => $priced ? $package->customer_price : null,
            'company_margin'        => $priced ? $package->company_margin : null,
            'distributor_margin'    => $priced ? $package->distributor_margin : null,
            'retailer_margin'       => $priced ? $package->retailer_margin : null,
            'retailer_dealer_id'    => $retailer?->id,
            'distributor_dealer_id' => $distributor?->id,
            'subscription_start'    => $device->subscription_start_date,
            'subscription_end'      => $device->subscription_end_date,
            'previous_expiry'       => $previousExpiry,
            'bank_invoice'          => $device->bank_invoice,
            'recorded_by'           => $user?->admin_id,
            'recorded_by_name'      => $user ? ($user->full_name ?: $user->username) : null,
            'status'                => 'valid',
        ]);

        if ($priced) {
            $this->earn($payment, $retailer, 'retailer', $payment->retailer_margin);
            $this->earn($payment, $distributor, 'distributor', $payment->distributor_margin);
        }

        return $payment;
    }

    /** The payment currently standing for this device and payment details, or null. */
    public function current(ActivatedDevice $device): ?PackagePayment
    {
        return PackagePayment::where('event_key', self::eventKey($device))->first();
    }

    /** Undoes a payment: marks it reversed and writes the negative commission lines today. Safe to call twice. */
    public function reverse(PackagePayment $payment, string $reason): void
    {
        DB::transaction(function () use ($payment, $reason) {
            $locked = PackagePayment::whereKey($payment->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'valid') {
                return;
            }

            $user = auth()->user();
            $locked->update([
                'status'          => 'reversed',
                'event_key'       => null,
                'reversed_at'     => now(),
                'reversed_by'     => $user?->admin_id,
                'reversal_reason' => mb_substr($reason, 0, 255),
            ]);

            $now = now();
            foreach (CommissionEntry::where('payment_id', $locked->id)->where('entry_type', 'earned')->get() as $e) {
                CommissionEntry::create([
                    'payment_id'   => $locked->id,
                    'dealer_id'    => $e->dealer_id,
                    'role'         => $e->role,
                    'entry_type'   => 'clawback',
                    'amount'       => -1 * (float) $e->amount,
                    'period_month' => $now->copy()->setTimezone(self::TZ)->format('Y-m'),
                    'occurred_at'  => $now,
                    'reason'       => mb_substr($reason, 0, 255),
                    'created_at'   => $now,
                ]);
            }
        });
    }

    /** Stable identity of a payment: the same device, package, start date and invoice is the same payment. */
    public static function eventKey(ActivatedDevice $device): string
    {
        return hash('sha256', implode('|', [
            $device->imei_number,
            $device->vehicle_id,
            $device->subscription_model,
            $device->subscription_start_date ? Carbon::parse($device->subscription_start_date)->format('Y-m-d') : '',
            trim((string) $device->bank_invoice),
        ]));
    }

    private function earn(PackagePayment $payment, ?Dealer $to, string $role, $amount): void
    {
        if (! $to || $amount === null || (float) $amount <= 0) {
            return;
        }

        $now = now();
        CommissionEntry::create([
            'payment_id'   => $payment->id,
            'dealer_id'    => $to->id,
            'role'         => $role,
            'entry_type'   => 'earned',
            'amount'       => $amount,
            'period_month' => $now->copy()->setTimezone(self::TZ)->format('Y-m'),
            'occurred_at'  => $now,
            'created_at'   => $now,
        ]);
    }

    private function sellingDealer(?string $imei): ?Dealer
    {
        if (! $imei) {
            return null;
        }

        $dealerId = SetupShalotrackDevice::where('imei_number', $imei)->value('dealer_id');

        return $dealerId ? Dealer::find($dealerId) : null;
    }
}