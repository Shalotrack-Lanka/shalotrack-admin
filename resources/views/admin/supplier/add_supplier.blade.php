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

            {{-- ===================== SEARCH SUPPLIERS (TOP) ===================== --}}
            <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">
                    Search Suppliers
                </div>
                <form method="GET" action="{{ route('admin.suppliers') }}"
                      class="p-5 flex flex-col md:flex-row gap-3 text-xs font-semibold text-gray-700">
                    <input type="text" name="search" value="{{ $search ?? '' }}"
                           placeholder="Search by name, email, phone, or country..."
                           class="flex-1 rounded-lg border-gray-300 h-9 shadow-sm">
                    <select name="status" class="w-full md:w-40 rounded-lg border-gray-300 h-9 shadow-sm">
                        <option value="" {{ ($status ?? '') === '' ? 'selected' : '' }}>All Statuses</option>
                        <option value="Active" {{ ($status ?? '') === 'Active' ? 'selected' : '' }}>Active</option>
                        <option value="Inactive" {{ ($status ?? '') === 'Inactive' ? 'selected' : '' }}>Inactive</option>
                    </select>
                    <button type="submit"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-5 h-9 rounded-lg font-bold shadow-sm">
                        Search
                    </button>
                    @if(($search ?? '') !== '' || ($status ?? '') !== '')
                        <a href="{{ route('admin.suppliers') }}"
                           class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 h-9 flex items-center rounded-lg font-bold shadow-sm">
                            Clear
                        </a>
                    @endif
                </form>
            </div>

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
                        <label class="block mb-1">Tax / VAT Reg. No. <span class="text-gray-400 font-normal">(GSTIN, if applicable)</span></label>
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

            {{-- ===================== 2. ALL SUPPLIERS ===================== --}}
            <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">
                    All Suppliers
                </div>
                <div class="p-5">
                    <div class="border border-gray-200 rounded-xl overflow-x-auto text-xs font-semibold text-gray-700">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="p-3">Supplier Name</th>
                                    <th class="p-3">Country</th>
                                    <th class="p-3">Phone Number</th>
                                    <th class="p-3">Email</th>
                                    <th class="p-3">Status</th>
                                    <th class="p-3">Products</th>
                                    <th class="p-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                @forelse($suppliers as $supplier)
                                    <tr class="hover:bg-gray-50 transition {{ $selectedSupplier?->id === $supplier->id ? 'bg-blue-50' : '' }}">
                                        <td class="p-3">{{ $supplier->name }}</td>
                                        <td class="p-3">{{ ucfirst($supplier->country ?? '-') }}</td>
                                        <td class="p-3">{{ $supplier->phone_number ?? '-' }}</td>
                                        <td class="p-3">{{ $supplier->email ?? '-' }}</td>
                                        <td class="p-3">
                                            @if($supplier->status === 'Active')
                                                <span class="px-2 py-1 rounded-full bg-green-50 text-green-700 border border-green-200 text-[10px] font-bold">Active</span>
                                            @else
                                                <span class="px-2 py-1 rounded-full bg-gray-100 text-gray-500 border border-gray-200 text-[10px] font-bold">Inactive</span>
                                            @endif
                                        </td>
                                        <td class="p-3">{{ $supplier->products_count }}</td>
                                        <td class="p-3">
                                            <div class="flex flex-wrap gap-1.5">
                                                <a href="{{ route('admin.suppliers', ['supplier_id' => $supplier->id]) }}"
                                                   class="px-3 py-1 rounded-lg bg-gray-800 text-white text-[11px] font-bold hover:bg-gray-900">
                                                    Edit / View
                                                </a>
                                                <form action="{{ route('admin.suppliers.toggle-status', $supplier->id) }}" method="POST"
                                                      onsubmit="return confirm('{{ $supplier->status === 'Active' ? 'Deactivate' : 'Activate' }} {{ $supplier->name }}?');">
                                                    @csrf
                                                    @method('PATCH')
                                                    @if($supplier->status === 'Active')
                                                        <button type="submit" class="px-3 py-1 rounded-lg bg-red-50 text-red-700 border border-red-200 hover:bg-red-100 text-[11px] font-bold">
                                                            Deactivate
                                                        </button>
                                                    @else
                                                        <button type="submit" class="px-3 py-1 rounded-lg bg-green-50 text-green-700 border border-green-200 hover:bg-green-100 text-[11px] font-bold">
                                                            Activate
                                                        </button>
                                                    @endif
                                                </form>
                                                <a href="{{ route('admin.supplier-invoice', ['supplier_id' => $supplier->id]) }}"
                                                   class="px-3 py-1 rounded-lg bg-blue-50 text-blue-700 border border-blue-200 hover:bg-blue-100 text-[11px] font-bold">
                                                    Invoices
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="p-6 text-center text-gray-400">
                                        @if(($search ?? '') !== '' || ($status ?? '') !== '')
                                            No suppliers match your search.
                                        @else
                                            No suppliers yet.
                                        @endif
                                    </td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            @if($selectedSupplier)

                {{-- ===================== 3. SUPPLIER OVERVIEW & EDIT ===================== --}}
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm flex items-center justify-between">
                        <span>Supplier Details — {{ $selectedSupplier->name }}</span>
                        @if($selectedSupplier->status === 'Active')
                            <span class="px-2 py-1 rounded-full bg-green-50 text-green-700 border border-green-200 text-[10px] font-bold">Active</span>
                        @else
                            <span class="px-2 py-1 rounded-full bg-gray-100 text-gray-500 border border-gray-200 text-[10px] font-bold">Inactive</span>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('admin.suppliers.update', $selectedSupplier->id) }}"
                          class="p-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs font-semibold text-gray-700">
                        @csrf
                        @method('PUT')
                        <div>
                            <label class="block mb-1">Supplier Name</label>
                            <input type="text" name="supplier_name" required
                                   value="{{ old('supplier_name', $selectedSupplier->name) }}"
                                   class="w-full rounded-lg border-gray-300 h-9 shadow-sm">
                        </div>
                        <div>
                            <label class="block mb-1">Phone Number</label>
                            <input type="text" name="phone_number"
                                   value="{{ old('phone_number', $selectedSupplier->phone_number) }}"
                                   class="w-full rounded-lg border-gray-300 h-9 shadow-sm">
                        </div>
                        <div>
                            <label class="block mb-1">Email ID</label>
                            <input type="email" name="email_id"
                                   value="{{ old('email_id', $selectedSupplier->email) }}"
                                   class="w-full rounded-lg border-gray-300 h-9 shadow-sm">
                        </div>
                        <div>
                            <label class="block mb-1">Country</label>
                            <select name="country" class="w-full rounded-lg border-gray-300 h-9 shadow-sm">
                                <option value="" {{ !$selectedSupplier->country ? 'selected' : '' }}>--Select--</option>
                                <option value="srilanka" {{ $selectedSupplier->country === 'srilanka' ? 'selected' : '' }}>Sri Lanka</option>
                            </select>
                        </div>
                        <div>
                            <label class="block mb-1">State</label>
                            <input type="text" name="state"
                                   value="{{ old('state', $selectedSupplier->state) }}"
                                   class="w-full rounded-lg border-gray-300 h-9 shadow-sm">
                        </div>
                        <div>
                            <label class="block mb-1">Website (if any)</label>
                            <input type="text" name="website"
                                   value="{{ old('website', $selectedSupplier->website) }}"
                                   class="w-full rounded-lg border-gray-300 h-9 shadow-sm">
                        </div>
                        <div>
                            <label class="block mb-1">Tax / VAT Reg. No. <span class="text-gray-400 font-normal">(GSTIN, if applicable)</span></label>
                            <input type="text" name="gstin"
                                   value="{{ old('gstin', $selectedSupplier->gstin_number) }}"
                                   class="w-full rounded-lg border-gray-300 h-9 shadow-sm">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block mb-1">Address</label>
                            <textarea name="address" rows="2"
                                      class="w-full rounded-lg border-gray-300 shadow-sm">{{ old('address', $selectedSupplier->address) }}</textarea>
                        </div>
                        <div class="md:col-span-2 flex gap-2 pt-1">
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg font-bold shadow-sm">
                                Save Changes
                            </button>
                            <a href="{{ route('admin.suppliers') }}" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 py-2 rounded-lg font-bold shadow-sm flex items-center">
                                Close
                            </a>
                        </div>
                    </form>
                </div>

                {{-- ===================== 4. SELECTED SUPPLIER PRODUCTS ===================== --}}
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">
                        Selected Supplier Products
                        <span class="font-normal text-gray-400">— {{ $selectedSupplier->name }}</span>
                    </div>
                    <div class="p-5">
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
                    </div>
                </div>

                {{-- ===================== 5. SUPPLY / STOCK HISTORY ===================== --}}
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">
                        Supply / Stock History
                        <span class="font-normal text-gray-400">— {{ $selectedSupplier->name }}</span>
                    </div>
                    <div class="p-5">
                        <p class="text-[11px] text-gray-400 mb-3">
                            Unit price and total amount aren't tracked in the stock table yet — showing what's actually recorded.
                        </p>
                        <div class="border border-gray-200 rounded-xl overflow-x-auto text-xs font-semibold text-gray-700">
                            <table class="w-full text-left border-collapse">
                                <thead class="bg-gray-50 border-b border-gray-200">
                                    <tr>
                                        <th class="p-3">Date</th>
                                        <th class="p-3">Device Type</th>
                                        <th class="p-3">Quantity Received</th>
                                        <th class="p-3">Unit Price</th>
                                        <th class="p-3">Total Amount</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white">
                                    @forelse($stockHistory as $stock)
                                        <tr class="hover:bg-gray-50 transition">
                                            <td class="p-3">{{ optional($stock->created_at)->format('d M Y') }}</td>
                                            <td class="p-3">
                                                {{ $stock->deviceType->device_category ?? '-' }}
                                                @if($stock->deviceType?->model)
                                                    <span class="text-gray-400">— {{ $stock->deviceType->model }}</span>
                                                @endif
                                            </td>
                                            <td class="p-3">{{ $stock->stock_in }}</td>
                                            <td class="p-3 text-gray-400">Not tracked</td>
                                            <td class="p-3 text-gray-400">Not tracked</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="p-6 text-center text-gray-400">No stock has been received from this supplier yet.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                {{-- ===================== 6. ALL PRODUCTS ===================== --}}
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">
                        All Products
                    </div>
                    <div class="p-5">
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
                    </div>
                </div>

            @endif

        </main>
    </div>
</div>

</body>
</html>