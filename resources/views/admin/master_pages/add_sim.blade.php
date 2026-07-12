<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin - Add SIM</title>

    @vite(['resources/css/app.css','resources/js/app.js'])

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body x-data="{ sidebarOpen: false }">

<div class="flex h-screen overflow-hidden">

    @include('partials.sidebars.admin')

    <div class="flex-1 flex flex-col overflow-y-auto">
        @include('partials.header')

        <main class="p-4 md:p-6 flex-1">
            @yield('content')

                <div class="lg:col-span-8 bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">Add New SIM </div>
                    <div class="p-5 text-xs font-semibold text-gray-700 space-y-4">

                        @if(session('success'))
                            <div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-xs font-bold">
                                {{ session('success') }}
                            </div>
                        @endif

                        @if ($errors->any())
                <div class="mb-4 p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg shadow-xs transition duration-300">
                    <div class="flex items-center gap-2 mb-2">
                        <svg class="w-4 h-4 text-red-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        <span class="text-xs font-bold uppercase tracking-wider">කරුණාකර මෙම වැරදීම් නිවැරදි කරන්න:</span>
                    </div>
                    
                    <ul class="list-disc pl-5 space-y-1 text-[11px] font-semibold">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

                        <form method="POST" action="{{ route('admin.stock.sim.store') }}" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @csrf
                            
                            <div>
                                <label class="block mb-1">SIM Type</label>
                                <select name="sim_type" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                    <option value="" selected disabled>--Select SIM Type--</option>
                                    <option value="dialog">Dialog Axiata</option>
                                    <option value="mobitel">Mobitel Sri Lanka</option>
                                </select>
                            </div>

                            <div>
                                <label class="block mb-1">SIM Number</label>
                                <input type="text" name="sim_number" maxlength="10" inputmode="numeric" pattern="\d{10}" placeholder="0771234567" class="w-full rounded-lg border-gray-300 h-10 shadow-sm">
                            </div>

                            <div>
                                <label class="block mb-1">IMEI Number</label>
                                <input type="text" name="imei_number" maxlength="15" pattern="\d{15}" inputmode="numeric" title="IMEI must be exactly 15 digits" class="w-full rounded-lg border-gray-300 h-10 shadow-sm">
                            </div>

                            <div>
                                <label class="block mb-1">SIM Status</label>
                                <select name="sim_status" required class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                    <option value="" selected disabled>--Select SIM Status--</option>
                                    <option value="Activated">Activated</option>
                                    <option value="Not Activated">Not Activated</option>
                                </select>
                            </div>

                            <div class="md:col-span-2 flex items-center gap-2 pt-2">
                                <input type="checkbox" name="testing_required" value="1" class="rounded border-gray-300 text-blue-600 w-4 h-4 shadow-sm">
                                <label>Activation / Network Testing Required</label>
                            </div>

                            <div class="md:col-span-2 flex gap-2 pt-2">
                                <button type="reset" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 py-2 rounded-lg font-bold shadow-sm transition">Reset</button>
                                <button type="submit" class="bg-[#17a2b8] hover:bg-[#138496] text-white px-5 py-2 rounded-lg font-bold shadow-sm transition">Save SIM Product</button>
                            </div>
                        </form>
                    </div>
                </div>
            {{-- ===================== NOT ACTIVATED SIMs ===================== --}}
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden w-full">
                    <div class="px-5 py-4 border-b border-gray-100 bg-gradient-to-r from-red-50 to-white flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-red-500"></span>
                            <h2 class="font-bold text-gray-800 text-sm tracking-wide">All / Not Activated SIMs</h2>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-[11px] font-bold text-red-600 bg-red-50 border border-red-200 px-2.5 py-1 rounded-full">
                            {{ $notActivatedSims->count() ?? 0 }} pending
                            </span>
                            {{-- In the Not Activated SIMs header --}}
                                <a href="{{ route('admin.sim.export-not-activated') }}"
                                class="px-3 py-1.5 rounded-lg border border-gray-300 text-gray-700 text-xs font-bold hover:bg-gray-100 flex items-center gap-1.5">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                    </svg>
                                    Generate Report
                                </a>
                            <button type="button" onclick="refreshCancelDevicePage(this)"
                                    class="px-3 py-1.5 rounded-lg border border-gray-300 text-gray-700 text-xs font-bold hover:bg-gray-100 flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5 refresh-icon transition-transform duration-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                                Refresh
                            </button>
                        </div>        
                    </div>
                    <div class="p-5">
                        <div class="border border-gray-200 rounded-xl overflow-x-auto text-xs font-semibold text-gray-700">
                            <table class="w-full text-left border-collapse">
                                <thead class="bg-gray-50 border-b border-gray-200">
                                    <tr>
                                        <th class="p-3">SIM Number</th>
                                        <th class="p-3">SIM Type</th>
                                        <th class="p-3">Provider</th>
                                        <th class="p-3">IMEI Number</th>
                                        <th class="p-3">SIM Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white">
                                    @forelse($notActivatedSims as $sim)
                                        <tr class="hover:bg-gray-50 transition">
                                            <td class="p-3">{{ $sim->sim_number }}</td>
                                            <td class="p-3">{{ $sim->sim_type }}</td>
                                            <td class="p-3">{{ $sim->provider }}</td>
                                            <td class="p-3">{{ $sim->imei_number ?? '-' }}</td>
                                            <td class="p-3">
                                                <form action="{{ route('admin.stock.sim.update-status', $sim->id) }}" method="POST" class="m-0">
                                                    @csrf
                                                    @method('PATCH')
                                                    <div class="relative inline-block w-36">
                                                        <select name="sim_status" 
                                                                onfocus="this.oldValue = this.value;"
                                                                onchange="if(confirm('Do you want to change SIM status to Active for {{ $sim->sim_number }}?')) { this.form.submit(); } else { this.value = this.oldValue; }" 
                                                                class="appearance-none w-full bg-red-50 border border-red-200 text-red-700 text-[11px] font-bold rounded-full px-3 py-1.5 pr-8 focus:outline-none focus:ring-2 focus:ring-red-500 cursor-pointer shadow-sm transition-colors hover:bg-red-100 text-center">
                                                            <option value="Not Activated" {{ ($sim->sim_status == 'Not Activated' || is_null($sim->sim_status)) ? 'selected' : '' }} class="font-bold text-red-600">Not Activated</option>
                                                            <option value="Activated" class="font-bold text-green-600">Activated</option>
                                                        </select>
                                                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-red-700">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                                                        </div>
                                                    </div>
                                                </form>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="p-6 text-center text-gray-400">No Pending SIMs Found.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
 
                {{-- ===================== ACTIVATED SIMs ===================== --}}
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden w-full">
                    <div class="px-5 py-4 border-b border-gray-100 bg-gradient-to-r from-green-50 to-white flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-green-500"></span>
                            <h2 class="font-bold text-gray-800 text-sm tracking-wide">Activated SIMs List</h2>
                        </div>
                        
                        <div class="flex items-center gap-2">
                            <span class="text-[11px] font-bold text-green-600 bg-green-50 border border-green-200 px-2.5 py-1 rounded-full">
                            {{ $activatedSims->count() ?? 0 }} active
                        </span>
                            <button type="button" onclick="refreshCancelDevicePage(this)"
                                    class="px-3 py-1.5 rounded-lg border border-gray-300 text-gray-700 text-xs font-bold hover:bg-gray-100 flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5 refresh-icon transition-transform duration-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                                Refresh
                            </button>
                        </div>
                    </div>
                    <div class="p-5">
                        <div class="border border-gray-200 rounded-xl overflow-x-auto text-xs font-semibold text-gray-700">
                            <table class="w-full text-left border-collapse">
                                <thead class="bg-gray-50 border-b border-gray-200">
                                    <tr>
                                        <th class="p-3">SIM Number</th>
                                        <th class="p-3">SIM Type</th>
                                        <th class="p-3">Provider</th>
                                        <th class="p-3">IMEI Number</th>
                                        <th class="p-3">SIM Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white">
                                    @forelse($activatedSims as $sim)
                                        <tr class="hover:bg-gray-50 transition">
                                            <td class="p-3">{{ $sim->sim_number }}</td>
                                            <td class="p-3">{{ $sim->sim_type }}</td>
                                            <td class="p-3">{{ $sim->provider }}</td>
                                            <td class="p-3">{{ $sim->imei_number ?? '-' }}</td>
                                            <td class="p-3">
                                                <form action="{{ route('admin.stock.sim.update-status', $sim->id) }}" method="POST" class="m-0">
                                                    @csrf
                                                    @method('PATCH')
                                                    <div class="relative inline-block w-36">
                                                        <select name="sim_status" 
                                                                onfocus="this.oldValue = this.value;"
                                                                onchange="if(confirm('Do you want to change SIM status to Not Activated for {{ $sim->sim_number }}?')) { this.form.submit(); } else { this.value = this.oldValue; }" 
                                                                class="appearance-none w-full bg-green-50 border border-green-200 text-green-700 text-[11px] font-bold rounded-full px-3 py-1.5 pr-8 focus:outline-none focus:ring-2 focus:ring-green-500 cursor-pointer shadow-sm transition-colors hover:bg-green-100 text-center">
                                                            <option value="Not Activated" class="font-bold text-red-600">Not Activated</option>
                                                            <option value="Activated" {{ $sim->sim_status == 'Activated' ? 'selected' : '' }} class="font-bold text-green-600">Activated</option>
                                                        </select>
                                                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-green-700">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                                                        </div>
                                                    </div>
                                                </form>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="p-6 text-center text-gray-400">No Activated SIMs Yet.</td>
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

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>
</body>
</html>