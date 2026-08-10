<?php

namespace App\Console\Commands;

use App\Models\ActivatedDevice;
use App\Models\ExpiredDevice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireDeviceSubscriptions extends Command
{
    protected $signature = 'devices:expire-subscriptions';

    protected $description = 'Moves activated_devices rows whose subscription has lapsed into expired_devices. IMEI, SIM and device category are carried over untouched; payment/subscription fields are cleared.';

    public function handle(): int
    {
        $lapsed = ActivatedDevice::whereNotNull('subscription_end_date')
            ->where('subscription_end_date', '<=', now())
            ->get();

        if ($lapsed->isEmpty()) {
            $this->info('No lapsed subscriptions.');
            return self::SUCCESS;
        }

        foreach ($lapsed as $device) {
            DB::transaction(function () use ($device) {
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
                    'bank_invoice'            => null,
                    'bank_slip'               => null,
                    'status'                  => 'Expired',
                ]);

                $device->delete();
            });
        }

        $this->info("Moved {$lapsed->count()} device(s) to expired_devices.");

        return self::SUCCESS;
    }
}
