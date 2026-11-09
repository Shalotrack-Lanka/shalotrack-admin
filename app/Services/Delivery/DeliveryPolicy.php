<?php

namespace App\Services\Delivery;

/**
 * The single rule that decides what happens when a device or SIM is registered, given what the delivery
 * records say about it. Pure (no database), so the same rule is used by every registration path (manual setup,
 * scan, bulk import, SIM pages) and is fully unit tested.
 *
 *   off          the feature is switched off: nothing is checked
 *   ok           received on a delivery (and, for a device, as the same device type): allowed
 *   unverified   not on any delivery, before the enforcement date: allowed with a warning
 *   blocked      not on any delivery, from the enforcement date on: refused
 *   mismatch     received as a different device type than the one being registered: refused
 *   registered   this unit has already been registered: refused
 */
final class DeliveryPolicy
{
    public const OFF = 'off';
    public const OK = 'ok';
    public const UNVERIFIED = 'unverified';
    public const BLOCKED = 'blocked';
    public const MISMATCH = 'mismatch';
    public const REGISTERED = 'registered';

    public static function decide(
        bool $enabled,
        ?string $enforceFrom,
        string $today,
        bool $unitExists,
        bool $unitRegistered,
        ?int $unitDeviceTypeId,
        ?int $chosenDeviceTypeId,
    ): string {
        if (! $enabled) {
            return self::OFF;
        }

        if ($unitExists) {
            if ($unitRegistered) {
                return self::REGISTERED;
            }
            if ($chosenDeviceTypeId !== null && $unitDeviceTypeId !== null && $unitDeviceTypeId !== $chosenDeviceTypeId) {
                return self::MISMATCH;
            }

            return self::OK;
        }

        return self::enforcing($enforceFrom, $today) ? self::BLOCKED : self::UNVERIFIED;
    }

    /** True only for a real YYYY-MM-DD date that is today or in the past. Anything else = not enforcing. */
    public static function enforcing(?string $enforceFrom, string $today): bool
    {
        if ($enforceFrom === null || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($enforceFrom), $m)) {
            return false;
        }
        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return false;
        }

        return trim($enforceFrom) <= $today;
    }
}
