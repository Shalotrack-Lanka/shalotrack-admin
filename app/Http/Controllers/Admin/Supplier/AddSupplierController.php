<?php

namespace App\Http\Controllers\Admin\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\Stock;
use Illuminate\Http\Request;

class AddSupplierController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status', '');

        $suppliers = Supplier::query()
            ->withCount('products')
            ->when($search !== '', function ($query) use ($search) {
                // ilike, not like — this is Postgres (Supabase). `like` is
                // case-sensitive on Postgres, so searching "acme" would
                // silently miss "Acme Traders" if we used `like` here.
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'ilike', "%{$search}%")
                      ->orWhere('email', 'ilike', "%{$search}%")
                      ->orWhere('phone_number', 'ilike', "%{$search}%")
                      ->orWhere('country', 'ilike', "%{$search}%");
                });
            })
            ->when(in_array($status, ['Active', 'Inactive'], true), function ($query) use ($status) {
                $query->where('status', $status);
            })
            // Active suppliers surface first, but Inactive ones stay visible —
            // otherwise there'd be no way to find and reactivate one.
            ->orderByRaw("status = 'Active' desc")
            ->orderBy('name')
            ->get();

        $selectedSupplier  = null;
        $selectedProducts  = collect();
        $availableProducts = collect();
        $stockHistory       = collect();

        if ($request->filled('supplier_id')) {
            $selectedSupplier = Supplier::with('products')->findOrFail($request->supplier_id);
            $selectedProducts = $selectedSupplier->products;

            $attachedIds       = $selectedProducts->pluck('id');
            $availableProducts = Product::whereNotIn('id', $attachedIds)->get();

            $stockHistory = Stock::with('deviceType')
                ->where('supplier_id', $selectedSupplier->id)
                ->orderByDesc('created_at')
                ->get();
        }

        return view('admin.supplier.add_supplier', compact(
            'suppliers', 'selectedSupplier', 'selectedProducts',
            'availableProducts', 'stockHistory', 'search', 'status'
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

    /**
     * routes/web.php already wired GET /{id}/edit to this method before
     * today — it just didn't exist, so that route was a live 500. There's
     * no separate edit view: the index page already renders a full edit
     * form inline once a supplier is selected, so this just sends the
     * request to the same place the "Edit" button already goes.
     */
    public function edit($id)
    {
        Supplier::findOrFail($id); // 404s early if the id is bogus

        return redirect()->route('admin.suppliers', ['supplier_id' => $id]);
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
        ]);

        return redirect()
            ->route('admin.suppliers', ['supplier_id' => $supplier->id])
            ->with('success', "Supplier '{$supplier->name}' updated successfully.");
    }

    /**
     * Active/Inactive toggle. Deliberately not a delete — previous stock
     * and transaction history must stay queryable against this supplier.
     */
    public function toggleStatus($id)
    {
        $supplier = Supplier::findOrFail($id);
        $supplier->status = $supplier->status === 'Active' ? 'Inactive' : 'Active';
        $supplier->save();

        return redirect()
            ->route('admin.suppliers', ['supplier_id' => $supplier->id])
            ->with('success', "Supplier '{$supplier->name}' marked {$supplier->status}.");
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