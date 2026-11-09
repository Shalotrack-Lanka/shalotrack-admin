<?php

namespace App\Services\Supplier;

use App\Models\Product;
use App\Models\SupplierDelivery;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Services\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Purchase orders to suppliers (stored in supplier_invoices / supplier_invoice_items).
 *
 * The purchasing chain is the standard "three-way match":
 *
 *   purchase order (what we ordered, at what price)  ->  delivery (what arrived, every IMEI / ICCID scanned)
 *                                                    ->  stock-in on Manage Stock (the ONLY step that changes stock)
 *
 * So a purchase order never touches stock. It can be edited while it is ORDERED and nothing has been received
 * against it; after that it is locked (it is what the delivery is checked against). A wrong order is cancelled
 * with a reason, never deleted. Every save, edit and cancel is written to the audit log.
 *
 * Only products on the supplier's own product list can be ordered from that supplier. Money is worked out here
 * in integer cents; totals sent by the browser are never used.
 */
class PurchaseOrders
{
    public const ORDERED = 'ORDERED';
    public const CANCELLED = 'CANCELLED';

    /** The money columns are decimal(12,2). */
    private const LIMIT_CENTS = 999999999999;

    private static bool $ready = false;

    /**
     * True once the database has the purchase-order status columns (migration 2026_11_09_000004). Until then
     * orders can still be placed, but edit / cancel / receive-against-order stay hidden instead of failing.
     */
    public static function ready(): bool
    {
        if (self::$ready) {
            return true;
        }

        try {
            return self::$ready = Schema::hasColumn('supplier_invoices', 'status');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Turn validated form lines into stored lines with server-side totals. Every product must be on this
     * supplier's product list.
     *
     * @param  array<int,array<string,mixed>> $items
     * @return array{lines: list<array<string,mixed>>, total_cents: int}
     */
    public function price(int $supplierId, array $items): array
    {
        $offered = $this->offeredProductIds($supplierId);

        $lines = [];
        $total = 0;
        foreach (array_values($items) as $index => $item) {
            $productId = (int) $item['product_id'];
            if (! isset($offered[$productId])) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => 'This product is not on this supplier\'s product list. Add it to the supplier first.',
                ]);
            }

            $qty = (int) $item['order_qty'];
            $unit = self::cents($item['unit_price']);
            $face = self::cents($item['face_value'] ?? 0);
            $discountBp = (int) round(((float) ($item['discount'] ?? 0)) * 100);   // 12.5% -> 1250

            $gross = $qty * $unit;
            $discount = intdiv($gross * $discountBp + 5000, 10000);
            $net = $gross - $discount + $face;

            if ($net > self::LIMIT_CENTS) {
                throw ValidationException::withMessages([
                    "items.{$index}.unit_price" => 'This line is too large. Reduce the quantity or the price.',
                ]);
            }
            $total += $net;

            $lines[] = [
                'product_id' => $productId,
                'type'       => mb_substr(trim((string) $item['type']), 0, 50),
                'order_qty'  => $qty,
                'unit_price' => self::decimal($unit),
                'discount'   => self::decimal($discountBp),
                'face_value' => self::decimal($face),
                'net_amount' => self::decimal($net),
            ];
        }

        if ($total > self::LIMIT_CENTS) {
            throw ValidationException::withMessages(['items' => 'The order total is too large. Split it into smaller orders.']);
        }

