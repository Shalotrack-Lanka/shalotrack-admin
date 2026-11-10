<?php

namespace App\Http\Controllers\Admin\Supplier;

use App\Support\Input;
use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Services\Supplier\PurchaseOrders;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Purchase orders to suppliers (ADMIN only, enforced by the route group). The rules live in
 * App\Services\Supplier\PurchaseOrders: only the supplier's own products, totals worked out on the server, an
 * order never changes stock (stock goes up when the delivery is added on Manage Stock), editable until goods are
 * received against it, cancelled rather than deleted.
 */
class SupplierInvoiceController extends Controller
{
    public function __construct(private PurchaseOrders $orders)
    {
    }

    /** New purchase order + the order history (optionally for one supplier). */
    public function index(Request $request)
    {
        // A supplier id from the Supplier Management link; anything that is not a plain id means "all".
        $supplierId = Input::id($request->query('supplier_id'));

        $suppliers = Supplier::where('status', 'Active')
            ->orderBy('name')
            ->get();

        $invoices = SupplierInvoice::with('supplier')
            ->when($supplierId !== null, function ($query) use ($supplierId) {
                $query->where('supplier_id', $supplierId);
            })
            ->latest('invoice_date')
            ->latest('id')
            ->limit(200)
            ->get();

        return view('admin.supplier.supplier_invoice', [
            'suppliers'  => $suppliers,
            'invoices'   => $invoices,
            'supplierId' => $supplierId,
            'summaries'  => $invoices->mapWithKeys(fn ($i) => [$i->id => $this->orders->summary($i)]),
            'editing'    => null,
        ]);
    }

    /** The same form, filled with an order that can still be changed. */
    public function edit(int $id)
    {
        $invoice = SupplierInvoice::with(['supplier', 'items.product'])->findOrFail($id);

        if ($reason = $this->orders->lockReason($invoice)) {
            return redirect()->route('admin.supplier-invoice', ['supplier_id' => $invoice->supplier_id])->with('error', $reason);
        }

        return view('admin.supplier.supplier_invoice', [
            'suppliers'  => Supplier::whereKey($invoice->supplier_id)->get(),
            'invoices'   => collect(),
            'supplierId' => $invoice->supplier_id,
            'summaries'  => collect(),
            'editing'    => [
                'id'     => $invoice->id,
                'number' => $invoice->invoice_number,
                'items'  => $invoice->items->map(fn ($i) => [
                    'product_id' => (string) $i->product_id,
                    'type'       => $i->type,
                    'order_qty'  => (int) $i->order_qty,
                    'unit_price' => (float) $i->unit_price,
                    'discount'   => (float) $i->discount,
                    'face_value' => (float) $i->face_value,
                ])->values(),
            ],
        ]);
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
     * AJAX: contact details + the products on this supplier's product list, with the agreed price. Only these
     * products can be put on an order to this supplier.
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
                'qty'            => 0,     // no typed "usual quantity" any more: the order quantity is chosen on the order
                // supplier_products.discount exists but no screen fills it in (and the relation never loaded it, so it
                // always came through as 0). A discount is agreed per order, on the order line.
                'discount'       => 0.0,
                'device_type_id' => $product->device_type_id,
                'type'           => $product->device_type_id ? 'Device' : (preg_match('/\bsim\b/i', (string) $product->product_name) ? 'SIM' : 'Other'),
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

    /** Save a new purchase order. It records what was ordered; it never changes stock. */
    public function store(Request $request)
    {
        $validated = $request->validate(array_merge([
            // bail + integer: a text or oversized id must stop here, before it reaches PostgreSQL (a 500 otherwise).
            'supplier_id' => ['bail', 'required', 'integer', 'min:1', Rule::exists('suppliers', 'id')->where('status', 'Active')],
        ], $this->lineRules()));

        $invoice = $this->orders->create((int) $validated['supplier_id'], $validated['items'], $this->adminId($request));

        return redirect()
            ->route('admin.supplier-invoice', ['supplier_id' => $invoice->supplier_id])
            ->with('success', "Purchase Order {$invoice->invoice_number} saved successfully. Stock changes when the delivery is received and added to stock.");
    }

    public function update(Request $request, int $id)
    {
        $invoice = SupplierInvoice::findOrFail($id);
        $validated = $request->validate($this->lineRules());

        $this->orders->update($invoice, $validated['items'], $this->adminId($request));

        return redirect()
            ->route('admin.supplier-invoice', ['supplier_id' => $invoice->supplier_id])
            ->with('success', "Purchase Order {$invoice->invoice_number} updated.");
    }

    public function cancel(Request $request, int $id)
    {
        $invoice = SupplierInvoice::findOrFail($id);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);

        $this->orders->cancel($invoice, trim($data['reason']), $this->adminId($request));

        return back()->with('success', "Purchase Order {$invoice->invoice_number} was cancelled.");
    }

    /** The one exception to the lock after the first scan: one line's quantity, with a reason, never below what arrived. */
    public function adjust(Request $request, int $id)
    {
        $invoice = SupplierInvoice::findOrFail($id);
        $data = $request->validate([
            'item_id'   => ['required', 'integer', 'min:1'],
            'order_qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason'    => ['required', 'string', 'min:5', 'max:200'],
        ]);

        $this->orders->adjustQuantity($invoice, (int) $data['item_id'], (int) $data['order_qty'], trim($data['reason']), $this->adminId($request));

        return back()->with('success', "Purchase Order {$invoice->invoice_number} quantity adjusted. The change is in the audit log.");
    }

    /** The maximums keep every number inside its database column (qty int4, money decimal(12,2), discount %). */
    private function lineRules(): array
    {
        return [
            'items'              => 'required|array|min:1|max:200',
            'items.*.product_id' => 'bail|required|integer|min:1|exists:products,id',
            'items.*.type'       => 'required|string|max:50',
            'items.*.order_qty'  => 'required|integer|min:1|max:1000000',
            'items.*.unit_price' => 'required|numeric|min:0|max:9999999',
            'items.*.discount'   => 'nullable|numeric|min:0|max:100',
            'items.*.face_value' => 'nullable|numeric|min:0|max:9999999',
        ];
    }

    private function adminId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? (string) $id : null;
    }
}
