<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin - Supplier Management</title>
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

            {{-- COMMON DATALIST FOR PRODUCTS --}}
            <datalist id="existing-products">
                @foreach($allProducts as $p)
                    <option value="{{ $p->product_name }}"></option>
                @endforeach
            </datalist>

            {{-- ===================== SEARCH SUPPLIERS ===================== --}}
            <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">
                    Search Suppliers
                </div>
                <form method="GET" action="{{ route('admin.suppliers') }}" class="p-5 flex flex-col md:flex-row gap-3 text-xs font-semibold text-gray-700">
                    <input type="text" name="search" value="{{ $search ?? '' }}" placeholder="Search by name, email, phone..." class="flex-1 rounded-lg border-gray-300 h-9 shadow-sm">
                    <select name="status" class="w-full md:w-40 rounded-lg border-gray-300 h-9 shadow-sm">
                        <option value="">All Statuses</option>
                        <option value="Active" {{ ($status ?? '') === 'Active' ? 'selected' : '' }}>Active</option>
                        <option value="Inactive" {{ ($status ?? '') === 'Inactive' ? 'selected' : '' }}>Inactive</option>
                    </select>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 h-9 rounded-lg font-bold shadow-sm">Search</button>
                    @if(($search ?? '') !== '' || ($status ?? '') !== '')
                        <a href="{{ route('admin.suppliers') }}" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 h-9 flex items-center rounded-lg font-bold shadow-sm">Clear</a>
                    @endif
                </form>
            </div>

              {{-- ===================== SEARCH RESULTS / ALL SUPPLIERS (MOVED DOWN) ===================== --}}
            @php $displaySuppliers = ($search !== '' || $status !== '') ? $searchResults : $allSuppliers; @endphp
            
            <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden mb-10">
                <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">
                    {{ ($search !== '' || $status !== '') ? 'Search Results' : 'All Suppliers' }}
                    <span class="text-gray-500 font-normal ml-2">({{ $displaySuppliers->count() }} result{{ $displaySuppliers->count() == 1 ? '' : 's' }})</span>
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
                                @forelse($displaySuppliers as $supplier)
                                    <tr class="hover:bg-gray-50 transition" x-data="{ editModalOpen: false }">
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
                                                <a href="{{ route('admin.supplier.profile.show', $supplier->id) }}" class="px-3 py-1 rounded-lg bg-cyan-600 text-white text-[11px] font-bold hover:bg-cyan-700">View Profile</a>
                                                
                                                <button type="button" @click="editModalOpen = true" class="px-3 py-1 rounded-lg bg-gray-800 text-white text-[11px] font-bold hover:bg-gray-900 cursor-pointer">Edit</button>

                                                <form action="{{ route('admin.suppliers.toggle-status', $supplier->id) }}" method="POST" onsubmit="return confirm('{{ $supplier->status === 'Active' ? 'Deactivate' : 'Activate' }} {{ $supplier->name }}?');">
                                                    @csrf
                                                    @method('PATCH')
                                                    @if($supplier->status === 'Active')
                                                        <button type="submit" class="px-3 py-1 rounded-lg bg-red-50 text-red-700 border border-red-200 hover:bg-red-100 text-[11px] font-bold">Deactivate</button>
                                                    @else
                                                        <button type="submit" class="px-3 py-1 rounded-lg bg-green-50 text-green-700 border border-green-200 hover:bg-green-100 text-[11px] font-bold">Activate</button>
                                                    @endif
                                                </form>

                                                <a href="{{ route('admin.supplier-invoice', ['supplier_id' => $supplier->id]) }}" class="px-3 py-1 rounded-lg bg-blue-50 text-blue-700 border border-blue-200 hover:bg-blue-100 text-[11px] font-bold">Invoices</a>
                                            </div>

                                            {{-- ===================== EDIT SUPPLIER MODAL ===================== --}}
                                            <div x-show="editModalOpen" x-cloak style="display: none;" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
                                                <div @click.away="editModalOpen = false" class="bg-white rounded-2xl shadow-xl w-full max-w-4xl mx-4 overflow-hidden text-left">
                                                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 flex justify-between items-center">
                                                        <h3 class="font-bold text-gray-800 text-sm">Edit Supplier — {{ $supplier->name }}</h3>
                                                        <button type="button" @click="editModalOpen = false" class="text-gray-400 hover:text-gray-600 focus:outline-none">
                                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                        </button>
                                                    </div>
                                                    <div class="p-5 max-h-[80vh] overflow-y-auto">
                                                        <form method="POST" action="{{ route('admin.suppliers.update', $supplier->id) }}" class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs font-semibold text-gray-700">
                                                            @csrf
                                                            @method('PUT')
                                                            <div><label class="block mb-1">Supplier Name</label><input type="text" name="supplier_name" required value="{{ $supplier->name }}" class="w-full rounded-lg border-gray-300 h-9 shadow-sm"></div>
                                                            <div><label class="block mb-1">Phone Number</label><input type="text" name="phone_number" value="{{ $supplier->phone_number }}" class="w-full rounded-lg border-gray-300 h-9 shadow-sm"></div>
                                                            <div><label class="block mb-1">Email ID</label><input type="email" name="email_id" value="{{ $supplier->email }}" class="w-full rounded-lg border-gray-300 h-9 shadow-sm"></div>
                                                            <div>
                                                                <label class="block mb-1">Country</label>
                                                                <select name="country" class="w-full rounded-lg border-gray-300 h-9 shadow-sm">
                                                                    <option value="" {{ !$supplier->country ? 'selected' : '' }}>--Select--</option>
                                                                    <option value="srilanka" {{ $supplier->country === 'srilanka' ? 'selected' : '' }}>Sri Lanka</option>
                                                                </select>
                                                            </div>
                                                            <div><label class="block mb-1">State</label><input type="text" name="state" value="{{ $supplier->state }}" class="w-full rounded-lg border-gray-300 h-9 shadow-sm"></div>
                                                            <div><label class="block mb-1">Website (if any)</label><input type="text" name="website" value="{{ $supplier->website }}" class="w-full rounded-lg border-gray-300 h-9 shadow-sm"></div>
                                                            <div class="md:col-span-2"><label class="block mb-1">Tax / VAT Reg. No.</label><input type="text" name="gstin" value="{{ $supplier->gstin_number }}" class="w-full rounded-lg border-gray-300 h-9 shadow-sm"></div>
                                                            <div class="md:col-span-2"><label class="block mb-1">Address</label><textarea name="address" rows="2" class="w-full rounded-lg border-gray-300 shadow-sm">{{ $supplier->address }}</textarea></div>
                                                            
                                                            {{-- DYNAMIC PRODUCTS IN EDIT FORM --}}
                                                            @php
                                                                $editProducts = $supplier->products->map(function($p) {
                                                                    return [
                                                                        'id' => rand(100000, 999999), 
                                                                        'product_name' => $p->product_name,
                                                                        'price' => $p->pivot->price,
                                                                        'qty' => $p->pivot->qty ?? 0
                                                                    ];
                                                                })->values()->toArray();
                                                            @endphp

                                                            <div class="md:col-span-2 bg-gray-50 p-4 rounded-xl border border-gray-200 mt-2" 
                                                                 x-data="{
                                                                     products: {{ json_encode($editProducts) }},
                                                                     init() { if (this.products.length === 0) this.addProduct(); },
                                                                     addProduct() { this.products.push({ id: Date.now(), product_name: '', price: '', qty: '' }); },
                                                                     removeProduct(index) { this.products.splice(index, 1); }
                                                                 }">
                                                                <h4 class="font-bold mb-3 text-gray-700">Assign Products</h4>
                                                                <template x-for="(item, index) in products" :key="item.id">
                                                                    <div class="flex flex-wrap md:flex-nowrap gap-2 items-end mb-3">
                                                                        <div class="flex-1 w-full">
                                                                            <label class="block mb-1 text-[10px]">Product Name</label>
                                                                            <input type="text" list="existing-products" required x-model="item.product_name" :name="`products[${index}][product_name]`" class="w-full rounded-lg border-gray-300 h-9 shadow-sm text-xs" placeholder="Select or type new product...">
                                                                        </div>
                                                                        <div class="w-1/4 md:w-28">
                                                                            <label class="block mb-1 text-[10px]">Unit Price</label>
                                                                            <input type="number" step="0.01" min="0" x-model="item.price" :name="`products[${index}][price]`" class="w-full rounded-lg border-gray-300 h-9 shadow-sm text-xs" placeholder="0.00">
                                                                        </div>
                                                                        <div class="w-1/4 md:w-24">
                                                                            <label class="block mb-1 text-[10px]">Qty</label>
                                                                            <input type="number" step="1" min="0" x-model="item.qty" :name="`products[${index}][qty]`" class="w-full rounded-lg border-gray-300 h-9 shadow-sm text-xs" placeholder="0">
                                                                        </div>
                                                                        <div class="w-1/4 md:w-32">
                                                                            <label class="block mb-1 text-[10px] text-gray-500">Total Price</label>
                                                                            <div class="h-9 px-3 flex items-center bg-gray-200 border border-gray-300 rounded-lg text-xs font-bold text-gray-700 overflow-hidden">
                                                                                Rs. <span x-text="( (item.price || 0) * (item.qty || 0) ).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})" class="ml-1"></span>
                                                                            </div>
                                                                        </div>
                                                                        <button type="button" @click="removeProduct(index)" class="bg-red-50 text-red-600 h-9 px-3 rounded-lg border border-red-200 w-auto font-bold hover:bg-red-100">X</button>
                                                                    </div>
                                                                </template>
                                                                <button type="button" @click="addProduct" class="text-xs text-blue-600 font-bold mt-1">+ Add Another Product</button>
                                                            </div>

                                                            <div class="md:col-span-2 flex justify-end gap-2 pt-2 border-t border-gray-100">
                                                                <button type="button" @click="editModalOpen = false" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 py-2 rounded-lg font-bold shadow-sm cursor-pointer">Cancel</button>
                                                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg font-bold shadow-sm">Save Changes</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="p-6 text-center text-gray-400">No suppliers found.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>


            {{-- ===================== ADD SUPPLIER FORM (MOVED UP) ===================== --}}
            <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">
                    Add Supplier Form
                </div>
                <form method="POST" action="{{ route('admin.suppliers.store') }}" class="p-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs font-semibold text-gray-700">
                    @csrf
                    <div><label class="block mb-1">Supplier Name</label><input type="text" name="supplier_name" required class="w-full rounded-lg border-gray-300 h-9 shadow-sm"></div>
                    <div><label class="block mb-1">Phone Number</label><input type="text" name="phone_number" class="w-full rounded-lg border-gray-300 h-9 shadow-sm"></div>
                    <div><label class="block mb-1">Email ID</label><input type="email" name="email_id" class="w-full rounded-lg border-gray-300 h-9 shadow-sm"></div>
                    <div>
                        <label class="block mb-1">Country</label>
                        <select name="country" class="w-full rounded-lg border-gray-300 h-9 shadow-sm">
                            <option value="" selected disabled>--Select--</option>
                            <option value="srilanka">Sri Lanka</option>
                        </select>
                    </div>
                    <div><label class="block mb-1">State</label><input type="text" name="state" class="w-full rounded-lg border-gray-300 h-9 shadow-sm"></div>
                    <div><label class="block mb-1">Website (if any)</label><input type="text" name="website" class="w-full rounded-lg border-gray-300 h-9 shadow-sm"></div>
                    <div class="md:col-span-2"><label class="block mb-1">Tax / VAT Reg. No.</label><input type="text" name="gstin" class="w-full rounded-lg border-gray-300 h-9 shadow-sm"></div>
                    <div class="md:col-span-2"><label class="block mb-1">Address</label><textarea name="address" rows="2" class="w-full rounded-lg border-gray-300 shadow-sm"></textarea></div>

                    {{-- DYNAMIC PRODUCTS IN ADD FORM --}}
                    <div class="md:col-span-2 bg-gray-50 p-4 rounded-xl border border-gray-200 mt-2" 
                         x-data="{
                             products: [{ id: Date.now(), product_name: '', price: '', qty: '' }],
                             addProduct() { this.products.push({ id: Date.now(), product_name: '', price: '', qty: '' }); },
                             removeProduct(index) { if(this.products.length > 1) { this.products.splice(index, 1); } }
                         }">
                        <h4 class="font-bold mb-3 text-gray-700">Assign Products</h4>
                        <template x-for="(item, index) in products" :key="item.id">
                            <div class="flex flex-wrap md:flex-nowrap gap-2 items-end mb-3">
                                <div class="flex-1 w-full">
                                    <label class="block mb-1 text-[10px]">Product Name</label>
                                    <input type="text" list="existing-products" required x-model="item.product_name" :name="`products[${index}][product_name]`" class="w-full rounded-lg border-gray-300 h-9 shadow-sm text-xs" placeholder="Select or type new product...">
                                </div>
                                <div class="w-1/4 md:w-28">
                                    <label class="block mb-1 text-[10px]">Unit Price</label>
                                    <input type="number" step="0.01" min="0" x-model="item.price" :name="`products[${index}][price]`" class="w-full rounded-lg border-gray-300 h-9 shadow-sm text-xs" placeholder="0.00">
                                </div>
                                <div class="w-1/4 md:w-24">
                                    <label class="block mb-1 text-[10px]">Qty</label>
                                    <input type="number" step="1" min="0" x-model="item.qty" :name="`products[${index}][qty]`" class="w-full rounded-lg border-gray-300 h-9 shadow-sm text-xs" placeholder="0">
                                </div>
                                <div class="w-1/4 md:w-32">
                                    <label class="block mb-1 text-[10px] text-gray-500">Total Price</label>
                                    <div class="h-9 px-3 flex items-center bg-gray-200 border border-gray-300 rounded-lg text-xs font-bold text-gray-700 overflow-hidden">
                                        Rs. <span x-text="( (item.price || 0) * (item.qty || 0) ).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})" class="ml-1"></span>
                                    </div>
                                </div>
                                <button type="button" @click="removeProduct(index)" class="bg-red-50 text-red-600 h-9 px-3 rounded-lg border border-red-200 w-auto font-bold hover:bg-red-100">X</button>
                            </div>
                        </template>
                        <button type="button" @click="addProduct" class="text-xs text-blue-600 font-bold mt-1">+ Add Another Product</button>
                    </div>

                    <div class="md:col-span-2 flex gap-2 pt-2 border-t border-gray-100">
                        <button type="reset" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 py-2 rounded-lg font-bold shadow-sm">Reset</button>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg font-bold shadow-sm">Save Supplier</button>
                    </div>
                </form>
            </div>

          
        </main>
    </div>
</div>
</body>
</html>