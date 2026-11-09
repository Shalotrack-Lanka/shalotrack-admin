<?php

namespace App\Support;

/** Identifier format checks shared by the delivery (goods receipt) code. */
final class Imei
{
    /** 15 digits + Luhn check digit (catches virtually every scanner misread). */
    public static function isValid(string $imei): bool
    {
        if (! preg_match('/^\d{15}$/', $imei)) {
            return false;
        }

        $sum = 0;
        foreach (array_reverse(str_split($imei)) as $i => $ch) {
            $d = (int) $ch;
            if ($i % 2 === 1) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }

        return $sum % 10 === 0;
    }

    /** A SIM ICCID is 19 or 20 digits (the same rule the SIM pages already use). */
    public static function isValidIccid(string $iccid): bool
    {
        return (bool) preg_match('/^\d{19,20}$/', $iccid);
    }
}
