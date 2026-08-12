<?php

namespace App\Http\Controllers\Admin\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\StockTransferLedger; // Stock වෙනුවට StockTransferLedger Model එක යොදා ගන්න
use Illuminate\Http\Request;

class SupplierManagementController extends Controller
{
    /**
     * Display Supplier Management page.
     */
    public function index(Request $request)
    {
        // Search values from URL
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status', '');

        /*
        |--------------------------------------------------------------------------
        | 1. ALL SUPPLIERS
        |--------------------------------------------------------------------------
        | This list is NOT affected by search.
        | It will always contain all suppliers.
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
        | Only search/filter this list.
        */
        $searchResults = collect();

        if ($search !== '' || $status !== '') {

            $searchResults = Supplier::query()
                ->withCount('products')

                // Search by supplier details
                ->when($search !== '', function ($query) use ($search) {

                    $query->where(function ($q) use ($search) {

                        // PostgreSQL / Supabase uses ILIKE
                        // for case-insensitive searching.
                        $q->where('name', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%")
                            ->orWhere('phone_number', 'ilike', "%{$search}%")
                            ->orWhere('country', 'ilike', "%{$search}%");
                    });
                })

                // Filter by status
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
        | Used when Admin clicks Edit / View.
        */
        $selectedSupplier = null;
        $selectedProducts = collect();
        $availableProducts = collect();
        $stockHistory = collect();


        if ($request->filled('supplier_id')) {

            // Get selected supplier and attached products
            $selectedSupplier = Supplier::with('products')
                ->findOrFail($request->supplier_id);

            $selectedProducts = $selectedSupplier->products;


            /*
            |--------------------------------------------------------------------------
            | Available Products
            |--------------------------------------------------------------------------
            */
            $attachedIds = $selectedProducts->pluck('id');

            $availableProducts = Product::whereNotIn('id', $attachedIds)
                ->orderBy('product_name')
                ->get();


            /*
            |--------------------------------------------------------------------------
            | Supplier Stock / Supply History
            |--------------------------------------------------------------------------
            | FIX: Querying StockTransferLedger instead of Stock table
            | because supplier_id exists in stock_transfer_ledgers.
            */
            $stockHistory = StockTransferLedger::with('stock.deviceType')
                ->where('supplier_id', $selectedSupplier->id)
                ->orderByDesc('created_at')
                ->get();
        }


        /*
        |--------------------------------------------------------------------------
        | 4. LOAD SUPPLIER MANAGEMENT VIEW
        |--------------------------------------------------------------------------
        */
        return view('admin.supplier.supplier_management', compact(

            // All suppliers
            'allSuppliers',

            // Search results
            'searchResults',

            // Selected supplier information
            'selectedSupplier',
            'selectedProducts',
            'availableProducts',
            'stockHistory',

            // Search values
            'search',
            'status'
        ));
    }


    /**
     * Store a new supplier.
     */
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
     * Activate / Deactivate Supplier.
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
     * Attach a product to supplier
     */
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
            ]
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
}