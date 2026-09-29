<?php

namespace App\Console\Commands;

use App\Models\ActivatedDevice;
use App\Models\ExpiredDevice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireDeviceSubscriptions extends Command
{
    protected $signature = 'devices:expire-subscriptions';

    // FIX 2026-09-29: this command used to delete the activated_devices row
    // and move it into expired_devices, as if the device itself expired.
    // That's wrong per how the business actually works (confirmed directly
    // with the client): a device is never returned to stock just because a
    // subscription lapses -- it stays physically installed and bound to
    // that customer, it's only the SUBSCRIPTION that needs renewing (a
    // repeat payment). Renewal is done by staff editing the SAME
    // activated_devices row via CustomerDeviceManagementController::update(),
    // which requires that row to still exist. Deleting it here silently
    // broke every renewal for any customer whose subscription had lapsed --
    // there is no payment gateway yet, so this is not a hypothetical edge
    // case, it's the only renewal path that exists today. This is also the
    // confirmed root cause of SetupShalotrackDevice rows left stuck at
    // status = 'Activated' with no backing activated_devices record (see
    // IMEI 345697852375151 investigation, 2026-09-29).
    protected $description = 'Marks activated_devices rows whose subscription has lapsed as requiring renewal (payment_status = not-Paid, subscription fields cleared) and logs a matching record to expired_devices for reporting. The live activated_devices row is kept -- the device stays bound to its customer; only the subscription needs renewing via CustomerDeviceManagementController::update().';

    public function handle(): int
    {
        $lapsed = ActivatedDevice::where('payment_status', 'Paid')
            ->whereNotNull('subscription_end_date')
            ->where('subscription_end_date', '<=', now())
            ->get();

        if ($lapsed->isEmpty()) {
            $this->info('No lapsed subscriptions.');
            return self::SUCCESS;
        }

        foreach ($lapsed as $device) {
            DB::transaction(function () use ($device) {
                // Reporting-only log entry. This does NOT replace the live
                // activated_devices row below -- it's a permanent record of
                // "this customer's subscription lapsed on this date", kept
                // for follow-up/reporting even after they eventually renew.
                ExpiredDevice::create([
                    'vehicle_id'              => $device->vehicle_id,
                    'customer_id'             => $device->customer_id,
                    'customer_name'           => $device->customer_name,
                    'vehicle_number'          => $device->vehicle_number,
                    'model'                   => $device->model,
                    'has_gps_device'          => $device->has_gps_device,
                    'imei_number'             => $device->imei_number,
                    'sim_number'              => $device->sim_number,
                    'device_category'         => $device->device_category,
                    'payment_status'          => 'not-Paid',
                    'subscription_model'      => null,
                    'subscription_start_date' => null,
                    'subscription_end_date'   => null,
                    'expired_date'            => $device->subscription_end_date,
                    'bank_invoice'            => null,
                    'bank_slip'               => null,
                    'status'                  => 'Expired',
                ]);

                // FIX: no longer deletes the device. Mirrors the same
                // "not Paid -> null out subscription fields" convention
                // CustomerDeviceManagementController::subscriptionFields()
                // already uses for manual admin edits, so payment_status
                // stays the single signal every system (admin UI, and any
                // future API/app feature-gate) checks to know a renewal is
                // due. Clearing subscription_end_date also makes this
                // idempotent: the query above won't match this row again
                // on the next run until a real renewal sets a new one.
                // 'status' (Activated) is untouched -- the device is still
                // activated and installed, it just needs paying for again.
                $device->update([
                    'payment_status'          => 'not-Paid',
                    'subscription_model'      => null,
                    'subscription_start_date' => null,
                    'subscription_end_date'   => null,
                    'bank_invoice'            => null,
                    'bank_slip'               => null,
                ]);
            });
        }

        $this->info("Marked {$lapsed->count()} device(s) as requiring subscription renewal.");

        return self::SUCCESS;
    }
}