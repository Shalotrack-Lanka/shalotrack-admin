<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin - Manage Raw Stock</title>
    @vite(['resources/css/app.css','resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body x-data="{ sidebarOpen: false }">

<div class="flex h-screen overflow-hidden bg-gray-50">
    @include('partials.sidebars.admin')

    <div class="flex-1 flex flex-col overflow-y-auto">
        @include('partials.header')

        <main class="p-4 md:p-6 flex-1 space-y-6">

            @if(session('success'))
                <div class="p-3 bg-green-50 text-green-700 border border-green-200 rounded-lg text-xs font-bold flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    {{ session('success') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg shadow-xs">
                    <div class="flex items-center gap-2 mb-2">
                        <svg class="w-4 h-4 text-red-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        <span class="text-xs font-bold uppercase tracking-wider">Please fix the following:</span>
                    </div>
                    <ul class="list-disc pl-5 space-y-1 text-[11px] font-semibold">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- ===================== ADD RAW DEVICES ===================== --}}
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden w-full"
                 x-data="{
                     deviceTypeId: '',
                     supplierId: '',
                     stockIn: 0,
                     stockMap: {{ \Illuminate\Support\Js::from($stockMap) }},
                     get baseAvailable() { return Number(this.stockMap[this.deviceTypeId] ?? 0); },
                     get total() { return this.baseAvailable + (Number(this.stockIn) || 0); },
                     reset() { this.deviceTypeId = ''; this.supplierId = ''; this.stockIn = 0; }
                 }">
                <div class="px-5 py-3 border-b border-gray-100 bg-gray-50">
                    <span class="font-bold text-gray-800 text-sm">Add Raw Devices</span>
                </div>

                <div class="p-5 text-xs font-semibold text-gray-700">
                    <form action="{{ route('admin.stock.store') }}" method="POST"
                          class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 items-end">
                        @csrf

                        <div>
                            <label class="block mb-1">Device Category / Type</label>
                            <select name="device_type_id" x-model="deviceTypeId" required
                                    class="w-full rounded-lg border-gray-300 h-10 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="" disabled>--Select Device Category / Type--</option>
                                @forelse($deviceTypes as $type)
                                    <option value="{{ $type->id }}">{{ $type->device_category }} with {{ $type->model }}</option>
                                @empty
                                    <option value="" disabled>No device types set up yet</option>
                                @endforelse
                            </select>
                        </div>

                        <div>
                            <label class="block mb-1">Supplier</label>
                            <select name="supplier_id" x-model="supplierId" required
                                    class="w-full rounded-lg border-gray-300 h-10 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="" disabled>--Select Supplier--</option>
                                @forelse($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                @empty
                                    <option value="" disabled>No active suppliers found</option>
                                @endforelse
                            </select>
                        </div>

                        <div>
                            <label class="block mb-1">Stock In</label>
                            <div class="flex items-center gap-2">
                                <button type="button" @click="stockIn = Math.max(0, Number(stockIn) - 1)" class="w-10 h-10 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold shrink-0">&minus;</button>
                                <input type="number" name="stock_in" x-model.number="stockIn" min="0" required
                                       class="w-full rounded-lg border-gray-300 h-10 text-xs shadow-sm text-center">
                                <button type="button" @click="stockIn = Number(stockIn) + 1" class="w-10 h-10 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold shrink-0">+</button>
                            </div>
                        </div>

                        <div class="p-2.5 rounded-lg bg-gray-50 border border-gray-200 flex justify-between items-center h-10">
                            <span class="text-gray-600">Total Available</span>
                            <span class="font-bold text-gray-900 text-sm" x-text="total"></span>
                        </div>

                        <div class="md:col-span-2 xl:col-span-4 flex gap-2 justify-end pt-2 border-t border-gray-100 mt-2">
                            <button type="button" @click="reset()" class="px-6 bg-gray-100 hover:bg-gray-200 text-gray-700 py-2.5 rounded font-bold transition">Reset</button>
                            <button type="submit" class="px-8 bg-[#17a2b8] hover:bg-[#138496] text-white py-2.5 rounded font-bold shadow-sm transition">Add Stock</button>
                        </div>
                    </form>
                </div>
            </div>

            {{-- ===================== COMPANY AVAILABLE STOCK ===================== --}}
             <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden w-full">
                <div class="px-5 py-3 border-b border-gray-100 bg-gray-50">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
                    <h3 class="font-bold text-gray-800 text-sm">Company Available Stock</h3>
                        <a href="{{ route('admin.stock.report', ['type' => 'stock']) }}" target="_blank" class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold px-3 py-1.5 rounded-lg shadow-sm transition inline-flex items-center gap-1">
                            Generate Stock Report
                        </a>
                    </div>
                </div>

                <div class="p-5">
                    <div class="border border-gray-200 rounded-xl shadow-sm overflow-x-auto max-h-96 overflow-y-auto">
                        <table class="w-full min-w-[600px] text-left border-collapse table-auto">
                            <thead class="bg-gray-50 border-b border-gray-200 text-[11px] text-gray-600 uppercase tracking-wider whitespace-nowrap sticky top-0 z-10">
                                <tr>
                                    <th class="p-3 w-20">Stock Id</th>
                                    <th class="p-3 min-w-[220px]">Device Category / Type</th>
                                    <th class="p-3 text-right">Company Available Stock</th>
                                    <th class="p-3">Last Edited Date</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white text-xs font-semibold text-gray-700">
                                @forelse($stocks as $stock)
                                    <tr class="hover:bg-gray-50/70 transition">
                                        <td class="p-3 text-gray-400 font-mono">{{ $stock->id }}</td>
                                        <td class="p-3">{{ $stock->device_category_type }}</td>
                                        <td class="p-3 text-right font-bold text-green-600 font-mono tabular-nums">{{ $stock->company_available_stock }}</td>
                                        <td class="p-3 text-gray-400 font-mono text-[11px] whitespace-nowrap">{{ optional($stock->updated_at)->format('Y-m-d H:i') }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="p-6 text-center text-gray-400 font-medium italic">No stock recorded yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- ===================== STOCK TRANSFER LEDGER ===================== --}}
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden w-full">
                <div class="px-5 py-3 border-b border-gray-100 bg-gray-50">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
                    <h3 class="font-bold text-gray-800 text-sm">Stock Transfer Ledger</h3>
                        <a href="{{ route('admin.stock.report', ['type' => 'ledger']) }}" target="_blank" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-3 py-1.5 rounded-lg shadow-sm transition inline-flex items-center gap-1">
                            Generate Ledger Report
                        </a>
                    </div>
                </div>

                @foreach($ledgerEntries as $entry)
                    <form id="ledger-desc-{{ $entry->id }}" action="{{ route('admin.stock.ledger.update', $entry) }}" method="POST" class="hidden">
                        @csrf
                        @method('PATCH')
                    </form>
                @endforeach

                <div class="p-5">
                    <div class="border border-gray-200 rounded-xl shadow-sm overflow-x-auto max-h-96 overflow-y-auto">
                        <table class="w-full min-w-[900px] text-left border-collapse table-auto">
                            <thead class="bg-gray-50 border-b border-gray-200 text-[11px] text-gray-600 uppercase tracking-wider whitespace-nowrap sticky top-0 z-10">
                                <tr>
                                    <th class="p-3 min-w-[200px]">Device Category /Type</th>
                                    <th class="p-3 w-20">Supplier Id</th>
                                    <th class="p-3 min-w-[150px]">Supplier</th>
                                    <th class="p-3 text-right">Stock In</th>
                                    <th class="p-3 min-w-[200px]">Description</th>
                                    <th class="p-3">Stocked-In Date</th>
                                    <th class="p-3 text-center">Save & Delete</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white text-xs font-semibold text-gray-700">
                                @forelse($ledgerEntries as $entry)
                                    <tr class="hover:bg-gray-50/70 transition">
                                        <td class="p-3">{{ $entry->device_category_type }}</td>
                                        <td class="p-3 text-gray-400 font-mono">{{ $entry->supplier_id }}</td>
                                        <td class="p-3">{{ $entry->supplier }}</td>
                                        <td class="p-3 text-right font-mono tabular-nums">{{ $entry->stock_in }}</td>
                                        <td class="p-2">
                                            <input type="text" name="description" form="ledger-desc-{{ $entry->id }}"
                                                   value="{{ $entry->description }}" placeholder="Add description"
                                                   class="w-full rounded-lg border-gray-300 h-10 text-xs shadow-sm">
                                        </td>
                                        <td class="p-3 text-gray-500 font-mono text-[11px] whitespace-nowrap">{{ optional($entry->stocked_in_date)->format('Y-m-d') }}</td>
                                        <td class="p-3 text-center">
                                            <div class="inline-flex items-center gap-2">
                                                <button type="submit" form="ledger-desc-{{ $entry->id }}"
                                                        class="px-3 py-1.5 rounded-lg bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold">Save</button>
                                                <form action="{{ route('admin.stock.ledger.destroy', $entry) }}" method="POST"
                                                      onsubmit="return confirm('Delete this ledger record?')" class="inline">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="px-3 py-1.5 rounded-lg bg-red-50 hover:bg-red-100 text-red-600 font-bold">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="p-6 text-center text-gray-400 font-medium italic">No stock transfer ledger records yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

</body>
</html>
