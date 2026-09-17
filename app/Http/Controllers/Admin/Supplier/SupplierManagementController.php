<?php

namespace App\Http\Controllers\Admin\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Supplier;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierManagementController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status', '');

        $allProducts = Product::orderBy('product_name')->get();

        $allSuppliers = Supplier::query()
            ->with(['products'])
            ->withCount('products')
            ->orderByRaw("status = 'Active' DESC")
            ->orderBy('name')
            ->get();

        $searchResults = collect();

        if ($search !== '' || $status !== '') {
            $searchResults = Supplier::query()
                ->with(['products'])
                ->withCount('products')
                ->when($search !== '', function ($query) use ($search) {
                    $query->where(function ($q) use ($search) {
                        $q->where('name', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%")
                            ->orWhere('phone_number', 'ilike', "%{$search}%")
                            ->orWhere('country', 'ilike', "%{$search}%");
                    });
                })
                ->when(
                    in_array($status, ['Active', 'Inactive'], true),
                    function ($query) use ($status) {
                        $query->where('status', $status);
                    }
                )
                ->orderByRaw("status = 'Active' DESC")
                ->orderBy('name')
                ->get();
        }

        return view('admin.supplier.supplier_management', compact(
            'allSuppliers',
            'searchResults',
            'search',
            'status',
            'allProducts'
        ));
    }

    public function store(Request $request)
    {
        $validated = $this->validateSupplierInput($request);

        $supplier = Supplier::create([
            'name'         => $validated['supplier_name'],
            'address'      => $validated['address'] ?? null,
            'country'      => $validated['country'] ?? null,
            'state'        => $validated['state'] ?? null,
            'phone_number' => $validated['phone_number'] ?? null,
            'email'        => $validated['email_id'] ?? null,
            'website'      => $validated['website'] ?? null,
            'gstin_number' => $validated['gstin'] ?? null,
            'status'       => 'Active',
        ]);

        $this->syncProducts($supplier, $request->products);

        return redirect()
            ->route('admin.suppliers')
            ->with('success', "Supplier '{$supplier->name}' added successfully.");
    }
    
    public function update(Request $request, $id)
    {
        $supplier = Supplier::findOrFail($id);

        $validated = $this->validateSupplierInput($request, $supplier->id);

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

        $this->syncProducts($supplier, $request->products);

        return redirect()
            ->route('admin.suppliers')
            ->with('success', "Supplier '{$supplier->name}' updated successfully.");
    }

    public function toggleStatus($id)
    {
        $supplier = Supplier::findOrFail($id);
        $supplier->status = ($supplier->status === 'Active') ? 'Inactive' : 'Active';
        $supplier->save();

        $adminStatus = $supplier->status === 'Active' ? 'ACTIVE' : 'INACTIVE';
        Admin::where('supplier_id', $supplier->id)->update(['status' => $adminStatus]);

        $label = $supplier->status === 'Active' ? 'activated' : 'deactivated';

        return redirect()
            ->route('admin.suppliers')
            ->with('success', "Supplier '{$supplier->name}' {$label}.");
    }

    private function validateSupplierInput(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'supplier_name' => 'required|string|max:255',
            'address'       => 'nullable|string|max:500',
            'country'       => 'nullable|string|max:100',
            'state'         => 'nullable|string|max:100',
            'phone_number'  => ['nullable', 'regex:/^[+]?[0-9\s\-\(\)]{7,20}$/'],
            'email_id'      => ['nullable', 'email', 'max:255', Rule::unique('suppliers', 'email')->ignore($ignoreId)],
            'website'       => 'nullable|string|max:255',
            'gstin'         => 'nullable|string|max:50',
            'products'      => 'nullable|array',
        ], [
            'phone_number.regex' => 'Phone number must contain only digits, spaces, hyphens, parentheses, or a leading +.',
            'email_id.unique'    => 'This email address is already registered to another supplier.',
        ]);
    }

    // 💡 UPDATE: Aluthin type karana ewa automatically Products table ekata save wenna haduwa
    private function syncProducts(Supplier $supplier, ?array $products)
    {
        if (is_array($products)) {
            $syncData = [];
            foreach ($products as $prod) {
                if (!empty($prod['product_name'])) {
                    
                    $productName = trim($prod['product_name']);
                    
                    // Name eka thiyenawada check karanawa, nathnam on the fly aluth ekak hadanawa
                    $product = Product::firstOrCreate(
                        ['product_name' => $productName]
                    );

                    $syncData[$product->id] = [
                        'price' => $prod['price'] ?? 0,
                        'qty'   => $prod['qty'] ?? 0,
                    ];
                }
            }
            $supplier->products()->sync($syncData);
        } else {
            $supplier->products()->sync([]);
        }
    }
}