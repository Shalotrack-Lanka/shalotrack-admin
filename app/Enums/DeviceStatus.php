<?php

namespace App\Enums;

/**
 * Every value that can appear in setup_shalotrack_devices.status.
 *
 * ONE source of truth: the backing strings are exactly what is stored in the
 * database (and what the C# API / reports already see), so switching a
 * controller from a raw string to DeviceStatus::X->value changes nothing at
 * runtime — it only means a typo becomes a fatal error at once instead of a
 * silently empty list.
 *
 * NOT for SIMs: sims.sim_status has its own 'Activated' / 'Not Activated'
 * strings with a different meaning. Never use this enum for those.
 */
enum DeviceStatus: string
{
    case NotActivated       = 'Not Activated';
    case Activated          = 'Activated';
    case TemporarilyStopped = 'Temporarily Stopped';
    case AssignedToCustomer = 'Assigned to Customer';
    case PendingRepair      = 'Pending Repair';
    case BrokenDevice       = 'Broken Device';

    /** Plain-language meaning, as the code actually behaves (safe to show in docs/UI). */
    public function description(): string
    {
        return match ($this) {
            self::NotActivated =>
                'Registered but not yet in service. It is either in company stock (no dealer) '
                . 'or in a dealer\'s stock (dealer_id set) waiting to be assigned to a customer.',
            self::Activated =>
                'In service. Subscription expiry does NOT change this status; it only marks '
                . 'the payment as not-Paid, which is synced to the API.',
            self::TemporarilyStopped =>
                'Stopped by an admin on the Cancel Device page. A reason is required. '
                . 'It is never set automatically (e.g. not by expiry or non-payment).',
            self::AssignedToCustomer =>
                'A dealer has assigned it to a customer. A device in this status with no '
                . 'linked customer is auto-corrected back to Not Activated on the dealer dashboard.',
            self::PendingRepair =>
                'Holding state before testing: a dealer unassigned it from a customer, or moved '
                . 'a broken device back for testing. It does not prove a repair happened.',
            self::BrokenDevice =>
                'Marked broken by the dealer; out of use until moved back to Pending Repair for testing.',
        };
    }

    /**
     * Moves the ADMIN Cancel Device page may make. Not Activated -> Activated is
     * deliberately NOT here: activation happens only in Customer Device Management,
     * which attaches the customer, vehicle and subscription. Activating from Cancel
     * Device left devices in service with no customer and no way to fix them.
     *
     * Anything not listed is
     * rejected server-side whatever the dropdown offered. Dealer-side moves
     * (assign / unassign / broken / retest) are enforced in the dealer
     * controller and are intentionally not part of this map.
     *
     * @return list<self>
     */
    public function adminNext(): array
    {
        return match ($this) {
            self::NotActivated       => [],
            self::Activated          => [self::TemporarilyStopped],
            self::TemporarilyStopped => [self::Activated],
            default                  => [],
        };
    }

    public function canAdminMoveTo(self $to): bool
    {
        return in_array($to, $this->adminNext(), true);
    }

    /** Statuses that mean "live on a customer account" (Cancel Device's Activated list). */
    public static function inServiceValues(): array
    {
        return [self::Activated->value, self::TemporarilyStopped->value];
    }

    /** Values the Cancel Device form is allowed to submit. */
    public static function adminSelectableValues(): array
    {
        return [self::NotActivated->value, self::Activated->value, self::TemporarilyStopped->value];
    }

    /** Statuses a dealer sees under their own tabs, not in Available Stock. */
    public static function dealerSideValues(): array
    {
        return [self::AssignedToCustomer->value, self::PendingRepair->value, self::BrokenDevice->value];
    }
}
