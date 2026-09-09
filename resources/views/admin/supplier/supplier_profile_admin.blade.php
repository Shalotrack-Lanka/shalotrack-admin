@extends('layouts.admin')

@section('title', $supplier->name . ' — Supplier Profile')

@section('content')

<div class="space-y-6" x-data="{ editOpen: false }">

    {{-- ── Flash messages ──────────────────────────────────────────────────── --}}
    @if(session('success'))
        <div class="bg-emerald-50 border-l-4 border-emerald-500 text-emerald-700 text-sm px-4 py-3 rounded shadow-sm">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="bg-red-50 border-l-4 border-red-500 text-red-700 text-sm px-4 py-3 rounded shadow-sm">
            <p class="font-bold mb-1">Fix these errors before saving:</p>
            <ul class="list-disc list-inside space-y-0.5 ml-4">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ── Header card ─────────────────────────────────────────────────────── --}}
    <div class="bg-white p-6 border border-gray-100 rounded-2xl shadow-sm">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">

            <div class="flex items-center gap-4">
                {{-- Avatar initials --}}
                <div class="w-14 h-14 rounded-full bg-blue-700 text-white flex items-center justify-center text-xl font-bold flex-shrink-0">
                    {{ strtoupper(substr($supplier->name, 0, 1)) }}
                </div>
                <div>
                    <h1 class="text-xl font-bold text-gray-800">{{ $supplier->name }}</h1>
                    <p class="text-xs text-gray-500 mt-0.5">
                        {{ $supplier->country ?? 'Country not set' }}
                        @if($supplier->website)
                            &middot;
                            <a href="{{ $supplier->website }}" target="_blank" rel="noopener noreferrer"
                               class="text-blue-600 hover:underline">{{ $supplier->website }}</a>
                        @endif
                    </p>
                </div>

                {{-- Status badge --}}
                @if($supplier->status === 'Active')
                    <span class="px-2.5 py-1 rounded-full bg-green-50 text-green-700 border border-green-200 text-xs font-bold">Active</span>
                @else
                    <span class="px-2.5 py-1 rounded-full bg-gray-100 text-gray-500 border border-gray-200 text-xs font-bold">Inactive</span>
                @endif
            </div>

            <div class="flex gap-2 flex-wrap">
                {{-- Toggle active / inactive --}}
                <form action="{{ route('admin.suppliers.toggle-status', $supplier->id) }}" method="POST"
                      onsubmit="return confirm('{{ $supplier->status === 'Active' ? 'Deactivate' : 'Activate' }} this supplier?');">
                    @csrf
                    @method('PATCH')
                    <button type="submit"
                        class="px-4 py-2 rounded-lg text-sm font-semibold shadow-sm
                        {{ $supplier->status === 'Active'
                            ? 'bg-red-50 text-red-700 border border-red-200 hover:bg-red-100'
                            : 'bg-green-50 text-green-700 border border-green-200 hover:bg-green-100' }}">
                        {{ $supplier->status === 'Active' ? 'Deactivate Supplier' : 'Activate Supplier' }}
                    </button>
                </form>

                {{-- Toggle edit panel --}}
                <button @click="editOpen = !editOpen"
                    :class="editOpen ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'"
                    class="px-4 py-2 rounded-lg text-sm font-semibold border shadow-sm transition-colors">
                    <span x-text="editOpen ? 'Close Edit' : 'Edit Supplier'"></span>
                </button>

                {{-- Back to supplier management --}}
                <a href="{{ route('admin.suppliers', ['supplier_id' => $supplier->id]) }}"
                   class="px-4 py-2 rounded-lg text-sm font-semibold bg-gray-100 text-gray-600 border border-gray-200 hover:bg-gray-200">
                    ← Back
                </a>
            </div>
        </div>
    </div>

    {{-- ── Edit panel (Alpine toggle) ──────────────────────────────────────── --}}
    <div x-show="editOpen" x-transition.opacity x-cloak
         class="bg-white p-6 border border-blue-100 rounded-2xl shadow-sm">

        <h2 class="font-bold text-gray-800 text-sm mb-5 uppercase tracking-wide">Edit Supplier Details</h2>

        <form method="POST" action="{{ route('admin.suppliers.update', $supplier->id) }}" class="space-y-5">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1.5">Company Name <span class="text-red-500">*</span></label>
                    <input type="text" name="supplier_name" value="{{ old('supplier_name', $supplier->name) }}" required
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1.5">Email</label>
                    <input type="email" name="email_id" value="{{ old('email_id', $supplier->email) }}"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1.5">Phone</label>
                    <input type="text" name="phone_number" value="{{ old('phone_number', $supplier->phone_number) }}"
                           placeholder="+94 77 123 4567"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1.5">Website</label>
                    <input type="url" name="website" value="{{ old('website', $supplier->website) }}"
                           placeholder="https://example.com"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1.5">Country</label>
                    <input type="text" name="country" value="{{ old('country', $supplier->country) }}"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1.5">State / Region</label>
                    <input type="text" name="state" value="{{ old('state', $supplier->state) }}"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1.5">GSTIN Number</label>
                    <input type="text" name="gstin" value="{{ old('gstin', $supplier->gstin_number) }}"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 outline-none">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1.5">Address</label>
                    <textarea name="address" rows="2"
                              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 outline-none">{{ old('address', $supplier->address) }}</textarea>
                </div>
            </div>

            <div class="flex gap-3 pt-2 border-t border-gray-100">
                <button type="submit"
                        class="bg-blue-700 hover:bg-blue-800 text-white font-semibold px-5 py-2 rounded-lg text-sm shadow-sm transition-colors">
                    Save Changes
                </button>
                <button type="button" @click="editOpen = false"
                        class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 font-medium px-5 py-2 rounded-lg text-sm shadow-sm transition-colors">
                    Cancel
                </button>
            </div>
        </form>
    </div>

    {{-- ── Stat cards ───────────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm">
            <div class="text-xs text-gray-400 uppercase font-bold mb-1">Products Supplied</div>
            <div class="text-2xl font-bold text-gray-800">{{ $supplier->products->count() }}</div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm">
            <div class="text-xs text-gray-400 uppercase font-bold mb-1">Total Units Received</div>
            <div class="text-2xl font-bold text-gray-800">{{ number_format($totalStockReceived) }}</div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm">
            <div class="text-xs text-gray-400 uppercase font-bold mb-1">Total Invoices</div>
            <div class="text-2xl font-bold text-gray-800">{{ $totalInvoices }}</div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm">
            <div class="text-xs text-gray-400 uppercase font-bold mb-1">Total Invoice Value</div>
            <div class="text-2xl font-bold text-gray-800">
                {{ $totalInvoiceValue > 0 ? 'Rs. ' . number_format($totalInvoiceValue, 2) : '—' }}
            </div>
        </div>
    </div>

    {{-- ── Two-column detail + activity ────────────────────────────────────── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Left: Contact + Recent activity --}}
        <div class="lg:col-span-1 space-y-6">

            <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-4">Contact Details</h2>
                <div class="space-y-3 text-sm">
                    <div>
                        <div class="text-xs text-gray-400 uppercase font-bold">Email</div>
                        <div class="text-gray-700 break-all">{{ $supplier->email ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400 uppercase font-bold">Phone</div>
                        <div class="text-gray-700">{{ $supplier->phone_number ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400 uppercase font-bold">Website</div>
                        @if($supplier->website)
                            <a href="{{ $supplier->website }}" target="_blank" rel="noopener noreferrer"
                               class="text-blue-600 hover:underline text-xs break-all">{{ $supplier->website }}</a>
                        @else
                            <div class="text-gray-400">—</div>
                        @endif
                    </div>
                    <div>
                        <div class="text-xs text-gray-400 uppercase font-bold">Country</div>
                        <div class="text-gray-700">{{ $supplier->country ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400 uppercase font-bold">State / Region</div>
                        <div class="text-gray-700">{{ $supplier->state ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400 uppercase font-bold">Address</div>
                        <div class="text-gray-700">{{ $supplier->address ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400 uppercase font-bold">GSTIN</div>
                        <div class="text-gray-700 font-mono text-xs">{{ $supplier->gstin_number ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400 uppercase font-bold">Member Since</div>
                        <div class="text-gray-700">{{ $supplier->created_at->format('d M Y') }}</div>
                    </div>
                </div>
            </div>

            <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-4">Recent Stock Activity</h2>
                @forelse($recentActivity as $item)
                    <div class="text-xs text-gray-600 border-l-2 border-blue-200 pl-3 pb-3">
                        <div class="text-gray-400">{{ $item['date']->format('d M Y, h:i A') }}</div>
                        <div>{{ $item['text'] }}</div>
                    </div>
                @empty
                    <p class="text-xs text-gray-400">No stock received from this supplier yet.</p>
                @endforelse
            </div>

            {{-- Quick links --}}
            <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-3">Quick Links</h2>
                <a href="{{ route('admin.supplier-invoice', ['supplier_id' => $supplier->id]) }}"
                   class="block text-xs text-blue-600 hover:underline mb-1.5">→ Create Purchase Invoice</a>
                <a href="{{ route('admin.suppliers', ['supplier_id' => $supplier->id]) }}"
                   class="block text-xs text-blue-600 hover:underline mb-1.5">→ Supplier Management Panel</a>
                <a href="{{ route('admin.stock.manage') }}"
                   class="block text-xs text-blue-600 hover:underline">→ Manage Stock</a>
            </div>
        </div>

        {{-- Right: Products, Stock history, Invoices --}}
        <div class="lg:col-span-2 space-y-6">

            {{-- Products --}}
            <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-4">
                    Products Supplied ({{ $supplier->products->count() }})
                </h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-gray-50 text-gray-500 uppercase">
                            <tr>
                                <th class="p-2">Product</th>
                                <th class="p-2">Price</th>
                                <th class="p-2">Discount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($supplier->products as $product)
                                <tr>
                                    <td class="p-2 font-medium text-gray-800">{{ $product->product_name }}</td>
                                    <td class="p-2">Rs. {{ number_format($product->pivot->price, 2) }}</td>
                                    <td class="p-2">{{ $product->pivot->discount ?? 0 }}%</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="p-4 text-center text-gray-400">
                                        No products linked yet.
                                        <a href="{{ route('admin.suppliers', ['supplier_id' => $supplier->id]) }}"
                                           class="text-blue-600 hover:underline ml-1">Add via Supplier Management</a>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Stock received history --}}
            <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-4">
                    Stock Received History ({{ $stockHistory->count() }} entries · {{ number_format($totalStockReceived) }} total units)
                </h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-gray-50 text-gray-500 uppercase">
                            <tr>
                                <th class="p-2">Date</th>
                                <th class="p-2">Device Type</th>
                                <th class="p-2 text-right">Units</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($stockHistory as $entry)
                                <tr>
                                    <td class="p-2 whitespace-nowrap">
                                        {{ optional($entry->created_at)->format('d M Y') }}
                                    </td>
                                    <td class="p-2">{{ $entry->device_category_type ?? '—' }}</td>
                                    <td class="p-2 text-right font-bold text-gray-700">{{ $entry->stock_in }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="p-4 text-center text-gray-400">No stock received yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Invoice history --}}
            <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-4">
                    Purchase Invoices ({{ $totalInvoices }})
                </h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-gray-50 text-gray-500 uppercase">
                            <tr>
                                <th class="p-2">Invoice #</th>
                                <th class="p-2">Date</th>
                                <th class="p-2 text-right">Grand Total</th>
                                <th class="p-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($invoices as $invoice)
                                <tr>
                                    <td class="p-2 font-mono text-gray-700">{{ $invoice->invoice_number }}</td>
                                    <td class="p-2 whitespace-nowrap">{{ optional($invoice->invoice_date)->format('d M Y') }}</td>
                                    <td class="p-2 text-right font-bold text-gray-700">
                                        Rs. {{ number_format($invoice->grand_total, 2) }}
                                    </td>
                                    <td class="p-2">
                                        <a href="{{ route('admin.supplier-invoice.download', $invoice->id) }}"
                                           class="text-blue-600 hover:underline text-xs">Download</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="p-4 text-center text-gray-400">
                                        No invoices yet.
                                        <a href="{{ route('admin.supplier-invoice', ['supplier_id' => $supplier->id]) }}"
                                           class="text-blue-600 hover:underline ml-1">Create one</a>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

</div>

@endsection