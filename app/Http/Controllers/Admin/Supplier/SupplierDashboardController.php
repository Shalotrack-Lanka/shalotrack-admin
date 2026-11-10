<?php

namespace App\Http\Controllers\Admin\Supplier;

use App\Http\Controllers\Controller;
use App\Models\StockTransferLedger;
use App\Models\SupplierDelivery;
use App\Models\SupplierInvoice;
use App\Services\Supplier\PurchaseOrders;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Schema;

/**
 * The supplier's own portal (SUPPLIER role, enforced by the route group). Read-only and always scoped to the
 * supplier linked to the logged-in account: their product list, the purchase orders the company placed with them
 * and what has been received. Nothing about other suppliers is ever loaded.
 */
class SupplierDashboardController extends Controller
{
    public function index(PurchaseOrders $orders)
    {
        $supplier = auth()->user()->supplier; // null if this account was never linked

        if (! $supplier) {
            return view('supplier.dashboard', ['supplier' => null]);
        }

        $products = $supplier->products()->orderBy('product_name')->get();

        $purchaseOrders = SupplierInvoice::where('supplier_id', $supplier->id)
            ->with('items.product')
            ->latest('invoice_date')->latest('id')
            ->limit(50)
            ->get();
        $summaries = $purchaseOrders->mapWithKeys(fn ($o) => [$o->id => $orders->summary($o)]);

        // Units the company has received from this supplier: scanned on deliveries, or stocked in by hand.
        $scanned = Schema::hasTable('supplier_delivery_units')
            ? (int) SupplierDelivery::where('supplier_id', $supplier->id)->withCount('units')->get()->sum('units_count')
            : 0;
        $stockedIn = (int) StockTransferLedger::where('supplier_id', $supplier->id)->sum('stock_in');

        $openOrders = $purchaseOrders->filter(fn ($o) => ! $o->isCancelled() && ($summaries[$o->id]['editable'] ?? false))->count();

        return view('supplier.dashboard', [
            'supplier'       => $supplier,
            'products'       => $products,
            'totalProducts'  => $products->count(),
            'purchaseOrders' => $purchaseOrders,
            'summaries'      => $summaries,
            'progress'       => $orders->progressByProduct((int) $supplier->id),
            'unitsScanned'   => $scanned,
            'unitsStocked'   => $stockedIn,
            'openOrders'     => $openOrders,
        ]);
    }

    /** One of THIS supplier's purchase orders as a PDF. Another supplier's order is a 404, never a 403 (no hint it exists). */
    public function downloadOrder(int $id)
    {
        $supplier = auth()->user()->supplier;
        abort_if(! $supplier, 404);

        $invoice = SupplierInvoice::with(['supplier', 'items.product'])
            ->where('supplier_id', $supplier->id)
            ->findOrFail($id);

        return Pdf::loadView('admin.supplier.invoice_pdf', compact('invoice'))->download($invoice->invoice_number . '.pdf');
    }
}
