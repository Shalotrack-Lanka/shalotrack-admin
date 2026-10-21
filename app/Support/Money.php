<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money helpers. All arithmetic is done in integer cents (never float), so 0.1 + 0.2 is exactly 0.30
 * and totals can be proven. Database columns are decimal(14,2) strings like "1250.50".
 */
final class Money
{
    /** "1,250.5" / "1250.50" / 1250.5 -> 125050. Rejects more than 2 decimals, negatives and junk. */
    public static function toCents(string|int|float|null $value): int
    {
        $v = trim(str_replace([',', ' '], '', (string) ($value ?? '')));
        if (! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $v)) {
            throw new InvalidArgumentException('Invalid amount');
        }
        [$whole, $frac] = array_pad(explode('.', $v, 2), 2, '0');

        return ((int) $whole) * 100 + (int) str_pad($frac, 2, '0');
    }

    /** A decimal read back from the database (string or float, depending on the driver) -> cents. */
    public static function dbToCents(string|int|float|null $value): int
    {
        return (int) round(((float) ($value ?? 0)) * 100);
    }

    /** 125050 -> "1250.50" (for the database). */
    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    /** 125050 -> "1,250.50" (for screens). */
    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign . number_format(intdiv($abs, 100)) . sprintf('.%02d', $abs % 100);
    }

    /** cents x rate% rounded half up, rate as a string like "8.00" (basis points avoid floats). */
    public static function percentOf(int $cents, string $rate): int
    {
        $basisPoints = self::toCents($rate);   // "8.00" -> 800 (hundredths of a percent)

        return intdiv($cents * $basisPoints + 5000, 10000);
    }
}
