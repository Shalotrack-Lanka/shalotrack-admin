<?php

namespace App\Services\Delivery;

use App\Models\DeviceType;
use App\Models\SetupShalotrackDevice;
use App\Models\Supplier;
use App\Models\SupplierDelivery;
use App\Models\SupplierDeliveryUnit;
use App\Services\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a delivery from a supplier: the scanned IMEIs (devices) or ICCIDs (SIMs), one delivery row plus one
 * unit row per accepted scan, in ONE transaction. Rejected scans (bad check digit, already received, already
 * registered) are reported back one by one and never block the good ones. This service does NOT change stock
 * counts; the stock-in screen does that (a later stage), so this stage cannot affect inventory.
 */
class SupplierDeliveryService
{
    /**
     * @param  array<int,mixed> $scans raw scanned values
     * @return array{delivery: ?SupplierDelivery, accepted: list<string>, rejected: list<array{identifier: string, reason: string}>}
     */
    public function receive(
        string $kind,
        int $supplierId,
        ?int $deviceTypeId,
        array $scans,
        ?string $reference,
        ?string $note,
        ?string $adminId,
    ): array {
        $supplier = Supplier::where('status', 'Active')->find($supplierId);
        if (! $supplier) {
            throw ValidationException::withMessages(['supplier_id' => 'Choose an active supplier.']);
        }

        if ($kind === SupplierDelivery::KIND_DEVICE) {
            if (! $deviceTypeId || ! DeviceType::whereKey($deviceTypeId)->exists()) {
                throw ValidationException::withMessages(['device_type_id' => 'Choose the device type being delivered.']);
            }
        } else {
            $deviceTypeId = null;
        }

        try {
            $batch = ScanBatch::classify($kind, $scans);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['scans' => $e->getMessage()]);
        }

        $rejected = $batch['rejected'];
        $toSave = $batch['valid'];

        if ($toSave === []) {
            return ['delivery' => null, 'accepted' => [], 'rejected' => $rejected];
        }

        try {
            $delivery = DB::transaction(function () use ($kind, $supplier, $deviceTypeId, $reference, $note, $adminId, &$toSave, &$rejected) {
                // Already received before (any supplier): the identifier is unique, so say which delivery.
                $known = SupplierDeliveryUnit::whereIn('identifier', $toSave)->with('delivery.supplier')->get()->keyBy('identifier');
                foreach ($known as $id => $unit) {
                    $rejected[] = ['identifier' => (string) $id, 'reason' => sprintf(
                        'Already received on delivery #%d from %s.',
                        $unit->supplier_delivery_id,
                        $unit->delivery?->supplier?->name ?? 'a supplier'
                    )];
                }

                // Already a registered device / attached to one: not new stock.
                $column = $kind === SupplierDelivery::KIND_DEVICE ? 'imei_number' : 'iccid';
                $registered = SetupShalotrackDevice::whereIn($column, $toSave)->pluck($column)->all();
                foreach ($registered as $id) {
                    $rejected[] = ['identifier' => (string) $id, 'reason' => $kind === SupplierDelivery::KIND_DEVICE
                        ? 'Already registered as a device in the system.'
                        : 'Already attached to a registered device.'];
                }

                $blocked = array_flip(array_merge($known->keys()->all(), array_map('strval', $registered)));
                $toSave = array_values(array_filter($toSave, fn ($id) => ! isset($blocked[$id])));

                if ($toSave === []) {
                    return null;
                }

                $delivery = SupplierDelivery::create([
                    'supplier_id'    => $supplier->id,
                    'kind'           => $kind,
                    'device_type_id' => $deviceTypeId,
                    'reference'      => $reference !== null ? mb_substr(trim($reference), 0, 100) ?: null : null,
                    'received_on'    => now()->toDateString(),
                    'note'           => $note !== null ? mb_substr(trim($note), 0, 500) ?: null : null,
                    'received_by'    => $adminId,
                ]);

                $now = now();
                SupplierDeliveryUnit::insert(array_map(fn ($id) => [
                    'supplier_delivery_id' => $delivery->id,
                    'kind'                 => $kind,
                    'identifier'           => $id,
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ], $toSave));

                return $delivery;
            });
        } catch (QueryException $e) {
            // The UNIQUE index is the real guard if two people scan the same unit at the same moment.
            report($e);
            throw ValidationException::withMessages(['scans' => 'One of these units was just recorded by someone else. Nothing was saved; rescan the delivery.']);
        }

        if ($delivery) {
            Audit::record(
                'supplier_delivery.received',
                'supplier_delivery',
                $delivery->id,
                $supplier->name . ' (' . strtolower($kind) . 's)',
                [],
                ['count' => count($toSave), 'reference' => $delivery->reference, 'identifiers' => array_slice($toSave, 0, 50)],
            );
        }

        return ['delivery' => $delivery, 'accepted' => $delivery ? $toSave : [], 'rejected' => $rejected];
    }

    /** Which supplier and delivery did this IMEI / ICCID come from? null = never received through a delivery. */
    public function provenance(string $identifier): ?SupplierDeliveryUnit
    {
        return SupplierDeliveryUnit::where('identifier', $identifier)->with('delivery.supplier')->first();
    }
}
