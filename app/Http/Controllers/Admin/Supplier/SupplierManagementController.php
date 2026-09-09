<?php

namespace App\Http\Controllers\Admin\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\StockTransferLedger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierManagementController extends Controller
{
    /**
     * Display Supplier Management page.
     */
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status', '');

        /*
        |--------------------------------------------------------------------------
        | 1. ALL SUPPLIERS
        |--------------------------------------------------------------------------
        | Not affected by search — always shows every supplier in the sidebar.
        */
        $allSuppliers = Supplier::query()
            ->withCount('products')
            ->orderByRaw("status = 'Active' DESC")
            ->orderBy('name')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | 2. SEARCH RESULTS
        |--------------------------------------------------------------------------
        */
        $searchResults = collect();

        if ($search !== '' || $status !== '') {
            $searchResults = Supplier::query()
                ->withCount('products')
                ->when($search !== '', function ($query) use ($search) {
                    $query->where(function ($q) use ($search) {
                        // PostgreSQL / Supabase: ILIKE for case-insensitive matching.
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

        /*
        |--------------------------------------------------------------------------
        | 3. SELECTED SUPPLIER
        |--------------------------------------------------------------------------
        */
        $selectedSupplier  = null;
        $selectedProducts  = collect();
        $availableProducts = collect();
        $stockHistory      = collect();

        if ($request->filled('supplier_id')) {
            $selectedSupplier = Supplier::with('products')
                ->findOrFail($request->supplier_id);

            $selectedProducts = $selectedSupplier->products;

            $attachedIds = $selectedProducts->pluck('id');

            $availableProducts = Product::whereNotIn('id', $attachedIds)
                ->orderBy('product_name')
                ->get();

            $stockHistory = StockTransferLedger::with('stock.deviceType')
                ->where('supplier_id', $selectedSupplier->id)
                ->orderByDesc('created_at')
                ->get();
        }

        return view('admin.supplier.supplier_management', compact(
            'allSuppliers',
            'searchResults',
            'selectedSupplier',
            'selectedProducts',
            'availableProducts',
            'stockHistory',
            'search',
            'status'
        ));
    }


    /**
     * Store a new supplier.
     */
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

        return redirect()
            ->route('admin.suppliers', ['supplier_id' => $supplier->id])
            ->with('success', "Supplier '{$supplier->name}' added successfully.");
    }


    /**
     * Open supplier in Edit / View mode.
     */
    public function edit($id)
    {
        Supplier::findOrFail($id);

        return redirect()
            ->route('admin.suppliers', ['supplier_id' => $id]);
    }


    /**
     * Update existing supplier.
     */
    public function update(Request $request, $id)
    {
        $supplier = Supplier::findOrFail($id);

        // Pass the current supplier's ID so the email uniqueness rule
        // ignores this supplier's own existing email when comparing.
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

        return redirect()
            ->route('admin.suppliers', ['supplier_id' => $supplier->id])
            ->with('success', "Supplier '{$supplier->name}' updated successfully.");
    }


    /**
     * Activate / Deactivate Supplier — admin-only action.
     */
    public function toggleStatus($id)
    {
        $supplier = Supplier::findOrFail($id);

        $supplier->status = ($supplier->status === 'Active') ? 'Inactive' : 'Active';
        $supplier->save();

        return redirect()
            ->route('admin.suppliers', ['supplier_id' => $supplier->id])
            ->with('success', "Supplier '{$supplier->name}' marked {$supplier->status}.");
    }


    /**
     * Attach a product to supplier.
     */
    public function attachProduct(Request $request, $id)
    {
        $supplier = Supplier::findOrFail($id);

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'price'      => 'required|numeric|min:0|max:99999999.99',
            'discount'   => 'nullable|numeric|min:0|max:100',
        ]);

        $supplier->products()->syncWithoutDetaching([
            $validated['product_id'] => [
                'price'    => $validated['price'],
                'discount' => $validated['discount'] ?? 0,
            ],
        ]);

        return redirect()
            ->route('admin.suppliers', ['supplier_id' => $supplier->id])
            ->with('success', 'Product added to supplier successfully.');
    }


    /**
     * Remove a product from supplier.
     */
    public function detachProduct($id, $productId)
    {
        $supplier = Supplier::findOrFail($id);

        $supplier->products()->detach($productId);

        return redirect()
            ->route('admin.suppliers', ['supplier_id' => $supplier->id])
            ->with('success', 'Product removed from supplier successfully.');
    }


    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Shared validation rules for store() and update().
     *
     * Previously store() and update() had identical rules copy-pasted —
     * one source of truth now, with the email uniqueness rule correctly
     * ignoring the supplier's own record during an update.
     *
     * @param  Request   $request
     * @param  int|null  $ignoreId  Supplier ID to exclude from the email
     *                              uniqueness check (null on create).
     */
    private function validateSupplierInput(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'supplier_name' => 'required|string|max:255',
            'address'       => 'nullable|string|max:500',
            'country'       => 'nullable|string|max:100',
            'state'         => 'nullable|string|max:100',

            // Phone: optional, but if provided must look like a real phone
            // number. Accepts international format (+94 77 123 4567),
            // local Sri Lankan (077 123 4567), and common separators.
            'phone_number' => [
                'nullable',
                'regex:/^[+]?[0-9\s\-\(\)]{7,20}$/',
            ],

            // Email: optional, valid format, and unique across the suppliers
            // table — except for the supplier being updated right now.
            'email_id' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('suppliers', 'email')->ignore($ignoreId),
            ],

            // Website: must be a real URL if provided, not just any string.
            'website' => 'nullable|url|max:255',

            'gstin' => 'nullable|string|max:50',
        ], [
            'phone_number.regex' => 'Phone number must contain only digits, spaces, hyphens, parentheses, or a leading +.',
            'email_id.unique'    => 'This email address is already registered to another supplier.',
            'website.url'        => 'Website must be a valid URL (e.g. https://example.com).',
        ]);
    }
}