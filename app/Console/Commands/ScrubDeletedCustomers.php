<?php

namespace App\Console\Commands;

use App\Models\CustomerAd;
use App\Models\VehicleAd;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * customers:scrub-deleted
 *
 * When a customer deletes their account, the API erases the data and leaves an anonymised shell
 * (name "Deleted customer", email deleted-<id>@deleted.invalid, plates "DELETED-XXXXXXXX").
 * customers:sync copies that shell into Customer-ad / vehicle_ad within five minutes. This command
 * then removes the identity that was copied by hand into the business records that must be kept
 * (warranty, payments, commissions, technician jobs): the customer name and the plate. Amounts,
 * IMEIs, dates and dealers stay, so the audit trail is intact.
 *
 * It only acts on a vehicle once vehicle_ad already shows the anonymised plate, i.e. after the API
 * has really purged it, so it can never scrub a customer who merely asked and may still cancel.
 * Safe to run any number of times. Not covered: dealer_customer_ads (a dealer's own sales record,
 * not linked to a customer id) — see the retention note delivered with this change.
 */
class ScrubDeletedCustomers extends Command
{
    protected $signature   = 'customers:scrub-deleted {--dry-run : Count what would change, change nothing}';
    protected $description = 'Remove the names and plates of deleted customers from the retained business records.';

    private const DELETED_NAME = 'Deleted customer';

    /** table => whether it also holds a free-text location note to clear. */
    private const TABLES = [
        'activated_devices' => false,
        'expired_devices'   => false,
        'package_payments'  => false,
        'technician_jobs'   => true,
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $deleted = CustomerAd::query()
            ->where('email', 'like', 'deleted-%@deleted.invalid')
            ->pluck('customer_id');

        if ($deleted->isEmpty()) {
            $this->info('No deleted customers.');
            return self::SUCCESS;
        }

        $touched = 0;

        foreach ($deleted as $customerId) {
            // Name only, by customer id (these tables all carry it).
            foreach (array_keys(self::TABLES) as $table) {
                $touched += $this->scrubName($table, 'customer_id', (string) $customerId, $dry);
            }

            // Plates, by vehicle id, once the anonymised plate has been synced.
            $vehicles = VehicleAd::query()
                ->where('customer_id', $customerId)
                ->where('vehicle_number', 'like', 'DELETED-%')
                ->get(['vehicle_id', 'vehicle_number']);

            foreach ($vehicles as $vehicle) {
                foreach (self::TABLES as $table => $hasNote) {
                    $touched += $this->scrubVehicle($table, (string) $vehicle->vehicle_id, (string) $vehicle->vehicle_number, $hasNote, $dry);
                }
            }
        }

        $msg = ($dry ? 'DRY RUN: would update ' : 'Updated ') . "{$touched} record(s) for {$deleted->count()} deleted customer(s).";
        $this->info($msg);

        if (!$dry && $touched > 0) {
            Log::info('customers:scrub-deleted ' . $msg);
        }

        return self::SUCCESS;
    }

    private function scrubName(string $table, string $idColumn, string $id, bool $dry): int
    {
        $q = DB::table($table)
            ->where($idColumn, $id)
            ->whereNotNull('customer_name')
            ->where('customer_name', '<>', self::DELETED_NAME);

        return $dry ? $q->count() : $q->update(['customer_name' => self::DELETED_NAME]);
    }

    private function scrubVehicle(string $table, string $vehicleId, string $anonPlate, bool $hasNote, bool $dry): int
    {
        $q = DB::table($table)
            ->where('vehicle_id', $vehicleId)
            ->where(function ($w) use ($anonPlate, $hasNote) {
                $w->whereNull('vehicle_number')->orWhere('vehicle_number', '<>', $anonPlate);
                if ($hasNote) {
                    $w->orWhereNotNull('location_note');
                }
            });

        if ($dry) {
            return $q->count();
        }

        $values = ['vehicle_number' => $anonPlate];
        if ($hasNote) {
            $values['location_note'] = null;
        }

        return $q->update($values);
    }
}