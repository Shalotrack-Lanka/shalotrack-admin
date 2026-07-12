<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin - Add Supplier</title>

    @vite(['resources/css/app.css','resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body x-data="{ sidebarOpen: false }">

<div class="flex h-screen overflow-hidden">

    @include('partials.sidebars.admin')

    <div class="flex-1 flex flex-col overflow-y-auto">
        @include('partials.header')

        <main class="p-4 md:p-6 flex-1 space-y-6">

            @if(session('success'))
                <div class="p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-xs font-bold">
                    {{ session('success') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg shadow-xs">
                    <ul class="list-disc pl-5 space-y-1 text-[11px] font-semibold">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- ===================== 1. ADD SUPPLIER FORM ===================== --}}
            <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">
                    Add Supplier Form
                </div>
                <form method="POST" action="{{ route('admin.suppliers.store') }}"
                      class="p-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs font-semibold text-gray-700">
                    @csrf
                    <div>
                        <label class="block mb-1">Supplier Name</label>
                        <input type="text" name="supplier_name" required class="w-full rounded-lg border-gray-300 h-9 shadow-sm">
                    </div>
                    <div>
                        <label class="block mb-1">Phone Number</label>
                        <input type="text" name="phone_number" class="w-full rounded-lg border-gray-300 h-9 shadow-sm">
                    </div>
                    <div>
                        <label class="block mb-1">Email ID</label>
                        <input type="email" name="email_id" class="w-full rounded-lg border-gray-300 h-9 shadow-sm">
                    </div>
                    <div>
                        <label class="block mb-1">Country</label>
                        <select name="country" class="w-full rounded-lg border-gray-300 h-9 shadow-sm">
                            <option value="" selected disabled>--Select--</option>
                            <option value="srilanka">Sri Lanka</option>
                        </select>
                    </div>
                    <div>
                        <label class="block mb-1">State</label>
                        <select name="state" class="w-full rounded-lg border-gray-300 h-9 shadow-sm">
                            <option value="">--Select--</option>
                        </select>
                    </div>
                    <div>
                        <label class="block mb-1">Website (if any)</label>
                        <input type="text" name="website" class="w-full rounded-lg border-gray-300 h-9 shadow-sm">
                    </div>
                    <div>
                        <label class="block mb-1">GSTIN Number</label>
                        <input type="text" name="gstin" class="w-full rounded-lg border-gray-300 h-9 shadow-sm">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block mb-1">Address</label>
                        <textarea name="address" rows="2" class="w-full rounded-lg border-gray-300 shadow-sm"></textarea>
                    </div>
                    <div class="md:col-span-2 flex gap-2 pt-1">
                        <button type="reset" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 py-2 rounded-lg font-bold shadow-sm">Reset</button>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg font-bold shadow-sm">Save Supplier</button>
                    </div>
                </form>
            </div>

            {{-- ===================== 2. ACTIVE SUPPLIERS ===================== --}}
            <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">
                    Active Suppliers
                </div>
                <div class="p-5">
                    <div class="border border-gray-200 rounded-xl overflow-x-auto text-xs font-semibold text-gray-700">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="p-3">Supplier</th>
                                    <th class="p-3">Products</th>
                                    <th class="p-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                @forelse($suppliers as $supplier)
                                    <tr class="hover:bg-gray-50 transition {{ $selectedSupplier?->id === $supplier->id ? 'bg-blue-50' : '' }}">
                                        <td class="p-3">{{ $supplier->name }}</td>
                                        <td class="p-3">{{ $supplier->products_count }} Products</td>
                                        <td class="p-3">
                                            <a href="{{ route('admin.suppliers', ['supplier_id' => $supplier->id]) }}"
                                               class="px-3 py-1 rounded-lg bg-gray-800 text-white text-[11px] font-bold hover:bg-gray-900">
                                                Edit
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="p-6 text-center text-gray-400">No active suppliers yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- ===================== 3. SELECTED SUPPLIER PRODUCTS ===================== --}}
            <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm flex items-center justify-between">
                    <span>
                        Selected Supplier Products
                        @if($selectedSupplier)
                            <span class="font-normal text-gray-400">— {{ $selectedSupplier->name }}</span>
                        @endif
                    </span>
                </div>
                <div class="p-5">
                    @if(!$selectedSupplier)
                        <p class="text-xs text-gray-400 text-center py-6">
                            Select a supplier above (click "Edit") to manage their products.
                        </p>
                    @else
                        <div class="border border-gray-200 rounded-xl overflow-x-auto text-xs font-semibold text-gray-700">
                            <table class="w-full text-left border-collapse">
                                <thead class="bg-gray-50 border-b border-gray-200">
                                    <tr>
                                        <th class="p-3">Product</th>
                                        <th class="p-3">Price</th>
                                        <th class="p-3">Discount</th>
                                        <th class="p-3"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white">
                                    @forelse($selectedProducts as $product)
                                        <tr class="hover:bg-gray-50 transition">
                                            <td class="p-3">{{ $product->product_name }}</td>
                                            <td class="p-3">{{ number_format($product->pivot->price, 2) }}</td>
                                            <td class="p-3">{{ number_format($product->pivot->discount, 2) }}</td>
                                            <td class="p-3">
                                                <form action="{{ route('admin.suppliers.detach-product', [$selectedSupplier->id, $product->id]) }}"
                                                      method="POST"
                                                      onsubmit="return confirm('Remove {{ $product->product_name }} from {{ $selectedSupplier->name }}?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit"
                                                            class="px-3 py-1 rounded-lg bg-red-50 text-red-700 border border-red-200 hover:bg-red-100 text-[11px] font-bold">
                                                        Remove
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="p-6 text-center text-gray-400">No products added for this supplier yet.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            {{-- ===================== 4. ALL PRODUCTS ===================== --}}
            <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">
                    All Products
                </div>
                <div class="p-5">
                    @if(!$selectedSupplier)
                        <p class="text-xs text-gray-400 text-center py-6">
                            Select a supplier above to add products to them.
                        </p>
                    @else
                        <div class="border border-gray-200 rounded-xl overflow-x-auto text-xs font-semibold text-gray-700">
                            <table class="w-full text-left border-collapse">
                                <thead class="bg-gray-50 border-b border-gray-200">
                                    <tr>
                                        <th class="p-3">Product</th>
                                        <th class="p-3">Price</th>
                                        <th class="p-3">Discount</th>
                                        <th class="p-3"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white">
                                    @forelse($availableProducts as $product)
                                        @php($formId = 'attach-form-'.$product->id)
                                        <tr class="hover:bg-gray-50 transition">
                                            <td class="p-3">{{ $product->product_name }}</td>
                                            <td class="p-3">
                                                <form id="{{ $formId }}"
                                                      action="{{ route('admin.suppliers.attach-product', $selectedSupplier->id) }}"
                                                      method="POST">
                                                    @csrf
                                                    <input type="hidden" name="product_id" value="{{ $product->id }}">
                                                    <input type="number" step="0.01" min="0" name="price" required
                                                           placeholder="Price"
                                                           class="w-24 rounded-lg border-gray-300 text-[11px] shadow-sm">
                                                </form>
                                            </td>
                                            <td class="p-3">
                                                <input type="number" step="0.01" min="0" name="discount" form="{{ $formId }}"
                                                       placeholder="Discount"
                                                       class="w-24 rounded-lg border-gray-300 text-[11px] shadow-sm">
                                            </td>
                                            <td class="p-3">
                                                <button type="submit" form="{{ $formId }}"
                                                        class="px-3 py-1 rounded-lg bg-blue-600 text-white text-[11px] font-bold hover:bg-blue-700">
                                                    Add
                                                </button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="p-6 text-center text-gray-400">All products are already added for this supplier.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

        </main>
    </div>
</div>

</body>
</html>