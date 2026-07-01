<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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

            <div x-data="{
                selectedSupplierId: '',
                // Supplier database lookup
                suppliers: {
                    'amila_test': { name: 'amila test', branch: 'Colombo Main', email: 'test@cloud.com', mobile: '079123456', address: '8d colombo', gstin: '13214' },
                    'dialog_axiata': { name: 'Dialog Axiata PLC', branch: 'Head Office', email: 'corporate@dialog.lk', mobile: '0777123456', address: 'Union Place, Colombo 02', gstin: '99875' },
                    'mobitel_sri_lanka': { name: 'Mobitel Sri Lanka', branch: 'Sri Lanka Branch', email: 'test@test.com', mobile: '1122334455', address: 'Sri Lanka colombo', gstin: '12345' }
                },
                // Returns currently active supplier data or empty placeholders
                get activeSupplier() {
                    return this.suppliers[this.selectedSupplierId] || { name: '', branch: '', email: '', mobile: '', address: '', gstin: '' };
                },
                items: [
                    { selected: true, type: 'SIM', product: 'Mobitel Sri Lanka', order_qty: 120, price: 2233.00, discount: 0, face_value: 0, net: 267960.00 }
                ],
                addItem() {
                    this.items.push({ selected: true, type: 'SIM', product: '', order_qty: 0, price: 0, discount: 0, face_value: 0, net: 0 });
                },
                removeItem(index) {
                    this.items.splice(index, 1);
                },
                calculateNet(item) {
                    let total = item.order_qty * item.price;
                    let discAmount = (total * item.discount) / 100;
                    item.net = total - discAmount + parseFloat(item.face_value || 0);
                    return item.net.toFixed(2);
                },
                get grandTotal() {
                    return this.items.reduce((sum, item) => sum + (item.selected ? parseFloat(item.net) : 0), 0).toFixed(2);
                },
                printPO() {
                    window.print();
                }
            }" class="w-full space-y-6">

                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden w-full print:hidden">
                    <div class="px-5 py-4 border-b border-gray-100 bg-gray-50">
                        <h3 class="text-sm font-bold text-gray-800">Purchase Order Wizard</h3>
                    </div>
                    <div class="p-5 text-xs font-semibold text-gray-700">
                        <div class="grid grid-cols-1 md:grid-cols-3 items-center gap-4">
                            <label class="text-gray-600 font-bold">Select Supplier to Generate Invoice :</label>
                            <div class="md:col-span-2">
                                <select x-model="selectedSupplierId" class="w-full rounded-lg border-gray-300 text-xs h-10 shadow-sm focus:border-blue-500 focus:ring-blue-500 font-bold text-gray-800">
                                    <option value="" selected>-- Click to Select Registered Supplier --</option>
                                    <option value="amila_test">amila test (Colombo)</option>
                                    <option value="dialog_axiata">Dialog Axiata PLC</option>
                                    <option value="mobitel_sri_lanka">Mobitel Sri Lanka</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full print:border-none print:shadow-none">
                    
                    <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex justify-between items-center print:bg-white print:pb-6">
                        <div>
                            <h2 class="text-base font-bold text-gray-900 tracking-wide uppercase">Purchase Order / Supplier Invoice</h2>
                            <p class="text-[11px] text-gray-500 mt-1">Date: {{ now()->format('d/m/Y') }}</p>
                        </div>
                        <div class="flex gap-2 print:hidden">
                            <button @click="printPO()" :disabled="!selectedSupplierId" type="button" :class="selectedSupplierId ? 'bg-[#17a2b8] hover:bg-[#138496]' : 'bg-gray-300 cursor-not-allowed'" class="text-white font-bold text-xs py-2 px-5 rounded-lg shadow-sm transition flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                                Save & Print PDF
                            </button>
                        </div>
                    </div>

                    <div class="p-6 bg-gray-50/50 grid grid-cols-2 md:grid-cols-4 gap-4 text-xs font-semibold text-gray-600 border-b border-gray-100 print:bg-white">
                        <div>
                            <span class="text-[10px] uppercase text-gray-400 block">Supplier Name</span>
                            <span class="text-gray-900 font-bold text-sm block mt-0.5" x-text="activeSupplier.name || 'No Supplier Selected'"></span>
                        </div>
                        <div>
                            <span class="text-[10px] uppercase text-gray-400 block">Branch Office</span>
                            <span class="text-gray-800 block mt-0.5" x-text="activeSupplier.branch || '-'"></span>
                        </div>
                        <div>
                            <span class="text-[10px] uppercase text-gray-400 block">Email Address</span>
                            <span class="text-gray-800 block mt-0.5" x-text="activeSupplier.email || '-'"></span>
                        </div>
                        <div>
                            <span class="text-[10px] uppercase text-gray-400 block">Mobile / Phone</span>
                            <span class="text-gray-800 block mt-0.5" x-text="activeSupplier.mobile || '-'"></span>
                        </div>
                        <div class="col-span-2 md:col-span-3 mt-2">
                            <span class="text-[10px] uppercase text-gray-400 block">Physical Address</span>
                            <span class="text-gray-800 block mt-0.5" x-text="activeSupplier.address || '-'"></span>
                        </div>
                        <div class="mt-2">
                            <span class="text-[10px] uppercase text-gray-400 block">Tax GSTIN No</span>
                            <span class="text-gray-800 font-mono block mt-0.5" x-text="activeSupplier.gstin || '-'"></span>
                        </div>
                    </div>

                    <div class="p-6">
                        <div class="flex justify-between items-center mb-3 print:hidden">
                            <h4 class="text-xs font-bold text-gray-800 uppercase tracking-wider">Order Items List</h4>
                            <button :disabled="!selectedSupplierId" type="button" @click="addItem()" class="bg-gray-800 hover:bg-gray-900 text-white font-bold text-[10px] py-1.5 px-3 rounded shadow-sm disabled:opacity-50 transition">+ Add Item Row</button>
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
                                                <input type="checkbox" x-model="item.selected" :disabled="!selectedSupplierId" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4">
                                            </td>
                                            <td class="p-3 text-center font-bold text-gray-400" x-text="index + 1"></td>
                                            <td class="p-2">
                                                <select x-model="item.type" :disabled="!item.selected || !selectedSupplierId" class="w-full rounded border-gray-300 py-1 px-2 text-xs focus:ring-1 focus:ring-blue-500">
                                                    <option value="SIM">SIM</option>
                                                    <option value="Device">Device</option>
                                                </select>
                                            </td>
                                            <td class="p-2">
                                                <select x-model="item.product" :disabled="!item.selected || !selectedSupplierId" class="w-full rounded border-gray-300 py-1 px-2 text-xs focus:ring-1 focus:ring-blue-500">
                                                    <option value="" disabled selected>Select Product</option>
                                                    <option value="Mobitel Sri Lanka">Mobitel Sri Lanka Package</option>
                                                    <option value="Dialog Axiata">Dialog eSIM</option>
                                                    <option value="GPS Tracker">Port In GPS Tracker</option>
                                                </select>
                                            </td>
                                            <td class="p-2">
                                                <input type="number" x-model.number="item.order_qty" @input="calculateNet(item)" :disabled="!item.selected || !selectedSupplierId" class="w-full rounded border-gray-300 py-1 px-1.5 text-xs text-center">
                                            </td>
                                            <td class="p-2">
                                                <input type="number" step="0.01" x-model.number="item.price" @input="calculateNet(item)" :disabled="!item.selected || !selectedSupplierId" class="w-full rounded border-gray-300 py-1 px-1.5 text-xs text-center font-semibold">
                                            </td>
                                            <td class="p-2">
                                                <input type="number" x-model.number="item.discount" @input="calculateNet(item)" :disabled="!item.selected || !selectedSupplierId" class="w-full rounded border-gray-300 py-1 px-1.5 text-xs text-center">
                                            </td>
                                            <td class="p-2">
                                                <input type="number" x-model.number="item.face_value" @input="calculateNet(item)" :disabled="!item.selected || !selectedSupplierId" class="w-full rounded border-gray-300 py-1 px-1.5 text-xs text-center">
                                            </td>
                                            <td class="p-3 text-right font-bold text-gray-900 pr-4" x-text="'LKR ' + (selectedSupplierId ? calculateNet(item) : '0.00')"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="p-6 border-t border-gray-100 bg-gray-50 grid grid-cols-1 md:grid-cols-12 gap-6 print:bg-white">
                        

                        <div class="md:col-span-3 flex flex-col justify-center items-end text-right">
                            <span class="text-[10px] uppercase text-gray-400 font-bold tracking-wider">Grand Total Summary</span>
                            <div class="text-2xl font-black text-gray-900 tracking-tight mt-1">
                                <span class="text-xs font-bold text-gray-400">LKR</span> 
                                <span x-text="selectedSupplierId ? grandTotal : '0.00'"></span>
                            </div>
                        </div>
                    </div>

                </div>

            </div>
        </main>
    </div>
</div>

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>

<style>
    @media print {
        body * { visibility: hidden; }
        main, main * { visibility: visible; }
        main { position: absolute; left: 0; top: 0; width: 100%; }
        .print\:hidden { display: none !important; }
        .print\:border-none { border: none !important; }
        .print\:shadow-none { box-shadow: none !important; }
    }
</style>

</body>
</html>