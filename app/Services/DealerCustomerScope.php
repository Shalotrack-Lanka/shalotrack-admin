<?php

namespace App\Services;

use App\Models\CustomerAd;
use App\Models\Dealer;
use App\Models\DealerCustomerAd;
use App\Models\VehicleAd;
use Illuminate\Support\Facades\DB;

/**
 * Which app-account customers (and therefore vehicles) belong to a dealer.
 *
 * A dealer's leads are matched to app customers by email or phone. This used
 * to be copy-pasted into three controllers, each with the same flaw: a dealer
 * with no usable email/phone (or a lead phone like "n/a", which becomes the
 * pattern LIKE '%') matched EVERY customer, so they could see, and command,
 * every vehicle in the system. It now lives here, once, and fails closed.
 */
class DealerCustomerScope
{
    /** @return list<string> customer_id values that belong to this dealer */
    public function customerIds(Dealer $dealer): array
    {
        $leads = DealerCustomerAd::where('dealer_id', $dealer->id)->get();

        $emails = $leads->pluck('email')->filter()
            ->map(fn ($e) => strtolower(trim($e)))
            ->filter()->unique()->values()->all();

        // Only real phone numbers (>= 9 digits) may be used for matching.
        $phones = $leads->pluck('contact')->filter()
            ->map(fn ($p) => preg_replace('/[^0-9]/', '', (string) $p))
            ->filter(fn ($digits) => strlen($digits) >= 9)
            ->map(fn ($digits) => substr($digits, -9))
            ->unique()->values()->all();

        // Fail closed: nothing to match on means no customers, never all of them.
        if (empty($emails) && empty($phones)) {
            return [];
        }

        return CustomerAd::query()
            ->where(function ($q) use ($emails, $phones) {
                if (!empty($emails)) {
                    $q->whereIn(DB::raw('LOWER(email)'), $emails);
                }
                foreach ($phones as $phone) {
                    $q->orWhere('phone_number', 'LIKE', '%' . $phone);
                }
            })
            ->pluck('customer_id')
            ->all();
    }

    /** Vehicles carrying a device IMEI that belong to this dealer's customers. */
    public function gpsVehicles(Dealer $dealer)
    {
        $ids = $this->customerIds($dealer);

        if (empty($ids)) {
            return VehicleAd::query()->whereRaw('1 = 0')->get();
        }

        return VehicleAd::whereIn('customer_id', $ids)
            ->whereNotNull('imei')
            ->where('imei', '!=', '')
            ->orderBy('vehicle_number')
            ->get();
    }
}
