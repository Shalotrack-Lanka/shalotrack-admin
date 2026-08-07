<?php

namespace App\Http\Controllers\Admin\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\Stock;
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
            | Products that are NOT already attached to this supplier.
            |
            | FIX: the products table column is `product_name`, not `name`
            | (see products migration + Product::$fillable). Sorting by
            | `name` here threw SQLSTATE[42703] "column name does not
            | exist" on every Edit click — that was the 500 you were
            | seeing.
            */
            $attachedIds = $selectedProducts->pluck('id');

            $availableProducts = Product::whereNotIn('id', $attachedIds)
                ->orderBy('product_name')
                ->get();


            /*
            |--------------------------------------------------------------------------
            | Supplier Stock / Supply History
            |--------------------------------------------------------------------------
            */
            $stockHistory = Stock::with('deviceType')
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
        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */
        $validated = $request->validate([

            'supplier_name' => 'required|string|max:255',

            'address' => 'nullable|string',

            'country' => 'nullable|string|max:100',

            'state' => 'nullable|string|max:100',

            'phone_number' => 'nullable|string|max:20',

            'email_id' => 'nullable|email|max:255',

            'website' => 'nullable|string|max:255',

            'gstin' => 'nullable|string|max:50',
        ]);


        /*
        |--------------------------------------------------------------------------
        | Create Supplier
        |--------------------------------------------------------------------------
        */
        $supplier = Supplier::create([

            'name' => $validated['supplier_name'],

            'address' => $validated['address'] ?? null,

            'country' => $validated['country'] ?? null,

            'state' => $validated['state'] ?? null,

            'phone_number' => $validated['phone_number'] ?? null,

            'email' => $validated['email_id'] ?? null,

            'website' => $validated['website'] ?? null,

            'gstin_number' => $validated['gstin'] ?? null,

            // New supplier is active by default
            'status' => 'Active',
        ]);


        /*
        |--------------------------------------------------------------------------
        | Redirect to newly created supplier
        |--------------------------------------------------------------------------
        */
        return redirect()
            ->route('admin.suppliers', [
                'supplier_id' => $supplier->id
            ])
            ->with(
                'success',
                "Supplier '{$supplier->name}' added successfully."
            );
    }


    /**
     * Open supplier in Edit / View mode.
     */
    public function edit($id)
    {
        /*
         * Make sure supplier exists.
         */
        Supplier::findOrFail($id);


        /*
         * No separate edit page is required.
         *
         * Redirect back to Supplier Management page
         * with supplier_id.
         */
        return redirect()
            ->route('admin.suppliers', [
                'supplier_id' => $id
            ]);
    }


    /**
     * Update existing supplier.
     */
    public function update(Request $request, $id)
    {
        /*
        |--------------------------------------------------------------------------
        | Find Supplier
        |--------------------------------------------------------------------------
        */
        $supplier = Supplier::findOrFail($id);


        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */
        $validated = $request->validate([

            'supplier_name' => 'required|string|max:255',

            'address' => 'nullable|string',

            'country' => 'nullable|string|max:100',

            'state' => 'nullable|string|max:100',

            'phone_number' => 'nullable|string|max:20',

            'email_id' => 'nullable|email|max:255',

            'website' => 'nullable|string|max:255',

            'gstin' => 'nullable|string|max:50',
        ]);


        /*
        |--------------------------------------------------------------------------
        | Update Supplier
        |--------------------------------------------------------------------------
        */
        $supplier->update([

            'name' => $validated['supplier_name'],

            'address' => $validated['address'] ?? null,

            'country' => $validated['country'] ?? null,

            'state' => $validated['state'] ?? null,

            'phone_number' => $validated['phone_number'] ?? null,

            'email' => $validated['email_id'] ?? null,

            'website' => $validated['website'] ?? null,

            'gstin_number' => $validated['gstin'] ?? null,
        ]);


        /*
        |--------------------------------------------------------------------------
        | Redirect back to selected supplier
        |--------------------------------------------------------------------------
        */
        return redirect()
            ->route('admin.suppliers', [
                'supplier_id' => $supplier->id
            ])
            ->with(
                'success',
                "Supplier '{$supplier->name}' updated successfully."
            );
    }


    /**
     * Activate / Deactivate Supplier.
     *
     * We do not delete suppliers because old stock,
     * invoices and transaction history may depend on them.
     */
    public function toggleStatus($id)
    {
        /*
        |--------------------------------------------------------------------------
        | Find Supplier
        |--------------------------------------------------------------------------
        */
        $supplier = Supplier::findOrFail($id);


        /*
        |--------------------------------------------------------------------------
        | Toggle Status
        |--------------------------------------------------------------------------
        */
        if ($supplier->status === 'Active') {

            $supplier->status = 'Inactive';

        } else {

            $supplier->status = 'Active';
        }


        $supplier->save();


        /*
        |--------------------------------------------------------------------------
        | Redirect
        |--------------------------------------------------------------------------
        */
        return redirect()
            ->route('admin.suppliers', [
                'supplier_id' => $supplier->id
            ])
            ->with(
                'success',
                "Supplier '{$supplier->name}' marked {$supplier->status}."
            );
    }


    /**
     * Attach a product to supplier.
     */
    public function attachProduct(Request $request, $id)
    {
        /*
        |--------------------------------------------------------------------------
        | Find Supplier
        |--------------------------------------------------------------------------
        */
        $supplier = Supplier::findOrFail($id);


        /*
        |--------------------------------------------------------------------------
        | Validate Product Information
        |--------------------------------------------------------------------------
        */
        $validated = $request->validate([

            'product_id' => 'required|exists:products,id',

            'price' => 'required|numeric|min:0',

            'discount' => 'nullable|numeric|min:0',
        ]);


        /*
        |--------------------------------------------------------------------------
        | Attach Product
        |--------------------------------------------------------------------------
        |
        | syncWithoutDetaching() prevents existing supplier products
        | from being removed when adding a new one.
        |
        */
        $supplier->products()->syncWithoutDetaching([

            $validated['product_id'] => [

                'price' => $validated['price'],

                'discount' => $validated['discount'] ?? 0,
            ]
        ]);


        /*
        |--------------------------------------------------------------------------
        | Redirect
        |--------------------------------------------------------------------------
        */
        return redirect()
            ->route('admin.suppliers', [
                'supplier_id' => $supplier->id
            ])
            ->with(
                'success',
                'Product added to supplier successfully.'
            );
    }


    /**
     * Remove a product from supplier.
     */
    public function detachProduct($id, $productId)
    {
        /*
        |--------------------------------------------------------------------------
        | Find Supplier
        |--------------------------------------------------------------------------
        */
        $supplier = Supplier::findOrFail($id);


        /*
        |--------------------------------------------------------------------------
        | Remove Product Relationship
        |--------------------------------------------------------------------------
        */
        $supplier->products()->detach($productId);


        /*
        |--------------------------------------------------------------------------
        | Redirect
        |--------------------------------------------------------------------------
        */
        return redirect()
            ->route('admin.suppliers', [
                'supplier_id' => $supplier->id
            ])
            ->with(
                'success',
                'Product removed from supplier successfully.'
            );
    }
}