<?php

namespace App\Http\Controllers\Admin\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\Product;
use Illuminate\Http\Request;

class AddSupplierController extends Controller
{
    public function index()
    {
        return view('admin.supplier.add_supplier');
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

        return redirect()
            ->route('admin.suppliers')
            ->with('success', "Supplier '{$supplier->name}' added successfully.");
    }

    public function edit($id)
    {
        $supplier = Supplier::findOrFail($id);
        $allProducts = Product::all();
        $attachedProducts = $supplier->products;

        return view('admin.supplier.edit_supplier', compact(
            'supplier',
            'allProducts',
            'attachedProducts'
        ));
    }

    public function update(Request $request, $id)
    {
        $supplier = Supplier::findOrFail($id);

        $validated = $request->validate([
            'supplier_name' => 'required|string|max:255',
            'address'       => 'nullable|string',
            'country'       => 'nullable|string|max:100',
            'state'         => 'nullable|string|max:100',
            'phone_number'  => 'nullable|string|max:20',
            'email_id'      => 'nullable|email|max:255',
            'website'       => 'nullable|string|max:255',
            'gstin'         => 'nullable|string|max:50',
            'status'        => 'required|in:Active,Archived',
        ]);

        $supplier->update([
            'name'         => $validated['supplier_name'],
            'address'      => $validated['address'] ?? null,
            'country'      => $validated['country'] ?? null,
            'state'        => $validated['state'] ?? null,
            'phone_number' => $validated['phone_number'] ?? null,
            'email'        => $validated['email_id'] ?? null,
            'website'      => $validated['website'] ?? null,
            'gstin_number' => $validated['gstin'] ?? null,
            'status'       => $validated['status'],
        ]);

        return redirect()
            ->route('admin.suppliers.edit', $supplier->id)
            ->with('success', "Supplier '{$supplier->name}' updated successfully.");
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
            ->route('admin.suppliers.edit', $supplier->id)
            ->with('success', 'Product attached to supplier.');
    }
}
