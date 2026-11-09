<?php

namespace App\Services;

use App\Support\Money;

/**
 * Turns the four decided amounts of a device into the whole price chain. Pure arithmetic (no database, no
 * request), in integer cents like the billing code, so the same inputs always give the same screen and the
 * same invoice figures.
 *
 *   landing + company margin        = distributor cost   (what the distributor pays the company)
 *   + distributor margin            = retailer price     (what the retailer pays the distributor)
 *   + retailer margin               = customer price     (what the customer pays the retailer)
 *
 * Margins are shown two ways because people mean both: "markup" is the margin as a percentage of what the
 * seller paid (cost), "share" is the margin as a percentage of what the buyer pays (selling price). A markup
 * of 100% is a share of 50%; they are never the same number, so neither is hidden.
 *
 * Tax is optional and comes from the billing settings (never a constant here). Prices are stored excluding
 * tax; with a rate, each SALE in the chain (company to distributor, distributor to retailer, retailer to
 * customer) shows its tax and its price including tax. Margins are always excluding tax.
 */
final class DevicePriceChain
{
    /**
     * @param  string|int|float $landing      landing cost, excluding tax (e.g. "1000.00")
     * @param  string|null      $taxRate      percent like "18.00", or null / "0" for no tax
     * @return array{
     *   steps: array<int,array<string,mixed>>,
     *   customer_total: int,
     *   total_margin: int,
     *   tax_rate: ?string
     * }
     */
    public static function build(
        string|int|float $landing,
        string|int|float $companyMargin,
        string|int|float $distributorMargin,
        string|int|float $retailerMargin,
        ?string $taxRate = null,
    ): array {
        $landingC = Money::toCents($landing);
        $companyC = Money::toCents($companyMargin);
        $distributorC = Money::toCents($distributorMargin);
        $retailerC = Money::toCents($retailerMargin);

        if ($landingC <= 0) {
            throw new \InvalidArgumentException('The landing cost must be more than zero.');
        }

        $rate = self::normaliseRate($taxRate);

        $distributorCost = $landingC + $companyC;
        $retailerPrice = $distributorCost + $distributorC;
        $customerPrice = $retailerPrice + $retailerC;

        $steps = [
            self::step('company', 'Company to distributor', $landingC, $companyC, $distributorCost, $rate),
            self::step('distributor', 'Distributor to retailer', $distributorCost, $distributorC, $retailerPrice, $rate),
            self::step('retailer', 'Retailer to customer', $retailerPrice, $retailerC, $customerPrice, $rate),
        ];

        return [
            'steps'          => $steps,
            'customer_total' => $customerPrice,
            'total_margin'   => $companyC + $distributorC + $retailerC,
            'tax_rate'       => $rate,
        ];
    }

    /** One sale in the chain. */
    private static function step(string $key, string $label, int $cost, int $margin, int $price, ?string $rate): array
    {
        $tax = $rate !== null ? Money::percentOf($price, $rate) : 0;

        return [
            'key'          => $key,
            'label'        => $label,
            'cost'         => $cost,                                  // what the seller paid, excluding tax
            'margin'       => $margin,
            'price'        => $price,                                 // what the buyer pays, excluding tax
            'tax'          => $tax,
            'price_incl'   => $price + $tax,
            'markup_bp'    => self::basisPoints($margin, $cost),      // margin / cost
            'share_bp'     => self::basisPoints($margin, $price),     // margin / selling price
        ];
    }

    /** margin as hundredths of a percent of base, rounded half up; null when the base is zero. */
    private static function basisPoints(int $amount, int $base): ?int
    {
        if ($base <= 0) {
            return null;
        }

        return intdiv($amount * 10000 * 2 + $base, $base * 2);
    }

    /** "1250" basis points -> "12.50" (for screens). */
    public static function percent(?int $basisPoints): string
    {
        return $basisPoints === null ? '-' : Money::fromCents($basisPoints) . '%';
    }

    /** A usable tax rate string ("18.00"), or null when tax is off / zero / not a valid percentage. */
    private static function normaliseRate(?string $rate): ?string
    {
        if ($rate === null || trim($rate) === '') {
            return null;
        }

        try {
            $cents = Money::toCents($rate);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $cents > 0 ? Money::fromCents($cents) : null;
    }
}
