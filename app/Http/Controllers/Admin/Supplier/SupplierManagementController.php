<?php

namespace App\Http\Controllers\Admin\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\DeviceType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierManagementController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status', '');

        // 1. DeviceTypes සියල්ල ලබා ගැනීම (Dropdown එකේ V5 Basic, V10 Plus ලෙස පෙන්වීමට)
        $deviceTypes = DeviceType::orderBy('device_category')->orderBy('model')->get();

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
            'allProducts',
            'deviceTypes'
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
                'qty'             => $p->pivot->qty,
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
            'device_type_id' => 'nullable|integer|exists:device_types,id',
            'product_name'   => 'required_without:device_type_id|nullable|string|max:255',
            'price'          => 'required|numeric|min:0',
            'qty'            => 'required|integer|min:0',
        ]);

        if (!empty($validated['device_type_id'])) {
            $deviceType = DeviceType::findOrFail($validated['device_type_id']);
            $productName = trim("{$deviceType->device_category} {$deviceType->model}");

            $product = Product::firstOrCreate(
                ['device_type_id' => $deviceType->id],
                ['product_name' => $productName]
            );
        } else {
            $product = Product::firstOrCreate([
                'product_name' => trim($validated['product_name']),
            ]);
        }

        $supplier->products()->syncWithoutDetaching([
            $product->id => [
                'price' => $validated['price'],
                'qty'   => $validated['qty'],
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
        $supplier->products()->detach($productId);

        return redirect()
            ->route('admin.suppliers')
            ->with('success', 'Product removed from ' . $supplier->name . '.');
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

    // 💡 UPDATE: DeviceType ID එක ලබාගෙන Product එක සාදා අදාළ device_type_id එක සම්බන්ධ කිරීම
    private function syncProducts(Supplier $supplier, ?array $products)
    {
        if (is_array($products)) {
            $syncData = [];
            foreach ($products as $prod) {
                // Dropdown එකෙන් DeviceType ID හෝ Product Name පැමිණේ නම්
                $deviceTypeId = $prod['device_type_id'] ?? $prod['product_name'] ?? null;

                if (!empty($deviceTypeId)) {

                    // 1. ඉදිරියෙන් ආවේ DeviceType ID එකක්දැයි බලයි
                    $deviceType = DeviceType::find($deviceTypeId);

                    if ($deviceType) {
                        $productName = trim("{$deviceType->device_category} {$deviceType->model}");

                        // Products table එකේ සාදයි හෝ සොයා ගනියි (device_type_id එකත් සමග)
                        $product = Product::firstOrCreate(
                            ['device_type_id' => $deviceType->id],
                            ['product_name' => $productName]
                        );
                    } else {
                        // හදිසියේවත් කෙළින්ම Product Name එකක් ආවොත් (Fallback)
                        $productName = trim($deviceTypeId);
                        $product = Product::firstOrCreate(
                            ['product_name' => $productName]
                        );
                    }

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