@extends('layouts.admin')

@section('content')
@php
    $isEdit = ! empty($editing);
    $typeNames = \App\Models\DeviceType::query()->get()->mapWithKeys(fn ($t) => [$t->id => trim($t->device_category . ' ' . $t->model)]);
@endphp
<div x-data="invoiceSystem(@js(['supplierId' => (string) ($supplierId ?? ''), 'editing' => $editing, 'dataUrl' => url('admin/supplier/get-supplier-data')]))" class="max-w-6xl mx-auto space-y-6">

    <div class="mb-4">
        @if($isEdit)
            <h2 class="text-2xl font-black text-slate-800">Edit Purchase Order {{ $editing['number'] }}</h2>
            <p class="text-slate-500 text-sm">An order can be changed until goods are received against it. Every change is kept in the audit log.</p>
        @else
            <h2 class="text-2xl font-black text-slate-800">Purchase Order / Supplier Invoice</h2>
            <p class="text-slate-500 text-sm">Order from a supplier's own product list at the agreed price. A purchase order does not change stock: stock goes up when the delivery is scanned in (Supplier Management &rarr; Receive delivery) and added on Manage Stock.</p>
        @endif
    </div>

    {{-- Success/Error Messages --}}
    @if(session('success'))
        <div class="p-4 bg-emerald-50 text-emerald-700 rounded-xl font-bold border border-emerald-200">
            ✅ {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="p-4 bg-red-50 text-red-700 rounded-xl font-bold border border-red-200">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="p-4 bg-red-50 text-red-700 rounded-xl font-bold border border-red-200">
            ⚠️ Please fix these and try again:
            <ul class="list-disc ml-6 mt-1 font-normal text-sm">
                @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form action="{{ $isEdit ? route('admin.supplier-invoice.update', $editing['id']) : route('admin.supplier-invoice.store') }}" method="POST" @submit="validateForm">
        @csrf
        @if($isEdit) @method('PUT') @endif

        {{-- 1. Select Supplier Section --}}
        <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 mb-6">
            <label class="block text-sm font-bold text-slate-700 mb-2">Supplier :</label>
            <select name="supplier_id"
                    x-model="supplierId"
                    @change="fetchSupplierData"
                    required
                    @disabled($isEdit)
                    class="w-full border-slate-300 rounded-xl focus:ring-blue-500 focus:border-blue-500 text-sm p-3 bg-slate-50 disabled:opacity-70">
                <option value="">-- Click to Select Registered Supplier --</option>
                @foreach($suppliers as $sup)
                    <option value="{{ $sup->id }}">{{ $sup->name }}</option>
                @endforeach
            </select>

            <div x-show="isLoading" class="text-sm text-blue-600 mt-2 font-bold animate-pulse" style="display: none;">
                Loading supplier data...
            </div>
            <div x-show="supplierDetails && availableProducts.length === 0" x-cloak class="mt-3 p-3 rounded-lg border border-amber-200 bg-amber-50 text-amber-800 text-sm">
                This supplier has no products on its list yet. Add the products it supplies (Supplier Management &rarr; Edit &rarr; Products) before ordering.
            </div>
        </div>

        {{-- 2. Supplier Details Section --}}
        <div x-show="supplierDetails" x-cloak class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 mb-6 transition-all" style="display: none;">
            <h3 class="font-bold text-slate-800 mb-4 border-b pb-2">Supplier Information</h3>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-sm">
                <div>
                    <span class="block text-xs text-slate-400 font-bold uppercase">Supplier Name</span>
                    <span class="font-bold text-slate-800" x-text="supplierDetails?.name || '-'"></span>
                </div>
                <div>
                    <span class="block text-xs text-slate-400 font-bold uppercase">Email Address</span>
                    <span class="font-medium text-slate-700" x-text="supplierDetails?.email || '-'"></span>
                </div>
                <div>
                    <span class="block text-xs text-slate-400 font-bold uppercase">Mobile / Phone</span>
                    <span class="font-medium text-slate-700" x-text="supplierDetails?.phone_number || '-'"></span>
                </div>
                <div>
                    <span class="block text-xs text-slate-400 font-bold uppercase">Tax / VAT Reg. No.</span>
                    <span class="font-medium text-slate-700" x-text="supplierDetails?.gstin_number || '-'"></span>
                </div>
                <div class="md:col-span-4 mt-2">
                    <span class="block text-xs text-slate-400 font-bold uppercase">Physical Address</span>
                    <span class="font-medium text-slate-700" x-text="supplierDetails?.address || '-'"></span>
                </div>
            </div>
        </div>

        {{-- 3. Order Items Table Section --}}
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden mb-6">
            <div class="bg-slate-50 px-6 py-4 border-b border-slate-200 flex justify-between items-center">
                <h3 class="font-bold text-slate-800">Order Items List</h3>
                <button type="button" @click="addItem" class="bg-slate-800 hover:bg-slate-700 text-white text-xs font-bold py-2 px-4 rounded-lg shadow-sm transition cursor-pointer">
                    + Add Item Row
                </button>
            </div>

            <div class="overflow-x-auto p-4">
                <table class="w-full text-left border-collapse text-sm">
                    <thead class="text-xs uppercase text-slate-500 bg-slate-50">
                        <tr>
                            <th class="p-3 border-b w-1/4">Product (this supplier's list)</th>
                            <th class="p-3 border-b">Type</th>
                            <th class="p-3 border-b w-24">Qty</th>
                            <th class="p-3 border-b w-32">Unit Price</th>
                            <th class="p-3 border-b w-24">Disc (%)</th>
                            <th class="p-3 border-b w-24">Face Value</th>
                            <th class="p-3 border-b w-32 text-right">Net Amount</th>
                            <th class="p-3 border-b text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(item, index) in items" :key="index">
                            <tr class="hover:bg-slate-50 transition border-b border-slate-100">
                                <td class="p-2">
                                    <select x-model="item.product_id" :name="`items[${index}][product_id]`" @change="productSelected(item)" required class="w-full border-slate-300 rounded-lg text-sm p-2 focus:ring-blue-500">
                                        <option value="">-- Select Product --</option>
                                        <template x-for="prod in availableProducts" :key="prod.id">
                                            <option :value="String(prod.id)" x-text="prod.product_name" :selected="String(prod.id) === String(item.product_id)"></option>
                                        </template>
                                    </select>
                                    <p class="text-[11px] text-slate-400 mt-1" x-show="agreedPrice(item) !== null && Number(item.unit_price) !== agreedPrice(item)" x-cloak>
                                        Agreed price: Rs. <span x-text="(agreedPrice(item) || 0).toFixed(2)"></span>
                                    </p>
                                </td>
                                <td class="p-2">
                                    <select x-model="item.type" :name="`items[${index}][type]`" required class="w-full border-slate-300 rounded-lg text-sm p-2 focus:ring-blue-500">
                                        <template x-for="t in typeOptions(item)" :key="t">
                                            <option :value="t" x-text="t" :selected="t === item.type"></option>
                                        </template>
                                    </select>
                                </td>
                                <td class="p-2">
                                    <input type="number" x-model.number="item.order_qty" :name="`items[${index}][order_qty]`" min="1" required class="w-full border-slate-300 rounded-lg text-sm p-2 focus:ring-blue-500">
                                </td>
                                <td class="p-2">
                                    <input type="number" step="0.01" x-model.number="item.unit_price" :name="`items[${index}][unit_price]`" min="0" required class="w-full border-slate-300 rounded-lg text-sm p-2 focus:ring-blue-500">
                                </td>
                                <td class="p-2">
                                    <input type="number" step="0.01" x-model.number="item.discount" :name="`items[${index}][discount]`" min="0" max="100" class="w-full border-slate-300 rounded-lg text-sm p-2 focus:ring-blue-500">
                                </td>
                                <td class="p-2">
                                    <input type="number" step="0.01" x-model.number="item.face_value" :name="`items[${index}][face_value]`" min="0" class="w-full border-slate-300 rounded-lg text-sm p-2 focus:ring-blue-500">
                                </td>
                                <td class="p-2 text-right font-bold text-slate-800">
                                    Rs. <span x-text="calculateRowTotal(item).toFixed(2)"></span>
                                </td>
                                <td class="p-2 text-center">
                                    <button type="button" @click="removeItem(index)" class="text-red-500 hover:text-red-700 bg-red-50 p-2 rounded-lg transition font-bold cursor-pointer">
                                        ✕
                                    </button>
                                </td>
                            </tr>
                        </template>

                        <tr x-show="items.length === 0">
                            <td colspan="8" class="p-8 text-center text-slate-400 italic">
                                No items added yet. Click "+ Add Item Row" to start.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- Grand Total Section --}}
            <div class="bg-blue-50/50 p-6 border-t border-slate-200 flex justify-end items-center gap-6">
                <div class="text-right">
                    <span class="block text-xs font-bold text-slate-500 uppercase tracking-widest">Grand Total Summary</span>
                    <span class="text-3xl font-black text-blue-700">Rs. <span x-text="grandTotal.toFixed(2)"></span></span>
                    <span class="block text-[11px] text-slate-400">The saved total is worked out again by the server.</span>
                </div>
            </div>
        </div>

        {{-- Submit Button --}}
        <div class="flex justify-end gap-3">
            @if($isEdit)
                <a href="{{ route('admin.supplier-invoice', ['supplier_id' => $supplierId]) }}" class="py-3 px-6 rounded-xl border border-slate-300 font-bold text-slate-700">Back</a>
            @endif
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-8 rounded-xl shadow-md transition text-lg cursor-pointer">
                💾 {{ $isEdit ? 'Save changes' : 'Save Purchase Order' }}
            </button>
        </div>

    </form>

    {{-- 4. Order history --}}
    @unless($isEdit)
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="bg-slate-50 px-6 py-4 border-b border-slate-200">
            <h3 class="font-bold text-slate-800">Purchase orders{{ $supplierId ? ' for ' . optional($suppliers->firstWhere('id', (int) $supplierId))->name : '' }}</h3>
            <p class="text-xs text-slate-500">"Received" counts the units scanned in on deliveries recorded against the order.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase text-slate-500 bg-slate-50">
                    <tr>
                        <th class="p-3">PO #</th>
                        <th class="p-3">Date</th>
                        <th class="p-3">Supplier</th>
                        <th class="p-3 text-right">Total</th>
                        <th class="p-3">Received</th>
                        <th class="p-3">Status</th>
                        <th class="p-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    @forelse($invoices as $inv)
                        @php $sum = $summaries[$inv->id] ?? null; @endphp
                        <tr>
                            <td class="p-3 font-mono text-xs">{{ $inv->invoice_number }}</td>
                            <td class="p-3">{{ $inv->invoice_date?->format('d M Y') }}</td>
                            <td class="p-3">{{ $inv->supplier->name ?? '-' }}</td>
                            <td class="p-3 text-right font-bold">Rs. {{ number_format((float) $inv->grand_total, 2) }}</td>
                            <td class="p-3 text-xs">
                                @if($sum)
                                    @foreach($sum['device_types'] as $typeId => $q)
                                        <div>{{ $typeNames[$typeId] ?? 'Device type #' . $typeId }}: <b>{{ $q['received'] }}</b> / {{ $q['ordered'] }}</div>
                                    @endforeach
                                    @if($sum['sims_ordered'] || $sum['sims_received'])
                                        <div>SIM cards: <b>{{ $sum['sims_received'] }}</b> / {{ $sum['sims_ordered'] }}</div>
                                    @endif
                                    @if(! $sum['device_types'] && ! $sum['sims_ordered'] && ! $sum['sims_received'])
                                        <span class="text-slate-400">-</span>
                                    @endif
                                @endif
                            </td>
                            <td class="p-3">
                                @if($inv->isCancelled())
                                    <span class="px-2 py-0.5 rounded-full bg-red-100 text-red-700 font-bold text-[10px]" title="{{ $inv->cancel_reason }}">Cancelled</span>
                                @elseif($sum && $sum['editable'])
                                    <span class="px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 font-bold text-[10px]">Ordered</span>
                                @else
                                    <span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 font-bold text-[10px]">Receiving / received</span>
                                @endif
                            </td>
                            <td class="p-3 text-right whitespace-nowrap text-xs font-bold">
                                <a href="{{ route('admin.supplier-invoice.download', $inv->id) }}" class="text-blue-700 hover:underline mr-3">Download</a>
                                @if($sum && $sum['editable'])
                                    <a href="{{ route('admin.supplier-invoice.edit', $inv->id) }}" class="text-slate-700 hover:underline mr-3">Edit</a>
                                    <form method="POST" action="{{ route('admin.supplier-invoice.cancel', $inv->id) }}" class="inline"
                                        onsubmit="const r = prompt('Why is this purchase order being cancelled?'); if (!r || r.trim().length < 3) { return false; } this.reason.value = r.trim(); return true;">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="reason" value="">
                                        <button type="submit" class="text-red-600 hover:underline">Cancel</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="p-6 text-center text-slate-400">No purchase orders yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @endunless
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('invoiceSystem', (cfg) => ({
        supplierId: cfg.supplierId || '',
        editing: cfg.editing,
        supplierDetails: null,
        availableProducts: [],
        items: [],
        isLoading: false,

        init() {
            if (this.supplierId) {
                this.fetchSupplierData();
            }
        },

        fetchSupplierData() {
            if (!this.supplierId) {
                this.supplierDetails = null;
                this.availableProducts = [];
                this.items = [];
                return;
            }
            this.isLoading = true;

            fetch(`${cfg.dataUrl}/${this.supplierId}`, { headers: { 'Accept': 'application/json' } })
                .then(res => {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.json();
                })
                .then(data => {
                    this.supplierDetails = data.supplier;
                    this.availableProducts = data.products || [];
                    // Editing: the saved lines. A new order: start empty when another supplier is chosen.
                    this.items = this.editing ? this.editing.items.map(i => Object.assign({}, i)) : [];
                    this.isLoading = false;
                })
                .catch(err => {
                    console.error('Error fetching data:', err);
                    alert('Could not load the supplier. Check your connection and try again.');
                    this.isLoading = false;
                });
        },

        addItem() {
            if (!this.supplierId) {
                alert('Please select a supplier first!');
                return;
            }
            this.items.push({
                product_id: '',
                type: 'Device',
                order_qty: 1,
                unit_price: 0,
                discount: 0,
                face_value: 0
            });
        },

        removeItem(index) {
            this.items.splice(index, 1);
        },

        product(item) {
            return this.availableProducts.find(p => String(p.id) === String(item.product_id)) || null;
        },

        productSelected(item) {
            const prod = this.product(item);
            if (prod) {
                item.unit_price = prod.price || 0;
                item.type = prod.type || item.type;
                if (Number(prod.qty) > 0) item.order_qty = Number(prod.qty);   // the supplier's usual order quantity
            }
        },

        agreedPrice(item) {
            const prod = this.product(item);
            return prod ? Number(prod.price || 0) : null;
        },

        typeOptions(item) {
            const base = ['Device', 'SIM', 'Service', 'Other'];
            // An older order may have a type that is no longer offered (Hardware, Software...): keep it selectable.
            return item.type && !base.includes(item.type) ? [item.type].concat(base) : base;
        },

        calculateRowTotal(item) {
            let lineTotal = (Number(item.order_qty) || 0) * (Number(item.unit_price) || 0);
            let discAmt = lineTotal * ((Number(item.discount) || 0) / 100);
            return lineTotal - discAmt + Number(item.face_value || 0);
        },

        get grandTotal() {
            return this.items.reduce((total, item) => {
                return total + this.calculateRowTotal(item);
            }, 0);
        },

        validateForm(e) {
            if (this.items.length === 0) {
                e.preventDefault();
                alert('⚠️ Please add at least one item to the order before saving.');
            }
        }
    }));
});
</script>
@endsection
