<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * DATA REPAIR, no schema change.
     *
     * Until now, adding stock removed the device from the supplier's product list ("it has been received"). The
     * list now means "what this supplier supplies", and ordering, receiving and stocking in all require the device
     * to be on it. So every supplier who has ever stocked in a device type gets that device back on its list
     * (agreed price 0, to be filled in on Supplier Management). The stock-in history is the proof they supply it.
     *
     * Idempotent (only adds missing links, creates the device's product only if it has none) and touches only
     * supplier_products / products. Nothing is removed, so down() does nothing.
     */
    public function up(): void
    {
        $pairs = DB::table('stock_transfer_ledgers')
            ->join('stocks', 'stocks.id', '=', 'stock_transfer_ledgers.stock_id')
            ->join('suppliers', 'suppliers.id', '=', 'stock_transfer_ledgers.supplier_id')
            ->join('device_types', 'device_types.id', '=', 'stocks.device_type_id')
            ->whereNotNull('stock_transfer_ledgers.supplier_id')
            ->distinct()
            ->get(['stock_transfer_ledgers.supplier_id', 'stocks.device_type_id', 'device_types.device_category', 'device_types.model']);

        $now = now();
        foreach ($pairs as $pair) {
            $productId = DB::table('products')->where('device_type_id', $pair->device_type_id)->orderBy('id')->value('id')
                ?? DB::table('products')->insertGetId([
                    'product_name'   => trim($pair->device_category . ' ' . $pair->model),
                    'device_type_id' => $pair->device_type_id,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);

            $linked = DB::table('supplier_products')
                ->join('products', 'products.id', '=', 'supplier_products.product_id')
                ->where('supplier_products.supplier_id', $pair->supplier_id)
                ->where('products.device_type_id', $pair->device_type_id)
                ->exists();

            if (! $linked) {
                DB::table('supplier_products')->insert([
                    'supplier_id' => $pair->supplier_id,
                    'product_id'  => $productId,
                    'price'       => 0,
                    'qty'         => 0,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Restored links are ordinary product-list entries now; they are not taken away again.
    }
};