        return ['lines' => $lines, 'total_cents' => $total];
    }

    /** Place a new order. Never changes stock. */
    public function create(int $supplierId, array $items, ?string $adminId): SupplierInvoice
    {
        $priced = $this->price($supplierId, $items);

        // The invoice number is UNIQUE: if two admins save at the same moment, the second one takes the next number.
        for ($attempt = 1; ; $attempt++) {
            try {
                $invoice = DB::transaction(function () use ($supplierId, $priced) {
                    $invoice = SupplierInvoice::create([
                        'invoice_number' => $this->nextNumber((int) now()->year),
                        'supplier_id'    => $supplierId,
                        'invoice_date'   => now()->toDateString(),
                        'grand_total'    => self::decimal($priced['total_cents']),
                    ]);
                    $this->writeLines($invoice, $priced['lines']);

                    return $invoice;
                });
                break;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 3) {
                    throw ValidationException::withMessages(['items' => 'Another order was being saved at the same time. Save again.']);
                }
            }
        }

        Audit::record('supplier_po.created', 'supplier_invoice', $invoice->id, $invoice->invoice_number, [], [
            'supplier_id' => $supplierId,
            'total'       => (string) $invoice->grand_total,
            'lines'       => count($priced['lines']),
        ]);

        return $invoice;
    }

    /** Replace the lines of an order that can still be edited. */
    public function update(SupplierInvoice $invoice, array $items, ?string $adminId): SupplierInvoice
    {
        $priced = $this->price((int) $invoice->supplier_id, $items);

        $before = null;
        DB::transaction(function () use ($invoice, $priced, $adminId, &$before) {
            $locked = SupplierInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($locked);

            $before = ['total' => $locked->grand_total, 'lines' => $this->lineSummary($locked)];

            SupplierInvoiceItem::where('supplier_invoice_id', $locked->id)->delete();
            $this->writeLines($locked, $priced['lines']);
            $locked->forceFill(['grand_total' => self::decimal($priced['total_cents']), 'updated_by' => $adminId])->save();
        });

        $invoice->refresh();
        Audit::record('supplier_po.updated', 'supplier_invoice', $invoice->id, $invoice->invoice_number, [
            'total' => ['from' => (string) ($before['total'] ?? ''), 'to' => (string) $invoice->grand_total],
        ], [
            'lines_before' => $before['lines'] ?? [],
            'lines_after'  => $this->lineSummary($invoice),
        ]);

        return $invoice;
    }

    /** Cancel an order (kept, marked CANCELLED with the reason). Only before anything is received against it. */
    public function cancel(SupplierInvoice $invoice, string $reason, ?string $adminId): void
    {
        DB::transaction(function () use ($invoice, $reason, $adminId) {
            $locked = SupplierInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($locked);

            $locked->forceFill([
                'status'        => self::CANCELLED,
                'cancelled_at'  => now(),
                'cancel_reason' => mb_substr($reason, 0, 200),
                'updated_by'    => $adminId,
            ])->save();
        });

        Audit::record('supplier_po.cancelled', 'supplier_invoice', $invoice->id, $invoice->invoice_number, [
            'status' => ['from' => self::ORDERED, 'to' => self::CANCELLED],
        ], ['reason' => $reason]);
    }

    /** null = can be edited / cancelled; otherwise the reason it cannot. */
    public function lockReason(SupplierInvoice $invoice): ?string
    {
        if (! self::ready()) {
            return 'Editing purchase orders needs the latest database update.';
        }
        if ($invoice->status === self::CANCELLED) {
            return 'This purchase order is cancelled.';
        }
        if (app(SupplierPayments::class)->hasLivePayments($invoice->id)) {
            return 'Payments have been recorded against this purchase order, so it can no longer be changed. Void the payments first.';
        }
        if ($this->hasDeliveries($invoice->id)) {
            return 'Goods have been received against this purchase order, so it can no longer be changed.';
        }

        return null;
    }

    /**
     * Orders a delivery from this supplier can be received against: ORDERED ones, with their device lines,
     * what has been received so far and what is still to come.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function openOrders(int $supplierId): Collection
    {
        if (! self::ready()) {
            return collect();
        }

        $orders = SupplierInvoice::where('supplier_id', $supplierId)
            ->where('status', self::ORDERED)
            ->with('items.product')
            ->orderByDesc('invoice_date')->orderByDesc('id')
            ->limit(50)
            ->get();

        // An order whose goods have all arrived has nothing left to receive, so it is not offered again.
        return $orders->map(fn (SupplierInvoice $o) => $this->summary($o))
            ->filter(fn (array $s) => collect($s['lines'])->contains(fn ($l) => $l['remaining'] > 0))
            ->values();
    }

    /**
     * What an order is for and how much of it has arrived: per device type ordered vs received, and SIMs.
     *
     * @return array{id:int, number:string, date:?string, status:string, device_types: array<int,array{ordered:int, received:int}>, sims_ordered:int, sims_received:int, lines: list<array{key:string, kind:string, device_type_id:?int, name:string, ordered:int, received:int, remaining:int}>, editable:bool}
     */
    public function summary(SupplierInvoice $order): array
    {
        $order->loadMissing('items.product');

        $types = [];
        $names = [];
        $simsOrdered = 0;
        $simName = null;
        foreach ($order->items as $item) {
            $typeId = $item->product?->device_type_id;
            if ($typeId) {
                $types[(int) $typeId]['ordered'] = ($types[(int) $typeId]['ordered'] ?? 0) + (int) $item->order_qty;
                $types[(int) $typeId]['received'] = 0;
                $names[(int) $typeId] ??= (string) $item->product->product_name;
            } elseif (self::isSimLine($item)) {
                $simsOrdered += (int) $item->order_qty;
                $simName ??= (string) ($item->product?->product_name ?? 'SIM cards');
            }
        }

        $simsReceived = 0;
        if (self::ready() && Schema::hasColumn('supplier_deliveries', 'supplier_invoice_id')) {
            $received = SupplierDelivery::where('supplier_invoice_id', $order->id)
                ->withCount('units')
                ->get(['id', 'kind', 'device_type_id']);
            foreach ($received as $d) {
                if ($d->kind === SupplierDelivery::KIND_DEVICE && $d->device_type_id) {
                    $types[(int) $d->device_type_id]['ordered'] ??= 0;
                    $types[(int) $d->device_type_id]['received'] = ($types[(int) $d->device_type_id]['received'] ?? 0) + (int) $d->units_count;
                } else {
                    $simsReceived += (int) $d->units_count;
                }
            }
        }

        // One line per thing on the order (each device type, and SIMs) with what is still to come: this is what the
        // Receive delivery screen offers, and receiving more than "remaining" is refused.
        $lines = [];
        foreach ($types as $typeId => $q) {
            $lines[] = [
                'key' => 'dt:' . $typeId, 'kind' => SupplierDelivery::KIND_DEVICE, 'device_type_id' => $typeId,
                'name' => $names[$typeId] ?? 'Device type #' . $typeId, 'ordered' => $q['ordered'], 'received' => $q['received'],
                'remaining' => max(0, $q['ordered'] - $q['received']),
            ];
        }
        if ($simsOrdered > 0 || $simsReceived > 0) {
            $lines[] = [
                'key' => 'sim', 'kind' => SupplierDelivery::KIND_SIM, 'device_type_id' => null,
                'name' => $simName ?? 'SIM cards', 'ordered' => $simsOrdered, 'received' => $simsReceived,
                'remaining' => max(0, $simsOrdered - $simsReceived),
                'sim_type' => SupplierCatalogue::simTypeFrom([$simName]),
            ];
        }

        return [
            'id'            => (int) $order->id,
            'number'        => (string) $order->invoice_number,
            'date'          => $order->invoice_date?->format('Y-m-d'),
            'status'        => (string) ($order->status ?? self::ORDERED),
            'device_types'  => $types,
            'sims_ordered'  => $simsOrdered,
            'sims_received' => $simsReceived,
            'lines'         => $lines,
            'editable'      => $this->lockReason($order) === null,
        ];
    }

    /**
     * How many more units of this device type (or SIMs, $deviceTypeId null) the order is still waiting for.
     */
    public function remaining(SupplierInvoice $order, string $kind, ?int $deviceTypeId): int
    {
        $summary = $this->summary($order);

        [$ordered, $received] = $kind === SupplierDelivery::KIND_DEVICE
            ? [$summary['device_types'][(int) $deviceTypeId]['ordered'] ?? 0, $summary['device_types'][(int) $deviceTypeId]['received'] ?? 0]
            : [$summary['sims_ordered'], $summary['sims_received']];

        return max(0, $ordered - $received);
    }

    /** A line with no device type that is a SIM (the product or the line says so). */
    public static function isSimLine(SupplierInvoiceItem $item): bool
    {
        return strtoupper((string) $item->type) === 'SIM'
            || SupplierCatalogue::isSimProduct($item->product);
    }

    // ------------------------------------------------------------------------------------------------ internals

    private function assertEditable(SupplierInvoice $invoice): void
    {
        $reason = $this->lockReason($invoice);
        if ($reason !== null) {
            throw ValidationException::withMessages(['invoice' => $reason]);
        }
    }

    private function hasDeliveries(int $invoiceId): bool
    {
        return Schema::hasColumn('supplier_deliveries', 'supplier_invoice_id')
            && SupplierDelivery::where('supplier_invoice_id', $invoiceId)->exists();
    }


    /** @return array<int,true> product ids on the supplier's product list */
    private function offeredProductIds(int $supplierId): array
    {
        return DB::table('supplier_products')->where('supplier_id', $supplierId)
            ->pluck('product_id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    private function writeLines(SupplierInvoice $invoice, array $lines): void
    {
        foreach ($lines as $line) {
            SupplierInvoiceItem::create(['supplier_invoice_id' => $invoice->id] + $line);
        }
    }

    /** @return list<array{product_id:int, qty:int, price:string, net:string}> */
    private function lineSummary(SupplierInvoice $invoice): array
    {
        return SupplierInvoiceItem::where('supplier_invoice_id', $invoice->id)->orderBy('id')->get()
            ->map(fn ($i) => ['product_id' => (int) $i->product_id, 'qty' => (int) $i->order_qty, 'price' => (string) $i->unit_price, 'net' => (string) $i->net_amount])
            ->all();
    }

    /** PO-<year>-0001: one more than the highest number used this year (not a count, so a gap never repeats a number). */
    private function nextNumber(int $year): string
    {
        $prefix = 'PO-' . $year . '-';
        $last = SupplierInvoice::where('invoice_number', 'like', $prefix . '%')->pluck('invoice_number')
            ->map(fn ($n) => preg_match('/^PO-\d{4}-(\d{1,9})$/', (string) $n, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        return $prefix . str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    /** A validated numeric amount -> cents (validation has already limited the range and sign). */
    private static function cents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private static function decimal(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
