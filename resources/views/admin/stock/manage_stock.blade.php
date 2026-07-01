<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin - Manage Stock</title>
    @vite(['resources/css/app.css','resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body x-data="{ sidebarOpen: false }">

<div class="flex h-screen overflow-hidden bg-gray-50">
    @include('partials.sidebars.admin')

    <div class="flex-1 flex flex-col overflow-y-auto">
        @include('partials.header')

<main class="p-4 md:p-6 flex-1" x-data="{ 
    isEdit: false,
    actionUrl: '', formMethod: 'POST',
    stockData: { id: '', product_name: '', product_model: '', stock_in: 0, company_available_stock: 0, dealer_available_stock: 0, sold_to_customer: 0 },
    
    setEditMode(stock) {
        this.isEdit = true;
        this.actionUrl = '/admin/stock/manage-stock/' + stock.id; 
        this.formMethod = 'POST';
        this.stockData = { ...stock };
    },
    resetForm() {
        this.isEdit = false;
        this.actionUrl = ''; this.stockData = { id: '', product_name: '', product_model: '', stock_in: 0, company_available_stock: 0, dealer_available_stock: 0, sold_to_customer: 0 };
    }
}">
            
            @if(session('success'))
                <div class="mb-4 p-3 bg-green-50 text-green-700 border border-green-200 rounded-lg text-xs font-bold flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    {{ session('success') }}
                </div>
            @endif

            <div class="grid grid-cols-1 xl:grid-cols-12 gap-6 items-start w-full">
                
                <div class="xl:col-span-4 bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 flex justify-between items-center">
                        <span class="font-bold text-gray-800 text-sm" x-text="isEdit ? 'Edit Product Stock' : 'Add New Stock Record'"></span>
                        <button type="button" x-show="isEdit" @click="resetForm()" class="text-xs text-red-500 hover:underline font-semibold">Cancel Edit</button>
                    </div>
                    
                    <div class="p-5 text-xs font-semibold text-gray-700">
                        <form :action="actionUrl ? actionUrl : '{{ route('admin.stock.store') }}'" method="POST" class="space-y-4">
                            @csrf
                            <template x-if="isEdit">
                                <input type="hidden" name="_method" value="PUT">
                            </template>

                            <div>
                                <label class="block mb-1">Product Name</label>
                                <input type="text" name="product_name" x-model="stockData.product_name" required class="w-full rounded-lg border-gray-300 h-9 text-xs shadow-sm">
                            </div>

                            <div>
                                <label class="block mb-1">Product Model</label>
                                <input type="text" name="product_model" x-model="stockData.product_model" required class="w-full rounded-lg border-gray-300 h-9 text-xs shadow-sm">
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block mb-1">Stock In</label>
                                    <input type="number" name="stock_in" x-model.number="stockData.stock_in" required class="w-full rounded-lg border-gray-300 h-9 text-xs shadow-sm text-center">
                                </div>
                                <div>
                                    <label class="block mb-1">Company Avail.</label>
                                    <input type="number" name="company_available_stock" x-model.number="stockData.company_available_stock" required class="w-full rounded-lg border-gray-300 h-9 text-xs shadow-sm text-center">
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block mb-1">Dealer Avail.</label>
                                    <input type="number" name="dealer_available_stock" x-model.number="stockData.dealer_available_stock" required class="w-full rounded-lg border-gray-300 h-9 text-xs shadow-sm text-center">
                                </div>
                                <div>
                                    <label class="block mb-1">Sold To Customer</label>
                                    <input type="number" name="sold_to_customer" x-model.number="stockData.sold_to_customer" required class="w-full rounded-lg border-gray-300 h-9 text-xs shadow-sm text-center">
                                </div>
                            </div>

                            <div class="flex gap-2 pt-2">
                                <button type="button" @click="resetForm()" class="w-1/3 bg-gray-100 hover:bg-gray-200 text-gray-700 py-2 rounded font-bold transition">Reset</button>
                                <button type="submit" class="w-2/3 bg-[#17a2b8] hover:bg-[#138496] text-white py-2 rounded font-bold shadow-sm transition" x-text="isEdit ? 'Save Changes' : 'Submit Stock'"></button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="xl:col-span-8 bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50">
                        <h3 class="font-bold text-gray-800 text-sm">Stock Summary</h3>
                    </div>
                    <div class="p-5">
                        <div class="border border-gray-200 rounded-xl overflow-hidden shadow-sm overflow-x-auto">
                            <table class="w-full text-left border-collapse min-w-[750px]">
                                <thead class="bg-gray-50 border-b border-gray-200 text-[11px] text-gray-600 uppercase tracking-wider">
                                    <tr>
                                        <th class="p-3 w-10 text-center">#</th>
                                        <th class="p-3">Product Name</th>
                                        <th class="p-3">Product Model</th>
                                        <th class="p-3 text-center">Stock In</th>
                                        <th class="p-3 text-center">Company Avail.</th>
                                        <th class="p-3 text-center">Dealer Avail.</th>
                                        <th class="p-3 text-center">Sold To Cust.</th>
                                        <th class="p-3 text-center w-36">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white text-xs font-semibold text-gray-700">
                                    @forelse($stocks as $index => $stock)
                                        <tr class="hover:bg-gray-50/70 transition">
                                            <td class="p-3 text-center text-gray-400 font-bold">{{ $index + 1 }}.</td>
                                            <td class="p-3 text-gray-900 font-bold">{{ $stock->product_name }}</td>
                                            <td class="p-3 text-gray-500">{{ $stock->product_model }}</td>
                                            <td class="p-3 text-center bg-gray-50/30 font-bold">{{ $stock->stock_in }}</td>
                                            <td class="p-3 text-center text-blue-600">{{ $stock->company_available_stock }}</td>
                                            <td class="p-3 text-center text-amber-600">{{ $stock->dealer_available_stock }}</td>
                                            <td class="p-3 text-center text-green-600">{{ $stock->sold_to_customer }}</td>
                                            <td class="p-3 text-center space-x-1 flex items-center justify-center h-full mt-0.5">
                                                <button type="button" @click="setEditMode({{ json_encode($stock) }})" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-2 py-1 rounded text-[10px] font-bold shadow-xs transition flex items-center gap-1">
                                                    Edit
                                                </button>
                                                <a href="{{ route('admin.stock.download', $stock->id) }}" class="bg-[#17a2b8] hover:bg-[#138496] text-white px-2 py-1 rounded text-[10px] font-bold shadow-xs transition flex items-center gap-1">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                                    Download
                                                </a>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="8" class="p-6 text-center text-gray-400 font-medium italic">No active stock records listed inside system matrix.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </div>
</div>

</body>
</html>