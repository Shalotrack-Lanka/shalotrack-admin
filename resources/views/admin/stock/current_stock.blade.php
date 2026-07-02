<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin - Current Stock</title>
    @vite(['resources/css/app.css','resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body x-data="{ sidebarOpen: false }">

<div class="flex h-screen overflow-hidden bg-gray-50">
    @include('partials.sidebars.admin')

    <div class="flex-1 flex flex-col overflow-y-auto">
        @include('partials.header')

        <main class="p-4 md:p-6 flex-1 space-y-6">
            
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden w-full">
                <div class="px-5 py-3 border-b border-gray-100 bg-gray-50">
                    <h3 class="font-bold text-gray-800 text-sm">Current Stock Filter Matrix</h3>
                </div>
                
                <div class="p-5 text-xs font-semibold text-gray-700">
                    <form method="GET" action="{{ route('admin.current-stock') }}" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                            
                            <div>
                                <label class="block mb-1.5 text-gray-600">Product Type</label>
                                <select name="product_type" class="w-full rounded-lg border-gray-300 h-9 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="">--Select Type--</option>
                                    @foreach($productTypes as $type)
                                        <option value="{{ $type }}" {{ request('product_type') == $type ? 'selected' : '' }}>{{ $type }}</option>
                                    @endforeach
                                    <option value="Device" {{ request('product_type') == 'Device' ? 'selected' : '' }}>Device</option>
                                    <option value="SIM" {{ request('product_type') == 'SIM' ? 'selected' : '' }}>SIM</option>
                                </select>
                            </div>

                            <div>
                                <label class="block mb-1.5 text-gray-600">Product Model / Name</label>
                                <input type="text" name="product_model" value="{{ request('product_model') }}" placeholder="Search model name..." class="w-full rounded-lg border-gray-300 h-9 text-xs shadow-sm">
                            </div>

                            <div class="flex gap-2">
                                <a href="{{ route('admin.current-stock') }}" class="w-1/2 bg-gray-100 hover:bg-gray-200 text-gray-700 h-9 rounded font-bold shadow-xs transition flex items-center justify-center">Reset</a>
                                <button type="submit" class="w-1/2 bg-[#17a2b8] hover:bg-[#138496] text-white h-9 rounded font-bold shadow-sm transition flex items-center justify-center gap-1">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                    Search
                                </button>
                            </div>

                        </div>
                    </form>
                </div>
            </div>

            <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden w-full">
                <div class="p-5">
                    <div class="border border-gray-200 rounded-xl overflow-hidden shadow-sm overflow-x-auto">
                        <table class="w-full text-left border-collapse min-w-[650px]">
                            <thead class="bg-gray-50 border-b border-gray-200 text-[11px] text-gray-600 uppercase tracking-wider">
                                <tr>
                                    <th class="p-3 w-12 text-center">#</th>
                                    <th class="p-3">Product Model / Type</th>
                                    <th class="p-3">Product Display Name</th>
                                    <th class="p-3 text-center w-36">Qty Available</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white text-xs font-semibold text-gray-700">
                                @forelse($stocks as $index => $stock)
                                    <tr class="hover:bg-gray-50/70 transition">
                                        <td class="p-3 text-center text-gray-400 font-bold">{{ $index + 1 }}.</td>
                                        <td class="p-3 text-gray-500 font-mono">{{ $stock->product_model }}</td>
                                        <td class="p-3 text-gray-900 font-bold">{{ $stock->product_name }}</td>
                                        <td class="p-3 text-center">
                                            <span class="px-2.5 py-1 rounded-md text-xs font-bold {{ $stock->company_available_stock > 10 ? 'text-green-700 bg-green-50' : 'text-red-700 bg-red-50' }}">
                                                {{ $stock->company_available_stock }}
                                            </span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="p-6 text-center text-gray-400 font-medium italic">No dynamic records found inside matching criteria.</td>
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

</body>
</html>