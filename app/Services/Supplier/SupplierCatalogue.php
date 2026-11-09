<?php

namespace App\Services\Supplier;

use App\Models\DeviceType;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierDelivery;
use App\Services\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * A supplier's product list ("what this supplier supplies, at what agreed price"). It is the one link every other
 * screen depends on:
 *
 *   Add Device Type  -> a device type can be put on a supplier's list (one product per device type)
 *   Supplier list    -> decides what can be ORDERED (purchase orders), RECEIVED (scanned deliveries) and
 *                       STOCKED IN (Manage Stock, stock import) from that supplier
 *
 * So the list is a catalogue, not an order: receiving stock never removes a product from it (it used to, which
 * made the supplier "stop supplying" a device as soon as one delivery was stocked in). A product cannot be taken
 * off the list while a purchase order or a scanned delivery still depends on it.
 */
class SupplierCatalogue
{
    /** Does this supplier supply this device type? */
    public function supplies(int $supplierId, int $deviceTypeId): bool
    {
        return DB::table('supplier_products')
            ->join('products', 'products.id', '=', 'supplier_products.product_id')
            ->where('supplier_products.supplier_id', $supplierId)
            ->where('products.device_type_id', $deviceTypeId)
            ->exists();
    }

    /** The message when it does not (one wording everywhere). */
    public static function notSuppliedMessage(string $supplierName, string $typeName): string
    {
        return "{$supplierName} does not supply {$typeName}. Add it to the supplier's products first (Supplier Management -> Edit -> Products).";
    }

    /**
     * The product for one line of the supplier form. A device type always maps to its single device product, so a
     * device is never stored twice. A typed name that matches a device type ("Shalotrack V5") is that device; a
     * typed name that matches an existing product (any capitals / spaces) is that product; anything else is a new
     * product that is not a device (a SIM package, a service...).
     */
    public function resolveProduct(?int $deviceTypeId, ?string $name): ?Product
    {
        if ($deviceTypeId) {
            $type = DeviceType::find($deviceTypeId);

            return $type ? $this->productForType($type) : null;
        }

        $name = trim(preg_replace('/\s+/', ' ', (string) $name));
        if ($name === '') {
            return null;
        }

        $key = mb_strtolower($name);
        foreach (DeviceType::query()->get(['id', 'device_category', 'model']) as $type) {
            if (mb_strtolower(trim($type->device_category . ' ' . $type->model)) === $key) {
                return $this->productForType($type);
            }
        }

        return Product::whereRaw('LOWER(TRIM(product_name)) = ?', [$key])->orderByRaw('device_type_id IS NULL')->first()
            ?? Product::create(['product_name' => $name]);
    }

    public function productForType(DeviceType $type): Product
    {
        return Product::firstOrCreate(
            ['device_type_id' => $type->id],
            ['product_name' => trim($type->device_category . ' ' . $type->model)]
        );
    }

    /**
     * Replace the supplier's list with $lines ([product_id => ['price' => .., 'qty' => ..]]), refusing to drop a
     * product that an open purchase order or a waiting delivery still needs.
     */
    public function sync(Supplier $supplier, array $lines): void
    {
        $current = $supplier->products()->pluck('products.id')->map(fn ($id) => (int) $id)->all();
        $removed = array_values(array_diff($current, array_map('intval', array_keys($lines))));

        $this->assertRemovable($supplier, $removed);

        $supplier->products()->sync($lines);

        if ($removed !== []) {
            Audit::record('supplier.products_removed', 'supplier', $supplier->id, $supplier->name, [], [
                'products' => Product::whereIn('id', $removed)->pluck('product_name')->all(),
            ]);
        }
    }

    public function detach(Supplier $supplier, int $productId): void
    {
        $this->assertRemovable($supplier, [$productId]);
        $supplier->products()->detach($productId);
    }

    /**
     * Work still open with this supplier: ORDERED purchase orders and scanned deliveries not yet in stock.
     *
     * @return list<string> one sentence per kind of open work (empty = nothing open)
     */
    public function openWork(Supplier $supplier): array
    {
        $out = [];

        if (PurchaseOrders::ready()) {
            $orders = DB::table('supplier_invoices')->where('supplier_id', $supplier->id)
                ->where('status', PurchaseOrders::ORDERED)->orderBy('id')->pluck('invoice_number')->all();
            // An ordered PO counts as open until something has been received against it.
            $orders = array_values(array_filter($orders, fn ($n) => ! DB::table('supplier_deliveries')
                ->join('supplier_invoices', 'supplier_invoices.id', '=', 'supplier_deliveries.supplier_invoice_id')
                ->where('supplier_invoices.invoice_number', $n)->exists()));
            if ($orders !== []) {
                $out[] = 'open purchase order(s) ' . implode(', ', $orders) . ' (cancel them first).';
            }
        }

        if (Schema::hasColumn('supplier_deliveries', 'stocked_in_at')) {
            $waiting = SupplierDelivery::where('supplier_id', $supplier->id)->where('kind', SupplierDelivery::KIND_DEVICE)
                ->whereNull('stocked_in_at')->orderBy('id')->pluck('id')->all();
            if ($waiting !== []) {
                $out[] = 'scanned delivery #' . implode(', #', $waiting) . ' waiting to be added to stock (add or cancel them first).';
            }
        }

        return $out;
    }

    /** @param list<int> $productIds */
    private function assertRemovable(Supplier $supplier, array $productIds): void
    {
        if ($productIds === []) {
            return;
        }

        $problems = [];
        $products = Product::whereIn('id', $productIds)->get();

        foreach ($products as $product) {
            if (PurchaseOrders::ready()) {
                $orders = DB::table('supplier_invoice_items')
                    ->join('supplier_invoices', 'supplier_invoices.id', '=', 'supplier_invoice_items.supplier_invoice_id')
                    ->where('supplier_invoices.supplier_id', $supplier->id)
                    ->where('supplier_invoices.status', PurchaseOrders::ORDERED)
                    ->where('supplier_invoice_items.product_id', $product->id)
                    ->distinct()->pluck('supplier_invoices.invoice_number')->all();
                if ($orders !== []) {
                    $problems[] = "{$product->product_name} is on open purchase order " . implode(', ', $orders) . '. Cancel it or receive it first.';
                    continue;
                }
            }

            if ($product->device_type_id && Schema::hasColumn('supplier_deliveries', 'stocked_in_at')
                && SupplierDelivery::where('supplier_id', $supplier->id)
                    ->where('kind', SupplierDelivery::KIND_DEVICE)
                    ->where('device_type_id', $product->device_type_id)
                    ->whereNull('stocked_in_at')->exists()) {
                $problems[] = "{$product->product_name} has a scanned delivery waiting to be added to stock. Add it to stock (or cancel it) first.";
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages(['products' => $problems]);
        }
    }
}
