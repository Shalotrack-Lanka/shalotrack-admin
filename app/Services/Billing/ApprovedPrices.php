<?php

namespace App\Services\Billing;

use App\Models\Dealer;
use App\Models\DevicePrice;
use App\Models\DeviceType;
use App\Services\DevicePriceChain;
use Illuminate\Support\Facades\Schema;

/**
 * Links wholesale invoices to the Device Prices page. The price a buyer is expected to pay for one device:
 *
 *   distributor  -> "distributor cost"  (company to distributor, step 1 of the chain)
 *   dealer       -> "retailer price"    (distributor to retailer, step 2 of the chain)
 *
 * Prices are EXCLUDING tax, like invoice prices (tax is added on the invoice from the billing settings).
 * The approved price only PRE-FILLS the invoice: the admin can still change it, and a difference is shown on the
 * review screen. A device type without an approved price gives null (never a guessed number).
 */
class ApprovedPrices
{
    /** @var array<string,int|null>|null  "category model" (lower case) -> device type id */
    private ?array $typeIds = null;

    /**
     * @param  iterable<object{id:int, device_category:string}> $transfers
     * @return array<int,int|null>  transfer id -> approved unit price in cents (or null)
     */
    public function forTransfers(Dealer $buyer, iterable $transfers): array
    {
        if (! $this->available()) {
            $out = [];
            foreach ($transfers as $t) {
                $out[(int) $t->id] = null;
            }

            return $out;
        }

        $step = $buyer->dealer_status === 'distributor' ? 0 : 1;
        $cache = [];
        $out = [];
        foreach ($transfers as $t) {
            $typeId = $this->typeIdFor((string) $t->device_category);
            if ($typeId === null) {
                $out[(int) $t->id] = null;
                continue;
            }
            $out[(int) $t->id] = $cache[$typeId] ??= $this->priceFor($typeId, $step);
        }

        return $out;
    }

    /** What the price is called for this buyer (for screens). */
    public static function labelFor(Dealer $buyer): string
    {
        return $buyer->dealer_status === 'distributor' ? 'distributor cost' : 'retailer price';
    }

    private function priceFor(int $deviceTypeId, int $step): ?int
    {
        $p = DevicePrice::where('device_type_id', $deviceTypeId)->first();
        if (! $p) {
            return null;
        }

        try {
            $chain = DevicePriceChain::build($p->landing_cost, $p->company_margin, $p->distributor_margin, $p->retailer_margin);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return (int) $chain['steps'][$step]['price'];
    }

    /** Transfers store the device as text ("Category Model", as written by device registration). */
    private function typeIdFor(string $category): ?int
    {
        if ($this->typeIds === null) {
            $this->typeIds = [];
            foreach (DeviceType::query()->get(['id', 'device_category', 'model']) as $t) {
                $key = self::key(trim($t->device_category . ' ' . $t->model));
                // Two types with the same name cannot be told apart: no price rather than a wrong one.
                $this->typeIds[$key] = array_key_exists($key, $this->typeIds) ? null : (int) $t->id;
            }
        }

        return $this->typeIds[self::key($category)] ?? null;
    }

    private static function key(string $s): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($s)));
    }

    private function available(): bool
    {
        try {
            return Schema::hasTable('device_prices');
        } catch (\Throwable) {
            return false;
        }
    }
}
