<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ShaloTrack Admin - Purchase Order</title>

    @vite(['resources/css/app.css','resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>

<body x-data="{ sidebarOpen: false }">

<div class="flex h-screen overflow-hidden bg-gray-50">

    @include('partials.sidebars.admin')

    <div class="flex-1 flex flex-col overflow-y-auto">

        @include('partials.header')

        <main class="p-4 md:p-6 flex-1 space-y-6">

            @yield('content')

            @if(session('success'))
                <div class="p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-xs font-bold print:hidden">
                    {{ session('success') }}
                </div>
            @endif

            <div x-data="purchaseOrder()" class="w-full space-y-6">

                {{-- PURCHASE ORDER WIZARD --}}
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden w-full print:hidden">

                    <div class="px-5 py-4 border-b border-gray-100 bg-gray-50">
                        <h3 class="text-sm font-bold text-gray-800">
                            Purchase Order Wizard
                        </h3>
                    </div>

                    <div class="p-5 text-xs font-semibold text-gray-700">

                        <div class="grid grid-cols-1 md:grid-cols-3 items-center gap-4">

                            <label class="text-gray-600 font-bold">
                                Select Supplier to Generate Invoice :
                            </label>

                            <div class="md:col-span-2">

                                <select
                                    x-model="selectedSupplierId"
                                    @change="loadSupplier()"
                                    class="w-full rounded-lg border-gray-300 text-xs h-10 shadow-sm focus:border-blue-500 focus:ring-blue-500 font-bold text-gray-800"
                                >
                                    <option value="">
                                        -- Click to Select Registered Supplier --
                                    </option>

                                    <template x-for="supplier in suppliers" :key="supplier.id">
                                        <option
                                            :value="supplier.id"
                                            x-text="supplier.name">
                                        </option>
                                    </template>
                                </select>

                                <p
                                    x-show="loadingSupplier"
                                    class="text-[10px] text-gray-400 mt-1"
                                >
                                    Loading supplier products...
                                </p>

                                <p
                                    x-show="!loadingSupplier && selectedSupplierId && supplierProducts.length === 0"
                                    class="text-[10px] text-amber-600 font-bold mt-1"
                                >
                                    This supplier has no linked products yet — add them via Supplier Management first.
                                </p>

                            </div>

                        </div>

                    </div>

                </div>


                {{-- PURCHASE ORDER / SUPPLIER INVOICE --}}
                <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full print:border-none print:shadow-none">

                    {{-- HEADER --}}
                    <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex justify-between items-center print:bg-white print:pb-6">

                        <div>
                            <h2 class="text-base font-bold text-gray-900 tracking-wide uppercase">
                                Purchase Order / Supplier Invoice
                            </h2>

                            <p class="text-[11px] text-gray-500 mt-1">
                                Date: {{ now()->format('d/m/Y') }}
                            </p>
                        </div>

                        <div class="flex gap-2 print:hidden">

                            <button
                                @click="submitOrder()"
                                :disabled="!selectedSupplierId || saving"
                                type="button"
                                :class="(selectedSupplierId && !saving)
                                    ? 'bg-[#17a2b8] hover:bg-[#138496]'
                                    : 'bg-gray-300 cursor-not-allowed'"
                                class="text-white font-bold text-xs py-2 px-5 rounded-lg shadow-sm transition flex items-center gap-2"
                            >

                                <svg
                                    class="w-4 h-4"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"
                                    />
                                </svg>

                                <span x-text="saving ? 'Saving...' : 'Save Purchase Order'"></span>

                            </button>

                        </div>

                    </div>


                    {{-- SUPPLIER INFO --}}
                    <div class="p-6 bg-gray-50/50 grid grid-cols-2 md:grid-cols-4 gap-4 text-xs font-semibold text-gray-600 border-b border-gray-100 print:bg-white">

                        <div>
                            <span class="text-[10px] uppercase text-gray-400 block">
                                Supplier Name
                            </span>

                            <span
                                class="text-gray-900 font-bold text-sm block mt-0.5"
                                x-text="supplierInfo.name || 'No Supplier Selected'"
                            ></span>
                        </div>

                        <div>
                            <span class="text-[10px] uppercase text-gray-400 block">
                                State
                            </span>

                            <span
                                class="text-gray-800 block mt-0.5"
                                x-text="supplierInfo.state || '-'"
                            ></span>
                        </div>

                        <div>
                            <span class="text-[10px] uppercase text-gray-400 block">
                                Email Address
                            </span>

                            <span
                                class="text-gray-800 block mt-0.5"
                                x-text="supplierInfo.email || '-'"
                            ></span>
                        </div>

                        <div>
                            <span class="text-[10px] uppercase text-gray-400 block">
                                Mobile / Phone
                            </span>

                            <span
                                class="text-gray-800 block mt-0.5"
                                x-text="supplierInfo.phone_number || '-'"
                            ></span>
                        </div>

                        <div class="col-span-2 md:col-span-3 mt-2">
                            <span class="text-[10px] uppercase text-gray-400 block">
                                Physical Address
                            </span>

                            <span
                                class="text-gray-800 block mt-0.5"
                                x-text="supplierInfo.address || '-'"
                            ></span>
                        </div>

                        <div class="mt-2">
                            <span class="text-[10px] uppercase text-gray-400 block">
                                Tax / VAT Reg. No.
                            </span>

                            <span
                                class="text-gray-800 font-mono block mt-0.5"
                                x-text="supplierInfo.gstin_number || '-'"
                            ></span>
                        </div>

                    </div>


                    {{-- ORDER ITEMS --}}
                    <div class="p-6">

                        <div class="flex justify-between items-center mb-3 print:hidden">

                            <h4 class="text-xs font-bold text-gray-800 uppercase tracking-wider">
                                Order Items List
                            </h4>

                            <button
                                :disabled="!selectedSupplierId || supplierProducts.length === 0"
                                type="button"
                                @click="addItem()"
                                class="bg-gray-800 hover:bg-gray-900 text-white font-bold text-[10px] py-1.5 px-3 rounded shadow-sm disabled:opacity-50 transition"
                            >
                                + Add Item Row
                            </button>

                        </div>


                        <div class="border border-gray-200 rounded-xl overflow-hidden shadow-sm overflow-x-auto">

                            <table class="w-full text-left border-collapse min-w-[850px]">

                                <thead class="bg-gray-50 border-b border-gray-200 text-[11px] text-gray-600 uppercase">

                                <tr>
                                    <th class="p-3 w-12 text-center print:hidden"></th>
                                    <th class="p-3 w-10 text-center">Sr.</th>
                                    <th class="p-3">Product Type</th>
                                    <th class="p-3">Product Items</th>
                                    <th class="p-3 w-20 text-center">Qty</th>
                                    <th class="p-3 w-24 text-center">Unit Price</th>
                                    <th class="p-3 w-20 text-center">Disc (%)</th>
                                    <th class="p-3 w-24 text-center">Face Value</th>
                                    <th class="p-3 w-28 text-right pr-4">Net Amount</th>
                                </tr>

                                </thead>


                                <tbody class="divide-y divide-gray-200 bg-white text-xs font-medium text-gray-700">

                                <template x-for="(item, index) in items" :key="index">

                                    <tr :class="!item.selected ? 'opacity-30 bg-gray-50' : ''">

                                        <td class="p-3 text-center print:hidden">
                                            <input
                                                type="checkbox"
                                                x-model="item.selected"
                                                class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4"
                                            >
                                        </td>

                                        <td
                                            class="p-3 text-center font-bold text-gray-400"
                                            x-text="index + 1"
                                        ></td>

                                        <td class="p-2">

                                            <select
                                                x-model="item.type"
                                                :disabled="!item.selected"
                                                class="w-full rounded border-gray-300 py-1 px-2 text-xs focus:ring-1 focus:ring-blue-500"
                                            >
                                                <option value="SIM">SIM</option>
                                                <option value="Device">Device</option>
                                            </select>

                                        </td>

                                        <td class="p-2">

                                            <select
                                                x-model="item.product_id"
                                                @change="onProductChange(item)"
                                                :disabled="!item.selected"
                                                class="w-full rounded border-gray-300 py-1 px-2 text-xs focus:ring-1 focus:ring-blue-500"
                                            >

                                                <option value="" disabled>
                                                    Select Product
                                                </option>

                                                <template
                                                    x-for="product in supplierProducts"
                                                    :key="product.id"
                                                >
                                                    <option
                                                        :value="product.id"
                                                        x-text="product.product_name"
                                                    ></option>
                                                </template>

                                            </select>

                                        </td>

                                        <td class="p-2">

                                            <input
                                                type="number"
                                                min="1"
                                                x-model.number="item.order_qty"
                                                @input="calculateNet(item)"
                                                :disabled="!item.selected"
                                                class="w-full rounded border-gray-300 py-1 px-1.5 text-xs text-center"
                                            >

                                        </td>

                                        <td class="p-2">

                                            <input
                                                type="number"
                                                step="0.01"
                                                x-model.number="item.unit_price"
                                                @input="calculateNet(item)"
                                                :disabled="!item.selected"
                                                class="w-full rounded border-gray-300 py-1 px-1.5 text-xs text-center font-semibold"
                                            >

                                        </td>

                                        <td class="p-2">

                                            <input
                                                type="number"
                                                x-model.number="item.discount"
                                                @input="calculateNet(item)"
                                                :disabled="!item.selected"
                                                class="w-full rounded border-gray-300 py-1 px-1.5 text-xs text-center"
                                            >

                                        </td>

                                        <td class="p-2">

                                            <input
                                                type="number"
                                                x-model.number="item.face_value"
                                                @input="calculateNet(item)"
                                                :disabled="!item.selected"
                                                class="w-full rounded border-gray-300 py-1 px-1.5 text-xs text-center"
                                            >

                                        </td>

                                        <td
                                            class="p-3 text-right font-bold text-gray-900 pr-4"
                                            x-text="'LKR ' + calculateNet(item)"
                                        ></td>

                                    </tr>

                                </template>


                                <tr x-show="items.length === 0">

                                    <td
                                        colspan="9"
                                        class="p-6 text-center text-gray-400"
                                    >

                                        <span x-show="!selectedSupplierId">
                                            Select a supplier to start adding items.
                                        </span>

                                        <span x-show="selectedSupplierId && supplierProducts.length === 0">
                                            This supplier has no linked products yet.
                                        </span>

                                    </td>

                                </tr>

                                </tbody>

                            </table>

                        </div>

                    </div>


                    {{-- GRAND TOTAL --}}
                    <div class="p-6 border-t border-gray-100 bg-gray-50 grid grid-cols-1 md:grid-cols-12 gap-6 print:bg-white">

                        <div class="md:col-span-3 md:col-start-10 flex flex-col justify-center items-end text-right">

                            <span class="text-[10px] uppercase text-gray-400 font-bold tracking-wider">
                                Grand Total Summary
                            </span>

                            <div class="text-2xl font-black text-gray-900 tracking-tight mt-1">

                                <span class="text-xs font-bold text-gray-400">
                                    LKR
                                </span>

                                <span x-text="grandTotal"></span>

                            </div>

                        </div>

                    </div>

                </div>

            </div>


            {{-- PURCHASE ORDER HISTORY --}}
            <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full print:hidden">

                <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex items-center justify-between">

                    <h3 class="text-base font-bold text-gray-800">

                        Purchase Order History

                        @if($supplierId !== '')
                            <span class="text-gray-400 font-normal text-sm">
                                — filtered by selected supplier
                            </span>
                        @endif

                    </h3>

                    @if($supplierId !== '')

                        <a
                            href="{{ route('admin.supplier-invoice') }}"
                            class="text-xs font-bold text-blue-600 hover:underline"
                        >
                            Show all suppliers
                        </a>

                    @endif

                </div>


                <div class="p-6">

                    <div class="border border-gray-200 rounded-lg overflow-x-auto">

                        <table class="w-full text-left border-collapse">

                            <thead class="bg-gray-50 border-b border-gray-200 text-xs text-gray-700 uppercase">

                            <tr>
                                <th class="p-4">Invoice Number</th>
                                <th class="p-4">Supplier</th>
                                <th class="p-4">Date</th>
                                <th class="p-4 text-right">Grand Total</th>
                                <th class="p-4 text-center">Action</th>
                            </tr>

                            </thead>


                            <tbody class="divide-y divide-gray-200 bg-white text-sm font-medium text-gray-700">

                            @forelse($invoices as $invoice)

                                <tr class="hover:bg-gray-50 transition">

                                    <td class="p-4 font-mono text-xs">
                                        {{ $invoice->invoice_number }}
                                    </td>

                                    <td class="p-4 font-bold">
                                        {{ $invoice->supplier->name ?? '-' }}
                                    </td>

                                    <td class="p-4 text-xs text-gray-500">
                                        {{ $invoice->invoice_date->format('d M Y') }}
                                    </td>

                                    <td class="p-4 text-right font-bold text-gray-900">
                                        LKR {{ number_format($invoice->grand_total, 2) }}
                                    </td>

                                    <td class="p-4 text-center">
                                        <a
                                            href="{{ route('admin.supplier-invoice.download', $invoice->id) }}"
                                            class="bg-blue-950 hover:bg-blue-900 text-white
                                                text-xs font-bold px-4 py-2 rounded-lg"
                                        >
                                            Download PDF
                                        </a>
                                    </td>

                                </tr>

                            @empty

                                <tr>
                                    <td
                                        colspan="4"
                                        class="p-8 text-center text-gray-400"
                                    >
                                        No purchase orders recorded yet.
                                    </td>
                                </tr>

                            @endforelse

                            </tbody>

                        </table>

                    </div>

                </div>

            </div>

        </main>

    </div>

</div>


<script>
function purchaseOrder() {
    return {
        suppliers: @json(
            $suppliers->map(fn($s) => [
                'id' => $s->id,
                'name' => $s->name
            ])->values()
        ),

        selectedSupplierId: @json((string) $supplierId),

        supplierInfo: {
            name: '',
            state: '',
            email: '',
            phone_number: '',
            address: '',
            gstin_number: ''
        },

        supplierProducts: [],
        loadingSupplier: false,
        saving: false,
        items: [],

        async loadSupplier() {
            this.supplierInfo = {
                name: '',
                state: '',
                email: '',
                phone_number: '',
                address: '',
                gstin_number: ''
            };

            this.supplierProducts = [];
            this.items = [];

            if (!this.selectedSupplierId) {
                return;
            }

            this.loadingSupplier = true;

            try {
                const res = await fetch(
                    '/admin/supplier/' +
                    this.selectedSupplierId +
                    '/purchase-data'
                );

                if (!res.ok) {
                    throw new Error('HTTP ' + res.status);
                }

                const data = await res.json();

                this.supplierInfo = data.supplier || {};
                this.supplierProducts = data.products || [];

                if (this.supplierProducts.length > 0) {
                    this.addItem();
                }

            } catch (e) {
                console.error(e);

                alert(
                    "Failed to load this supplier's products. " +
                    "Check the console for details."
                );

            } finally {
                this.loadingSupplier = false;
            }
        },

        addItem() {
            const first = this.supplierProducts[0] || null;

            const item = {
                selected: true,
                type: 'Device',
                product_id: first ? first.id : '',
                order_qty: 1,
                unit_price: first ? Number(first.price || 0) : 0,
                discount: first ? Number(first.discount || 0) : 0,
                face_value: 0,
                net_amount: 0
            };

            this.items.push(item);
            this.calculateNet(item);
        },

        removeItem(index) {
            this.items.splice(index, 1);
        },

        onProductChange(item) {
            const product = this.supplierProducts.find(
                p => p.id == item.product_id
            );

            if (product) {
                item.unit_price = Number(product.price || 0);
                item.discount = Number(product.discount || 0);
            }

            this.calculateNet(item);
        },

        calculateNet(item) {
            const quantity = Number(item.order_qty || 0);
            const unitPrice = Number(item.unit_price || 0);
            const discount = Number(item.discount || 0);
            const faceValue = Number(item.face_value || 0);

            const total = quantity * unitPrice;
            const discountAmount = (total * discount) / 100;

            item.net_amount = total - discountAmount + faceValue;

            return Number(item.net_amount || 0).toFixed(2);
        },

        get grandTotal() {
            return this.items
                .reduce((sum, item) => {
                    return sum + (
                        item.selected
                            ? Number(item.net_amount || 0)
                            : 0
                    );
                }, 0)
                .toFixed(2);
        },

        async submitOrder() {
            if (!this.selectedSupplierId) {
                alert('Select a supplier first.');
                return;
            }

            const selectedItems = this.items.filter(
                item => item.selected && item.product_id
            );

            if (selectedItems.length === 0) {
                alert('Add at least one item.');
                return;
            }

            const invalidQuantity = selectedItems.some(
                item => Number(item.order_qty) <= 0
            );

            if (invalidQuantity) {
                alert('Quantity must be greater than 0.');
                return;
            }

            this.saving = true;

            try {
                const res = await fetch(
                    @json(route('admin.supplier-invoice.store')),
                    {
                        method: 'POST',

                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document
                                .querySelector('meta[name="csrf-token"]')
                                .getAttribute('content'),
                            'Accept': 'application/json'
                        },

                        body: JSON.stringify({
                            supplier_id: this.selectedSupplierId,

                            items: selectedItems.map(item => ({
                                product_id: item.product_id,
                                type: item.type,
                                order_qty: Number(item.order_qty),
                                unit_price: Number(item.unit_price),
                                discount: Number(item.discount),
                                face_value: Number(item.face_value)
                            }))
                        })
                    }
                );

                if (!res.ok) {
                    const err = await res.json().catch(() => null);

                    throw new Error(
                        err && err.message
                            ? err.message
                            : 'HTTP ' + res.status
                    );
                }

                window.location.href =
                    @json(route('admin.supplier-invoice')) +
                    '?supplier_id=' +
                    encodeURIComponent(this.selectedSupplierId);

            } catch (e) {
                console.error(e);

                alert(
                    'Failed to save Purchase Order: ' +
                    e.message
                );

            } finally {
                this.saving = false;
            }
        },

        init() {
            if (this.selectedSupplierId) {
                this.loadSupplier();
            }
        }
    };
}
</script>

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>

<style>
@media print {
    body * { visibility: hidden; }
    main, main * { visibility: visible; }
    main {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
    }
    .print\:hidden { display: none !important; }
    .print\:border-none { border: none !important; }
    .print\:shadow-none { box-shadow: none !important; }
}
</style>

</body>
</html>