<?php

namespace App\Services;

use App\Models\CustomerAd;
use App\Models\DealerCustomerAd;
use App\Models\VehicleAd;
use Illuminate\Support\Collection;

/**
 * CustomerLinkService
 *
 * Single source of truth for soft-matching between:
 *   - DealerCustomerAd  (dealer's submitted lead — stored in Admin DB)
 *   - CustomerAd        (real app user — synced from C# API / DB1)
 *   - VehicleAd         (vehicles — synced from C# API / DB1)
 *
 * WHY SOFT MATCH, NOT FOREIGN KEY:
 *   CustomerAd and VehicleAd are read-only mirrors of data that lives in DB1
 *   (the telemetry/business Supabase). We cannot place a FK from DealerCustomerAd
 *   into that data because the admin portal has no write authority over DB1.
 *   Matching on phone + email is the ERP-standard approach for cross-system
 *   identity resolution when you don't control both schemas.
 *
 * PHONE NORMALISATION:
 *   Dealer stores:  0771234567      (10-digit local Sri Lankan format)
 *   API stores:     +94771234567    (E.164 international format)
 *   Both normalise to subscriber digits: 771234567
 *   This 9-digit subscriber number is the stable identity token.
 *
 * MATCH PRIORITY:
 *   1. Email match (most reliable — emails are globally unique, phones can be recycled)
 *   2. Phone normalised match (catches customers who registered before adding email)
 *   A match on EITHER is sufficient. Both together confirm with certainty.
 */
class CustomerLinkService
{
    // ─────────────────────────────────────────────────────────────────────────
    // Core normaliser — shared between phone fields on both sides
    // ─────────────────────────────────────────────────────────────────────────

    public static function normalisePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        // E.164 without + : 94771234567 → 771234567
        if (str_starts_with($digits, '94') && strlen($digits) === 11) {
            return substr($digits, 2);
        }

        // Local Sri Lankan: 0771234567 → 771234567
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return substr($digits, 1);
        }

        return $digits;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Find real app account for a single dealer lead
    // Returns CustomerAd|null
    // ─────────────────────────────────────────────────────────────────────────

    public static function findAppAccount(DealerCustomerAd $lead): ?CustomerAd
    {
        $normalisedPhone = self::normalisePhone($lead->contact ?? '');

        // Build query: match on email OR normalised phone
        $query = CustomerAd::query();

        $conditions = [];

        if (!empty($lead->email)) {
            $conditions[] = ['email', 'ilike', $lead->email];
        }

        if (!empty($normalisedPhone)) {
            // Match against E.164 variants
            $conditions[] = ['phone_number', 'ilike', '%' . $normalisedPhone];
        }

        if (empty($conditions)) {
            return null;
        }

        return $query->where(function ($q) use ($lead, $normalisedPhone) {
            if (!empty($lead->email)) {
                $q->orWhere('email', 'ilike', $lead->email);
            }
            if (!empty($normalisedPhone)) {
                $q->orWhere('phone_number', 'ilike', '%' . $normalisedPhone);
            }
        })->first();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Enrich a collection of DealerCustomerAd with linked app accounts
    // and their vehicles. Returns the same collection with two extra attributes
    // set on each item:  ->appAccount (CustomerAd|null)
    //                    ->appVehicles (Collection<VehicleAd>)
    //
    // Loads all CustomerAd and VehicleAd in two queries (not N+1).
    // ─────────────────────────────────────────────────────────────────────────

    public static function enrichLeads(Collection $leads): Collection
    {
        if ($leads->isEmpty()) {
            return $leads;
        }

        // Collect all normalised phones and emails from the leads batch.
        $normalisedPhones = $leads
            ->map(fn($l) => self::normalisePhone($l->contact ?? ''))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $emails = $leads
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        // Single query to pull every potentially matching CustomerAd.
        $appAccounts = CustomerAd::with('vehicles')
            ->where(function ($q) use ($normalisedPhones, $emails) {
                if (!empty($emails)) {
                    $q->orWhereIn('email', $emails);
                }
                foreach ($normalisedPhones as $phone) {
                    $q->orWhere('phone_number', 'ilike', '%' . $phone);
                }
            })
            ->get()
            ->keyBy(function ($c) {
                // Index by normalised phone for O(1) lookup below
                return self::normalisePhone($c->phone_number ?? '');
            });

        // Build email → CustomerAd index as secondary lookup
        $appByEmail = $appAccounts->keyBy('email')->filter();

        // Attach matched account and vehicles to each lead
        return $leads->map(function ($lead) use ($appAccounts, $appByEmail) {
            $appAccount = null;

            // Priority 1: email match
            if (!empty($lead->email) && isset($appByEmail[$lead->email])) {
                $appAccount = $appByEmail[$lead->email];
            }

            // Priority 2: phone match
            if (!$appAccount) {
                $normPhone = self::normalisePhone($lead->contact ?? '');
                if (!empty($normPhone) && isset($appAccounts[$normPhone])) {
                    $appAccount = $appAccounts[$normPhone];
                }
            }

            $lead->appAccount  = $appAccount;
            $lead->appVehicles = $appAccount ? $appAccount->vehicles : collect();

            return $lead;
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Find which dealer lead originated a given CustomerAd
    // (used on admin customer setup page to show "Source: Dealer X")
    // Returns DealerCustomerAd|null
    // ─────────────────────────────────────────────────────────────────────────

    public static function findDealerLead(CustomerAd $customer): ?DealerCustomerAd
    {
        $normPhone = self::normalisePhone($customer->phone_number ?? '');

        return DealerCustomerAd::with('dealer')
            ->where(function ($q) use ($customer, $normPhone) {
                if (!empty($customer->email)) {
                    $q->orWhere('email', 'ilike', $customer->email);
                }
                if (!empty($normPhone)) {
                    // Dealer phone is local format — last 9 digits match
                    $q->orWhere('contact', 'ilike', '%' . $normPhone);
                }
            })
            ->first();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Enrich a collection of CustomerAd with their dealer source lead.
    // Returns the same collection with ->dealerLead (DealerCustomerAd|null)
    // set on each item. Two queries total, never N+1.
    // ─────────────────────────────────────────────────────────────────────────

    public static function enrichCustomers(Collection $customers): Collection
    {
        if ($customers->isEmpty()) {
            return $customers;
        }

        $normPhones = $customers
            ->map(fn($c) => self::normalisePhone($c->phone_number ?? ''))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $emails = $customers
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        // Single query for all dealer leads that could match any customer in the batch
        $leads = DealerCustomerAd::with('dealer')
            ->where(function ($q) use ($normPhones, $emails) {
                if (!empty($emails)) {
                    $q->orWhereIn('email', $emails);
                }
                foreach ($normPhones as $phone) {
                    $q->orWhere('contact', 'ilike', '%' . $phone);
                }
            })
            ->get();

        $leadsByEmail = $leads->keyBy('email')->filter();
        $leadsByPhone = $leads->keyBy(fn($l) => self::normalisePhone($l->contact ?? ''))->filter();

        return $customers->map(function ($customer) use ($leadsByEmail, $leadsByPhone) {
            $lead = null;

            if (!empty($customer->email) && isset($leadsByEmail[$customer->email])) {
                $lead = $leadsByEmail[$customer->email];
            }

            if (!$lead) {
                $normPhone = self::normalisePhone($customer->phone_number ?? '');
                if (!empty($normPhone) && isset($leadsByPhone[$normPhone])) {
                    $lead = $leadsByPhone[$normPhone];
                }
            }

            $customer->dealerLead = $lead;
            return $customer;
        });
    }
}