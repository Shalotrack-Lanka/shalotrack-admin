<?php

namespace App\Http\Controllers\Admin\Supplier;

use App\Support\Input;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\DeviceType;
use App\Services\Supplier\SupplierCatalogue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SupplierManagementController extends Controller
{
    public function index(Request $request)
    {
        $search = Input::text($request->query('search'), 100);
        $status = Input::choice($request->query('status'), ['Active', 'Inactive'], '');

        // 1. Get all device types (to show V5 Basic, V10 Plus etc. in the dropdown)
        $deviceTypes = DeviceType::orderBy('device_category')->orderBy('model')->get();

        $allProducts = Product::orderBy('product_name')->get();
        // Non-device products (SIM packages, services) for the product picker; devices are picked by device type.
        $otherProducts = $allProducts->whereNull('device_type_id')->values();

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
                        $like = Input::contains($search);
                        $q->where('name', 'ilike', $like)
                            ->orWhere('email', 'ilike', $like)
                            ->orWhere('phone_number', 'ilike', $like)
                            ->orWhere('country', 'ilike', $like);
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
            'allProducts',
            'otherProducts',
            'deviceTypes'
        ));
    }

    public function store(Request $request)
    {
        $validated = $this->validateSupplierInput($request);

        $supplier = DB::transaction(fn () => tap(Supplier::create([
            'name'         => $validated['supplier_name'],
            'address'      => $validated['address'] ?? null,
            'country'      => $validated['country'] ?? null,
            'state'        => $validated['state'] ?? null,
            'phone_number' => $validated['phone_number'] ?? null,
            'email'        => $validated['email_id'] ?? null,
            'website'      => $validated['website'] ?? null,
            'gstin_number' => $validated['gstin'] ?? null,
            'status'       => 'Active',
        ]), fn ($supplier) => $this->syncProducts($supplier, $validated['products'] ?? null)));

        return redirect()
            ->route('admin.suppliers')
            ->with('success', "Supplier '{$supplier->name}' added successfully.");
    }
    
    public function update(Request $request, $id)
    {
        $supplier = Supplier::findOrFail($id);

        $validated = $this->validateSupplierInput($request, $supplier->id);

        // One transaction: if a product cannot be removed (an open order or a waiting delivery needs it), nothing
        // about the supplier is changed.
        DB::transaction(function () use ($supplier, $validated) {
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

            $this->syncProducts($supplier, $validated['products'] ?? null);
        });

        return redirect()
            ->route('admin.suppliers')
            ->with('success', "Supplier '{$supplier->name}' updated successfully.");
    }

    // Route existed (admin.suppliers.edit) but this method never did —
    // hitting it threw "Call to undefined method". The Edit Supplier
    // modal in supplier_management.blade.php is actually populated
    // server-side inline and submits to update(), not this route, so
    // this exists as a JSON data endpoint for any future AJAX-driven
    // edit UI rather than something currently in the request path.
    public function edit($id)
    {
        $supplier = Supplier::with('products')->findOrFail($id);

        return response()->json([
            'id'           => $supplier->id,
            'name'         => $supplier->name,
            'address'      => $supplier->address,
            'country'      => $supplier->country,
            'state'        => $supplier->state,
            'phone_number' => $supplier->phone_number,
            'email'        => $supplier->email,
            'website'      => $supplier->website,
            'gstin_number' => $supplier->gstin_number,
            'status'       => $supplier->status,
            'products'     => $supplier->products->map(fn ($p) => [
                'product_id'      => $p->id,
                'device_type_id'  => $p->device_type_id,
                'product_name'    => $p->product_name,
                'price'           => $p->pivot->price,
                            ])->values(),
        ]);
    }

    // Route existed (admin.suppliers.attach-product) but this method
    // never did. Unlike update()'s syncProducts() — which replaces the
    // supplier's ENTIRE product list in one shot — this attaches a
    // single product without touching the others, using the same
    // device-type-or-freetext resolution as syncProducts() so both
    // paths create identical Product rows.
    public function attachProduct(Request $request, $id)
    {
        $supplier = Supplier::findOrFail($id);

        $validated = $request->validate([
            'device_type_id' => 'bail|nullable|integer|min:1|exists:device_types,id',
            'product_name'   => 'required_without:device_type_id|nullable|string|max:255',
            'price'          => 'required|numeric|min:0|max:99999999',
        ]);

        $product = app(SupplierCatalogue::class)->resolveProduct(
            ! empty($validated['device_type_id']) ? (int) $validated['device_type_id'] : null,
            $validated['product_name'] ?? null,
        );

        $supplier->products()->syncWithoutDetaching([
            $product->id => [
                'price' => $validated['price'],
                'qty'   => 0,   // no typed quantity any more: ordered / received come from purchase orders and deliveries
            ],
        ]);

        return redirect()
            ->route('admin.suppliers')
            ->with('success', "Product '{$product->product_name}' attached to '{$supplier->name}'.");
    }

    // Route existed (admin.suppliers.detach-product) but this method
    // never did. Detaches one product from the pivot without touching
    // the supplier's other products.
    public function detachProduct($id, $productId)
    {
        $supplier = Supplier::findOrFail($id);
        app(SupplierCatalogue::class)->detach($supplier, (int) $productId);

        return redirect()
            ->route('admin.suppliers')
            ->with('success', 'Product removed from ' . $supplier->name . '.');
    }

    public function toggleStatus($id)
    {
        $supplier = Supplier::findOrFail($id);

        // An Inactive supplier cannot be ordered from, received from or stocked in from, so open work would be
        // stranded: finish or cancel it first.
        if ($supplier->status === 'Active' && ($open = app(SupplierCatalogue::class)->openWork($supplier)) !== []) {
            return redirect()->route('admin.suppliers')
                ->withErrors(['status' => "{$supplier->name} cannot be deactivated yet: " . implode(' ', $open)]);
        }

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
            'products'      => 'nullable|array|max:500',
            // Each line is checked before syncProducts() runs: a text value in device_type_id used to be looked up
            // as a number (a database error on PostgreSQL) and a text price broke the save.
            'products.*'                => 'array',
            'products.*.device_type_id' => 'bail|nullable|integer|min:1|exists:device_types,id',
            'products.*.product_name'   => 'nullable|string|max:255',
            'products.*.price'          => 'nullable|numeric|min:0|max:99999999',
        ], [
            'phone_number.regex' => 'Phone number must contain only digits, spaces, hyphens, parentheses, or a leading +.',
            'email_id.unique'    => 'This email address is already registered to another supplier.',
        ]);
    }

    /**
     * Save the supplier's product list. Each line is a device type (device_type_id) or a product name; see
     * SupplierCatalogue::resolveProduct(). Two lines for the same product are merged (the last price wins, the
     * quantities are added) instead of one silently replacing the other.
     */
    private function syncProducts(Supplier $supplier, ?array $products): void
    {
        $catalogue = app(SupplierCatalogue::class);
        $syncData = [];
        $existing = $supplier->products()->get()->mapWithKeys(fn ($p) => [(int) $p->id => (int) ($p->pivot->qty ?? 0)])->all();

        foreach ($products ?? [] as $prod) {
            $raw = $prod['device_type_id'] ?? null;
            // Older forms sent the device type id in product_name; a whole number there is still a device type id.
            if (($raw === null || $raw === '') && isset($prod['product_name']) && ctype_digit(trim((string) $prod['product_name']))) {
                $raw = trim((string) $prod['product_name']);
            }

            $product = $catalogue->resolveProduct(
                ($raw !== null && $raw !== '') ? (int) $raw : null,
                ($raw !== null && $raw !== '') ? null : ($prod['product_name'] ?? null),
            );
            if (! $product) {
                continue;
            }

            // The quantity is no longer typed: an existing line keeps what it had, a new one starts at 0.
            $syncData[$product->id] = [
                'price' => $prod['price'] ?? 0,
                'qty'   => (int) ($existing[$product->id] ?? 0),
            ];
        }

        $catalogue->sync($supplier, $syncData);
    }
}
