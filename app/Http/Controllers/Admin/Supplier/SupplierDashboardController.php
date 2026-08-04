<?php

namespace App\Http\Controllers\Admin\Supplier;

use App\Http\Controllers\Controller;

class SupplierDashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $supplier = $user->supplier; // null if this account was never linked

        if (!$supplier) {
            return view('supplier.dashboard', [
                'supplier' => null,
            ]);
        }

        // Real relationship: Supplier::products() via the supplier_products
        // pivot table, with real price/discount data.
        $products = $supplier->products;
        $totalProducts = $products->count();

        // Deliberately NOT passing $totalSupplied, $pendingOrders,
        // $pendingPayments, $orders, $invoices, $activities — none of these
        // have a real backing table in the current schema (no Order or
        // Invoice model exists anywhere in the app). The view shows an
        // honest "not built yet" state for these instead of fake zeros.

        return view('supplier.dashboard', compact('supplier', 'products', 'totalProducts'));
    }
}