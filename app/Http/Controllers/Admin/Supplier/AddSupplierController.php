<?php

namespace App\Http\Controllers\Admin\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\Product;
use Illuminate\Http\Request;

class AddSupplierController extends Controller
{
    public function index(Request $request)
{
    $suppliers = Supplier::where('status', 'Active')
        ->withCount('products')
        ->latest()
        ->get();

    $selectedSupplier  = null;
    $selectedProducts  = collect();
    $availableProducts = collect();

    if ($request->filled('supplier_id')) {
        $selectedSupplier = Supplier::with('products')->findOrFail($request->supplier_id);
        $selectedProducts = $selectedSupplier->products;

        $attachedIds       = $selectedProducts->pluck('id');
        $availableProducts = Product::whereNotIn('id', $attachedIds)->get();
    }

    return view('admin.supplier.add_supplier', compact(
        'suppliers', 'selectedSupplier', 'selectedProducts', 'availableProducts'
    ));
}

public function store(Request $request)
{
    $validated = $request->validate([
        'supplier_name' => 'required|string|max:255',
        'address'       => 'nullable|string',
        'country'       => 'nullable|string|max:100',
        'state'         => 'nullable|string|max:100',
        'phone_number'  => 'nullable|string|max:20',
        'email_id'      => 'nullable|email|max:255',
        'website'       => 'nullable|string|max:255',
        'gstin'         => 'nullable|string|max:50',
    ]);

    $supplier = Supplier::create([
        'name'         => $validated['supplier_name'],
        'address'      => $validated['address'] ?? null,
        'country'      => $validated['country'] ?? null,
        'state'        => $validated['state'] ?? null,
        'phone_number' => $validated['phone_number'] ?? null,
        'email'        => $validated['email_id'] ?? null,
        'website'      => $validated['website'] ?? null,
        'gstin_number' => $validated['gstin'] ?? null,
    ]);

    // Jump straight into product-selection mode for the supplier just created —
    // that's the natural next step per your flow diagram.
    return redirect()
        ->route('admin.suppliers', ['supplier_id' => $supplier->id])
        ->with('success', "Supplier '{$supplier->name}' added successfully.");
}

public function attachProduct(Request $request, $id)
{
    $supplier = Supplier::findOrFail($id);

    $validated = $request->validate([
        'product_id' => 'required|exists:products,id',
        'price'      => 'required|numeric|min:0',
        'discount'   => 'nullable|numeric|min:0',
    ]);

    $supplier->products()->syncWithoutDetaching([
        $validated['product_id'] => [
            'price'    => $validated['price'],
            'discount' => $validated['discount'] ?? 0,
        ],
    ]);

    return redirect()
        ->route('admin.suppliers', ['supplier_id' => $supplier->id])
        ->with('success', 'Product added to supplier.');
}

public function detachProduct($id, $productId)
{
    $supplier = Supplier::findOrFail($id);
    $supplier->products()->detach($productId);

    return redirect()
        ->route('admin.suppliers', ['supplier_id' => $supplier->id])
        ->with('success', 'Product removed from supplier.');
}
}
