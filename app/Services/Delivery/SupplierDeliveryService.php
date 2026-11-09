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
use Illuminate\Support\Facades\Schema;
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
     * The device types this supplier supplies: the device-linked products on its product list (Supplier
     * Management -> Edit -> Products). Free-text products (SIM packages and the like) have no device type and
     * are not included.
     *
     * @return \Illuminate\Support\Collection<int, DeviceType>
     */
    public function deviceTypesFor(int $supplierId)
    {
        return DeviceType::whereIn('id', function ($q) use ($supplierId) {
            $q->select('products.device_type_id')
                ->from('products')
                ->join('supplier_products', 'supplier_products.product_id', '=', 'products.id')
                ->where('supplier_products.supplier_id', $supplierId)
                ->whereNotNull('products.device_type_id');
        })->orderBy('device_category')->orderBy('model')->get();
    }

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
            // Only a device this supplier is set up to supply (its product list). The screen only offers those,
            // so this catches a stale page or a hand-made request, not normal use.
            if (! $this->deviceTypesFor($supplier->id)->contains('id', $deviceTypeId)) {
                throw ValidationException::withMessages(['device_type_id' => sprintf(
                    '%s does not supply this device type. Add it to the supplier\'s products first.',
                    $supplier->name
                )]);
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

    /**
     * Device deliveries received but not yet added to stock, for the stock-in screen.
     *
     * @return \Illuminate\Support\Collection<int,SupplierDelivery>
     */
    public function openDeviceDeliveries(?int $supplierId = null)
    {
        return SupplierDelivery::query()
            ->where('kind', SupplierDelivery::KIND_DEVICE)
            ->whereNull('stocked_in_at')
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->with('deviceType')
            ->withCount('units')
            ->orderBy('id')
            ->get();
    }

    public function hasOpenDeviceDelivery(int $supplierId, int $deviceTypeId): bool
    {
        return SupplierDelivery::where('kind', SupplierDelivery::KIND_DEVICE)
            ->where('supplier_id', $supplierId)
            ->where('device_type_id', $deviceTypeId)
            ->whereNull('stocked_in_at')
            ->exists();
    }

    /**
     * Inside the stock-in transaction: lock the delivery, check it really is this supplier's open delivery of
     * this device type, and return it with the number of scanned units (the quantity to add). Locking makes a
     * double click or two admins unable to add the same delivery twice.
     *
     * @return array{delivery: SupplierDelivery, qty: int}
     */
    public function claimForStockIn(int $supplierId, int $deviceTypeId, int $deliveryId): array
    {
        $delivery = SupplierDelivery::whereKey($deliveryId)->lockForUpdate()->first();

        if (! $delivery
            || $delivery->kind !== SupplierDelivery::KIND_DEVICE
            || (int) $delivery->supplier_id !== $supplierId
            || (int) $delivery->device_type_id !== $deviceTypeId) {
            throw ValidationException::withMessages(['delivery_id' => 'That delivery does not belong to this supplier and device type.']);
        }
        if ($delivery->isStockedIn()) {
            throw ValidationException::withMessages(['delivery_id' => 'That delivery has already been added to stock.']);
        }

        $qty = $delivery->units()->count();
        if ($qty < 1) {
            throw ValidationException::withMessages(['delivery_id' => 'That delivery has no scanned units.']);
        }

        return ['delivery' => $delivery, 'qty' => $qty];
    }

    /** Same transaction as the stock increase: records which stock-in ledger row this delivery became. */
    public function markStocked(SupplierDelivery $delivery, int $ledgerId): void
    {
        $delivery->forceFill(['stocked_in_at' => now(), 'stock_ledger_id' => $ledgerId])->save();

        Audit::record(
            'supplier_delivery.stocked',
            'supplier_delivery',
            $delivery->id,
            null,
            [],
            ['units' => $delivery->units()->count(), 'ledger_id' => $ledgerId],
        );
    }

    /**
     * Cancel a delivery that was scanned by mistake (wrong box, wrong type) so its units can be scanned again.
     * Only while it has NOT been added to stock and none of its units has become a registered device. The units
     * are removed (their IMEIs are free again) and the cancellation, with its reason, stays in the audit trail.
     */
    public function void(int $supplierId, int $deliveryId, string $reason, ?string $adminId): void
    {
        DB::transaction(function () use ($supplierId, $deliveryId, $reason) {
            $delivery = SupplierDelivery::whereKey($deliveryId)->where('supplier_id', $supplierId)->lockForUpdate()->first();

            if (! $delivery) {
                throw ValidationException::withMessages(['delivery_id' => 'Delivery not found.']);
            }
            if ($delivery->isStockedIn()) {
                throw ValidationException::withMessages(['delivery_id' => 'This delivery is already in stock and cannot be cancelled.']);
            }
            if ($delivery->units()->whereNotNull('registered_at')->exists()) {
                throw ValidationException::withMessages(['delivery_id' => 'Some units of this delivery are already registered and cannot be cancelled.']);
            }

            $units = $delivery->units()->pluck('identifier')->all();
            $delivery->units()->delete();
            $delivery->delete();

            Audit::record(
                'supplier_delivery.voided',
                'supplier_delivery',
                $deliveryId,
                null,
                [],
                ['reason' => $reason, 'kind' => $delivery->kind, 'count' => count($units), 'identifiers' => array_slice($units, 0, 50)],
            );
        });
    }

    // ------------------------------------------------------------------------------------------------
    // Registration checks. Every way a device or SIM enters the system calls these, so the rule is the
    // same everywhere (see DeliveryPolicy). All of it is a no-op while the feature is switched off.
    // ------------------------------------------------------------------------------------------------

    private static bool $tablesReady = false;

    public function enabled(): bool
    {
        return self::isEnabled();
    }

    /**
     * On unless SUPPLIER_DELIVERIES_ENABLED=false, AND only once the database has the delivery tables. Until the
     * migrations have run the whole feature stays quietly off instead of failing pages with a missing-table
     * error, so deploying the code before the migration is safe. (Only a positive answer is remembered.)
     */
    public static function isEnabled(): bool
    {
        if (! config('supplier_deliveries.enabled')) {
            return false;
        }
        if (self::$tablesReady) {
            return true;
        }

        try {
            self::$tablesReady = Schema::hasTable('supplier_deliveries')
                && Schema::hasTable('supplier_delivery_units')
                && Schema::hasColumn('supplier_deliveries', 'stocked_in_at');
        } catch (\Throwable $e) {
            return false;
        }

        return self::$tablesReady;
    }

    /**
     * Read-only look (no lock), for screens and bulk validation.
     *
     * @return array{status: string, unit: ?SupplierDeliveryUnit, from: ?string, message: ?string}
     */
    public function preview(string $kind, string $identifier, ?int $deviceTypeId): array
    {
        if (! $this->enabled()) {
            return ['status' => DeliveryPolicy::OFF, 'unit' => null, 'from' => null, 'message' => null];
        }

        $unit = SupplierDeliveryUnit::where('identifier', $identifier)->where('kind', $kind)->with('delivery.supplier')->first();

        return $this->describe($kind, $unit, $deviceTypeId);
    }

    /**
     * Inside the caller's registration transaction: lock the unit, apply the rule, and either return what to
     * stamp afterwards or throw a ValidationException (the caller's transaction then rolls back, nothing saved).
     *
     * @return array{status: string, unit: ?SupplierDeliveryUnit}
     */
    public function verify(string $kind, string $identifier, ?int $deviceTypeId, string $errorField): array
    {
        if (! $this->enabled()) {
            return ['status' => DeliveryPolicy::OFF, 'unit' => null];
        }

        $unit = SupplierDeliveryUnit::where('identifier', $identifier)->where('kind', $kind)->with('delivery.supplier')->lockForUpdate()->first();
        $d = $this->describe($kind, $unit, $deviceTypeId);

        if (in_array($d['status'], [DeliveryPolicy::BLOCKED, DeliveryPolicy::MISMATCH, DeliveryPolicy::REGISTERED], true)) {
            throw ValidationException::withMessages([$errorField => $d['message']]);
        }

        return ['status' => $d['status'], 'unit' => $unit];
    }

    /** After the device / SIM row exists (same transaction): record what the unit became. */
    public function stamp(?SupplierDeliveryUnit $unit, string $ref): void
    {
        if ($unit) {
            $unit->forceFill(['registered_at' => now(), 'registered_ref' => mb_substr($ref, 0, 64)])->save();
        }
    }

    /** The warning shown after a registration that was allowed without a delivery, or null. */
    public static function warningFor(string $status, string $what): ?string
    {
        return $status === DeliveryPolicy::UNVERIFIED
            ? "Note: this {$what} was not received on any supplier delivery, so its supplier is unknown."
            : null;
    }

    /**
     * Supplier name for each IMEI (for the Setup Devices list). One query for the whole list.
     *
     * @param  array<int,string> $imeis
     * @return array<string,string>
     */
    public function supplierNamesByImei(array $imeis): array
    {
        if (! $this->enabled() || $imeis === []) {
            return [];
        }

        $out = [];
        SupplierDeliveryUnit::where('kind', SupplierDelivery::KIND_DEVICE)->whereIn('identifier', $imeis)->with('delivery.supplier')->get()
            ->each(function ($u) use (&$out) {
                $out[$u->identifier] = $u->delivery?->supplier?->name ?? '-';
            });

        return $out;
    }

    /** @return array{status: string, unit: ?SupplierDeliveryUnit, from: ?string, message: ?string} */
    private function describe(string $kind, ?SupplierDeliveryUnit $unit, ?int $deviceTypeId): array
    {
        $status = DeliveryPolicy::decide(
            true,
            config('supplier_deliveries.enforce_from'),
            now()->toDateString(),
            $unit !== null,
            $unit?->registered_at !== null,
            $unit?->delivery?->device_type_id !== null ? (int) $unit->delivery->device_type_id : null,
            $kind === SupplierDelivery::KIND_DEVICE ? $deviceTypeId : null,
        );

        $what = $kind === SupplierDelivery::KIND_DEVICE ? 'IMEI' : 'SIM';
        $from = $unit?->delivery
            ? ($unit->delivery->supplier?->name ?? 'a supplier') . ' (delivery #' . $unit->supplier_delivery_id . ')'
            : null;

        $message = match ($status) {
            DeliveryPolicy::BLOCKED => "This {$what} was not received on a supplier delivery. Record the delivery under Supplier Management first.",
            DeliveryPolicy::REGISTERED => "This {$what} has already been registered.",
            DeliveryPolicy::MISMATCH => sprintf(
                'This IMEI was delivered as %s, not as the device type you chose. Choose the type it was delivered as.',
                $this->typeName((int) $unit->delivery->device_type_id)
            ),
            DeliveryPolicy::UNVERIFIED => "Not received on any supplier delivery (supplier unknown).",
            default => null,
        };

        return ['status' => $status, 'unit' => $unit, 'from' => $from, 'message' => $message];
    }

    private function typeName(int $id): string
    {
        $t = DeviceType::find($id);

        return $t ? trim($t->device_category . ' ' . $t->model) : 'another device type';
    }

    /** Which supplier and delivery did this IMEI / ICCID come from? null = never received through a delivery. */
    public function provenance(string $identifier): ?SupplierDeliveryUnit
    {
        return SupplierDeliveryUnit::where('identifier', $identifier)->with('delivery.supplier')->first();
    }
}
