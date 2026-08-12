<?php

namespace App\Http\Controllers\Admin\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\Stock;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class SupplierInvoiceController extends Controller
{
    /**
     * Purchase Order wizard + invoice history. supplier_id was already
     * being passed in from the Supplier Management "Invoices" link before
     * this — it was just never read. Now it filters the history list.
     */
    public function index(Request $request)
    {
        $supplierId = $request->query('supplier_id', '');

        $suppliers = Supplier::where('status', 'Active')
            ->orderBy('name')
            ->get();

        $invoices = SupplierInvoice::with('supplier')
            ->when($supplierId !== '', function ($query) use ($supplierId) {
                $query->where('supplier_id', $supplierId);
            })
            ->latest('invoice_date')
            ->latest('id')
            ->get();

        return view('admin.supplier.supplier_invoice', compact(
            'suppliers', 'invoices', 'supplierId'
        ));
    }

    public function download($id)
{
    $invoice = SupplierInvoice::with([
        'supplier',
        'items.product'
    ])->findOrFail($id);

    $pdf = Pdf::loadView('admin.supplier.invoice_pdf', compact('invoice'));

    return $pdf->download(
        $invoice->invoice_number . '.pdf'
    );
}

    /**
     * AJAX: contact details + linked products (with real price/discount
     * from supplier_products) for the supplier just picked in the wizard
     */
    public function getSupplierData($id)
    {
        $supplier = Supplier::with(['products' => function ($query) {
            $query->orderBy('product_name');
        }])->findOrFail($id);

        $products = $supplier->products->map(function ($product) {
            return [
                'id'             => $product->id,
                'product_name'   => $product->product_name,
                'price'          => (float) $product->pivot->price,
                'discount'       => (float) $product->pivot->discount,
                'device_type_id' => $product->device_type_id,
            ];
        });

        return response()->json([
            'supplier' => [
                'name'         => $supplier->name,
                'state'        => $supplier->state,
                'email'        => $supplier->email,
                'phone_number' => $supplier->phone_number,
                'address'      => $supplier->address,
                'gstin_number' => $supplier->gstin_number,
            ],
            'products' => $products,
        ]);
    }

    /**
     * Save the Purchase Order / Invoice. Totals are recalculated here from
     * qty/price/discount/face_value — never trusted from the client, since
     * this is money and Alpine's grandTotal is only for the on-screen
     * preview, not the source of truth.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
        'supplier_id' => 'required|exists:suppliers,id',
        'items' => 'required|array|min:1',
        'items.*.product_id' => 'required|exists:products,id',
        'items.*.type' => 'required|string',
        'items.*.order_qty' => 'required|numeric|min:1',
        'items.*.unit_price' => 'required|numeric|min:0',
        'items.*.discount' => 'nullable|numeric|min:0',
        'items.*.face_value' => 'nullable|numeric|min:0',
    ]);

    return DB::transaction(function () use ($validated) {

        $year = now()->year;

        // PostgreSQL FIX:
        // Don't use lockForUpdate() together with count()
        $count = SupplierInvoice::whereYear('invoice_date', $year)->count();

        $invoiceNumber =
            'PO-' .
            $year .
            '-' .
            str_pad($count + 1, 4, '0', STR_PAD_LEFT);

            $invoice = SupplierInvoice::create([
                'invoice_number' => $invoiceNumber,
                'supplier_id'    => $validated['supplier_id'],
                'invoice_date'   => now()->toDateString(),
                'grand_total'    => 0,
            ]);

            $grandTotal = 0;

            foreach ($validated['items'] as $item) {

                $product = Product::findOrFail($item['product_id']);

                $qty         = (int) $item['order_qty'];
                $unitPrice   = (float) $item['unit_price'];
                $discountPct = (float) ($item['discount'] ?? 0);
                $faceValue   = (float) ($item['face_value'] ?? 0);

                $lineTotal      = $qty * $unitPrice;
                $discountAmount = $lineTotal * ($discountPct / 100);
                $netAmount      = $lineTotal - $discountAmount + $faceValue;

                SupplierInvoiceItem::create([
                    'supplier_invoice_id' => $invoice->id,
                    'product_id'          => $product->id,
                    'type'                => $item['type'],
                    'order_qty'           => $qty,
                    'unit_price'          => $unitPrice,
                    'discount'            => $discountPct,
                    'face_value'          => $faceValue,
                    'net_amount'          => $netAmount,
                ]);

                $grandTotal += $netAmount;

                /*
                | Only products explicitly linked to a device_type touch
                | stock. SIM packages and any unlinked product are recorded
                | on the invoice only — exactly the behaviour that existed
                | before this feature, just now also true for real product
                | rows instead of everything.
                */
                if ($product->device_type_id) {

                    $stock = Stock::lockForUpdate()
                        ->firstOrNew(['device_type_id' => $product->device_type_id]);

                    $stock->supplier_id              = $validated['supplier_id'];
                    $stock->stock_in                 = ($stock->stock_in ?? 0) + $qty;
                    $stock->company_available_stock  = ($stock->company_available_stock ?? 0) + $qty;
                    $stock->total_available           = ($stock->total_available ?? 0) + $qty;
                    $stock->save();
                }
            }

            $invoice->update(['grand_total' => $grandTotal]);

            return $invoice;
        });

        return redirect()
            ->route('admin.supplier-invoice', ['supplier_id' => $invoice->supplier_id])
            ->with('success', "Purchase Order {$invoice->invoice_number} saved successfully.");
    }
}