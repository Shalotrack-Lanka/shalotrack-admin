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

            <!-- ADD RAW DEVICES: full-width row, fields laid out horizontally -->
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden w-full"
                 x-data="{
                     deviceTypeId: '',
                     supplierId: '',
                     stockIn: 0,
                     companyAvail: 0,
                     get total() { return (Number(this.stockIn) || 0) + (Number(this.companyAvail) || 0); },
                     reset() { this.deviceTypeId = ''; this.supplierId = ''; this.stockIn = 0; this.companyAvail = 0; }
                 }">
                <div class="px-5 py-3 border-b border-gray-100 bg-gray-50">
                    <span class="font-bold text-gray-800 text-sm">Add Raw Devices</span>
                </div>

                <div class="p-5 text-xs font-semibold text-gray-700">
                    <form action="{{ route('admin.stock.store') }}" method="POST"
                          class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-4 items-end">
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
                                <button type="button" @click="stockIn--" class="w-10 h-10 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold shrink-0">&minus;</button>
                                <input type="number" name="stock_in" x-model.number="stockIn" required
                                       class="w-full rounded-lg border-gray-300 h-10 text-xs shadow-sm text-center">
                                <button type="button" @click="stockIn++" class="w-10 h-10 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold shrink-0">+</button>
                            </div>
                        </div>

                        <div>
                            <label class="block mb-1">Company Avail.</label>
                            <div class="flex items-center gap-2">
                                <button type="button" @click="companyAvail--" class="w-10 h-10 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold shrink-0">&minus;</button>
                                <input type="number" name="company_available_stock" x-model.number="companyAvail" required
                                       class="w-full rounded-lg border-gray-300 h-10 text-xs shadow-sm text-center">
                                <button type="button" @click="companyAvail++" class="w-10 h-10 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold shrink-0">+</button>
                            </div>
                        </div>

                        <div class="p-2.5 rounded-lg bg-gray-50 border border-gray-200 flex justify-between items-center h-10">
                            <span class="text-gray-600">Total Available</span>
                            <span class="font-bold text-gray-900 text-sm" x-text="total"></span>
                        </div>

                        <div class="md:col-span-2 xl:col-span-5 flex gap-2 justify-end pt-2 border-t border-gray-100 mt-2">
                            <button type="button" @click="reset()" class="px-6 bg-gray-100 hover:bg-gray-200 text-gray-700 py-2.5 rounded font-bold transition">Reset</button>
                            <button type="submit" class="px-8 bg-[#17a2b8] hover:bg-[#138496] text-white py-2.5 rounded font-bold shadow-sm transition">Add Stock</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- RECORDS: full-width, below the form, no width ceiling -->
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden w-full"
                 x-data="{
                     rows: {{ \Illuminate\Support\Js::from($stockRows) }},
                     deletedIds: [],
                     rowTotal(row) { return (Number(row.stock_in) || 0) + (Number(row.company_available_stock) || 0); },
                     moveUp(index) {
                         if (index === 0) return;
                         [this.rows[index - 1], this.rows[index]] = [this.rows[index], this.rows[index - 1]];
                     },
                     moveDown(index) {
                         if (index === this.rows.length - 1) return;
                         [this.rows[index + 1], this.rows[index]] = [this.rows[index], this.rows[index + 1]];
                     },
                     removeRow(index) {
                         this.deletedIds.push(this.rows[index].id);
                         this.rows.splice(index, 1);
                     }
                 }">
                <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 flex justify-between items-center sticky top-0 z-10">
                    <h3 class="font-bold text-gray-800 text-sm">Raw Stock Records</h3>
                    <button type="submit" form="bulkStockForm" class="bg-[#17a2b8] hover:bg-[#138496] text-white px-5 py-2 rounded text-xs font-bold shadow-sm transition">
                        Save Changes
                    </button>
                </div>

                <form id="bulkStockForm" action="{{ route('admin.stock.bulk-update') }}" method="POST">
                    @csrf
                    @method('PUT')

                    <template x-for="id in deletedIds" :key="'del-' + id">
                        <input type="hidden" name="deleted_ids[]" :value="id">
                    </template>

                    <div class="p-5">
                        <!--
                            table-fixed + colgroup instead of min-w on each <th>.
                            Under the default table-layout:auto, min-w on a cell is
                            only a suggestion — the browser will still shrink columns
                            to fit the container instead of scrolling, which is why
                            Supplier was clipping to "am" / "gsg" before. table-fixed
                            makes these widths a hard contract the browser can't
                            renegotiate, and that's what makes overflow-x-auto below
                            actually kick in once the row gets wider than the screen.
                        -->
                        <div class="border border-gray-200 rounded-xl shadow-sm overflow-x-auto">
                            <table class="min-w-[1400px] w-[1400px] text-left border-collapse table-fixed">
                                <colgroup>
                                    <col style="width:60px">
                                    <col style="width:260px">
                                    <col style="width:260px">
                                    <col style="width:90px">
                                    <col style="width:110px">
                                    <col style="width:120px">
                                    <col style="width:280px">
                                    <col style="width:160px">
                                    <col style="width:60px">
                                </colgroup>
                                <thead class="bg-gray-50 border-b border-gray-200 text-[11px] text-gray-600 uppercase tracking-wider whitespace-nowrap">
                                    <tr>
                                        <th class="p-3 text-center">#</th>
                                        <th class="p-3">Device Category / Type</th>
                                        <th class="p-3">Supplier</th>
                                        <th class="p-3 text-right">Stock In</th>
                                        <th class="p-3 text-right">Company Avail.</th>
                                        <th class="p-3 text-right">Total Available</th>
                                        <th class="p-3">Description</th>
                                        <th class="p-3">Last Edited</th>
                                        <th class="p-3 text-center">Del</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white text-xs font-semibold text-gray-700">
                                    <template x-for="(row, index) in rows" :key="row.id">
                                        <tr class="hover:bg-gray-50/70 transition"
                                            :class="row.is_superseded ? 'line-through decoration-red-300 text-red-300 bg-red-50/40 hover:bg-red-50/60' : ''">

                                            <td class="p-3 text-center align-middle">
                                                <input type="hidden" :name="'rows[' + index + '][id]'" :value="row.id">
                                                <div class="flex flex-col items-center gap-1" x-show="row.is_superseded">
                                                    <button type="button" @click="moveUp(index)" class="w-7 h-6 rounded bg-gray-100 hover:bg-gray-200 text-gray-600 text-xs leading-none">&#9650;</button>
                                                    <button type="button" @click="moveDown(index)" class="w-7 h-6 rounded bg-gray-100 hover:bg-gray-200 text-gray-600 text-xs leading-none">&#9660;</button>
                                                </div>
                                                <span class="text-gray-400 font-bold" x-show="!row.is_superseded" x-text="index + 1"></span>
                                            </td>

                                            <td class="p-2">
                                                <select :name="'rows[' + index + '][device_type_id]'" x-model.number="row.device_type_id"
                                                        class="w-full rounded-lg border-gray-300 h-10 text-xs shadow-sm">
                                                    @foreach($deviceTypes as $type)
                                                        <option value="{{ $type->id }}">{{ $type->device_category }} with {{ $type->model }}</option>
                                                    @endforeach
                                                </select>
                                            </td>

                                            <td class="p-2">
                                                <select :name="'rows[' + index + '][supplier_id]'" x-model.number="row.supplier_id"
                                                        class="w-full rounded-lg border-gray-300 h-10 text-xs shadow-sm px-2">
                                                    @foreach($suppliers as $supplier)
                                                        <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                                    @endforeach
                                                </select>
                                            </td>

                                            <td class="p-2 bg-slate-50/70">
                                                <input type="number" :name="'rows[' + index + '][stock_in]'" x-model.number="row.stock_in"
                                                       class="w-full rounded-lg border-gray-300 h-10 text-xs shadow-sm text-right font-mono tabular-nums px-3">
                                            </td>

                                            <td class="p-2 bg-slate-50/70">
                                                <input type="number" :name="'rows[' + index + '][company_available_stock]'" x-model.number="row.company_available_stock"
                                                       class="w-full rounded-lg border-gray-300 h-10 text-xs shadow-sm text-right font-mono tabular-nums px-3">
                                            </td>

                                            <td class="p-3 text-right font-bold text-green-600 text-sm font-mono tabular-nums bg-slate-50/70" x-text="rowTotal(row)"></td>

                                            <td class="p-2">
                                                <input type="text" :name="'rows[' + index + '][description]'" x-model="row.description"
                                                       placeholder="—"
                                                       class="w-full rounded-lg border-gray-300 h-10 text-xs shadow-sm">
                                            </td>

                                            <td class="p-3 text-gray-400 font-mono text-[11px] whitespace-nowrap" x-text="row.updated_at"></td>

                                            <td class="p-3 text-center">
                                                <input type="hidden" :name="'rows[' + index + '][sort_order]'" :value="rows.length - index">
                                                <button type="button" @click="removeRow(index)"
                                                        class="w-8 h-8 rounded-lg bg-red-50 hover:bg-red-100 text-red-600 font-bold">&times;</button>
                                            </td>
                                        </tr>
                                    </template>

                                    <tr x-show="rows.length === 0">
                                        <td colspan="9" class="p-6 text-center text-gray-400 font-medium italic">No raw stock records yet.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </form>
            </div>
        </main>
    </div>
</div>

</body>
</html>