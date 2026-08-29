@extends('layouts.dealer')

@section('title', 'Dealer Dashboard')

@section('content')

@if(!$dealer)

    {{-- Success Alert Message --}}
    @if(session('success'))
        <div x-data="{ show: true }" 
            x-show="show" 
            x-init="setTimeout(() => show = false, 4000)" 
            class="mb-6 p-4 bg-green-100 border border-green-300 text-green-800 rounded-2xl flex justify-between items-center shadow-sm">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <span class="font-bold text-sm">{{ session('success') }}</span>
            </div>
            <button @click="show = false" class="text-green-600 hover:text-green-800 font-bold">&times;</button>
        </div>
    @endif

    {{-- ============================================================
         NO DEALER ACCOUNT LINKED
    ============================================================ --}}
    <div class="max-w-4xl mx-auto mt-10">
        <div class="bg-red-50 border border-red-200 rounded-3xl p-8 shadow-sm">
            <h2 class="text-xl font-black text-red-700 mb-3">
                Dealer Account Not Linked
            </h2>
            <p class="text-sm text-red-600 leading-relaxed">
                Your login account is not linked to a dealer profile.
                Please contact the system administrator.
            </p>
        </div>
    </div>

@else

<div class="max-w-7xl mx-auto space-y-8">

    {{-- Success Alert Message --}}
    @if(session('success'))
        <div x-data="{ show: true }" 
            x-show="show" 
            x-init="setTimeout(() => show = false, 4000)" 
            class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl flex justify-between items-center shadow-sm">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <span class="font-bold text-sm">{{ session('success') }}</span>
            </div>
            <button @click="show = false" class="text-emerald-600 hover:text-emerald-800 font-bold">&times;</button>
        </div>
    @endif

    {{-- ============================================================
         WELCOME SECTION & ADD CUSTOMER MODAL
    ============================================================ --}}
    <div x-data="{ 
            isAddCustomerOpen: {{ $errors->any() ? 'true' : 'false' }},
            deviceCount: {{ old('no_of_devices', 1) }}
         }" 
         class="flex flex-col md:flex-row md:justify-between md:items-center gap-4 bg-gradient-to-r from-blue-950 via-slate-900 to-blue-900 p-8 rounded-3xl text-white shadow-xl relative overflow-hidden">
        
        <!-- Decorative Background Circle -->
        <div class="absolute -right-10 -bottom-10 w-48 h-48 bg-blue-500/10 rounded-full blur-2xl"></div>

        <div class="relative z-10">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-500/20 text-blue-300 text-xs font-semibold mb-3 border border-blue-400/20">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                Dealer Portal Active
            </div>
            <h1 class="text-3xl font-black tracking-tight">
                Welcome back, {{ $dealer->full_name ?? Auth::user()->full_name }} 👋
            </h1>
            <p class="text-slate-300 text-sm mt-1">
                Manage your customer requests, device allocations, and performance metrics from one place.
            </p>
        </div>

        <!-- Add Customer Button -->
        <button @click="isAddCustomerOpen = true" 
                class="relative z-10 bg-blue-600 hover:bg-blue-500 text-white font-bold py-3.5 px-7 rounded-2xl shadow-lg hover:shadow-blue-500/25 transition duration-200 flex items-center justify-center gap-2.5 group shrink-0">
            <div class="bg-white/20 p-1 rounded-lg group-hover:scale-110 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path>
                </svg>
            </div>
            <span>Add Customer</span>
        </button>

        <!-- Alpine.js Modal Background -->
        <div x-show="isAddCustomerOpen" 
             x-cloak
             style="display: none;"
             class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 backdrop-blur-md px-4">
            
            <!-- Modal Content -->
            <div @click.away="isAddCustomerOpen = false" 
                 x-transition
                 class="bg-white text-slate-800 rounded-3xl shadow-2xl w-full max-w-lg overflow-hidden max-h-[90vh] flex flex-col border border-slate-100">
                 
                <!-- Header -->
                <div class="bg-blue-950 px-6 py-4 flex justify-between items-center shrink-0 text-white">
                    <div>
                        <h3 class="font-bold text-lg">Add New Customer</h3>
                        <p class="text-xs text-blue-200">Submit new customer lead and device details</p>
                    </div>
                    <button type="button" @click="isAddCustomerOpen = false" class="text-slate-300 hover:text-white transition">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>

                <!-- Form -->
                <form action="{{ route('dealer.customer-ad.store') }}" method="POST" class="p-6 overflow-y-auto space-y-4">
                    @csrf
                    
                    {{-- Global Validation Errors Alert Box --}}
                    @if($errors->any())
                        <div class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-2xl text-xs font-semibold space-y-1">
                            <p class="font-bold text-sm mb-1 text-red-800">Please fix the following errors:</p>
                            @foreach ($errors->all() as $error)
                                <p>• {{ $error }}</p>
                            @endforeach
                        </div>
                    @endif

                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-1">Customer Name *</label>
                        <input type="text" name="name" value="{{ old('name') }}" required 
                               class="w-full border-slate-300 rounded-xl focus:border-blue-500 focus:ring focus:ring-blue-200 text-sm">
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-1">Contact No (10 Digits) *</label>
                            <input type="text" name="contact" value="{{ old('contact') }}" maxlength="10" required 
                                   placeholder="07XXXXXXXX"
                                   class="w-full border-slate-300 rounded-xl focus:border-blue-500 focus:ring focus:ring-blue-200 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-1">NIC / ID</label>
                            <input type="text" name="nic_or_id" value="{{ old('nic_or_id') }}" 
                                   class="w-full border-slate-300 rounded-xl focus:border-blue-500 focus:ring focus:ring-blue-200 text-sm">
                        </div>
                    </div>

                    <!-- Devices Count -->
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-1">No of Devices Required *</label>
                        <input type="number" name="no_of_devices" x-model.number="deviceCount" min="1" max="50" required 
                               class="w-full border-slate-300 rounded-xl focus:border-blue-500 focus:ring focus:ring-blue-200 text-sm">
                    </div>

                    <!-- Dynamic IMEI Inputs -->
                    <div x-show="deviceCount > 0" class="space-y-3 bg-slate-50 p-4 rounded-2xl border border-slate-200">
                        <label class="block text-xs font-extrabold uppercase text-slate-500 tracking-wider">
                            Enter IMEI Numbers (15 Digits Each) *
                        </label>

                        <template x-for="i in deviceCount" :key="i">
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-bold text-slate-400 min-w-[50px]" x-text="'IMEI ' + i + ':'"></span>
                                <input type="text" 
                                       name="imei_numbers[]" 
                                       maxlength="15"
                                       required
                                       :placeholder="'Enter 15-digit IMEI ' + i" 
                                       class="w-full text-xs font-mono border-slate-300 rounded-lg focus:border-blue-500 focus:ring focus:ring-blue-200">
                            </div>
                        </template>
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-1">Address</label>
                        <textarea name="address" rows="2" class="w-full border-slate-300 rounded-xl focus:border-blue-500 focus:ring focus:ring-blue-200 text-sm">{{ old('address') }}</textarea>
                    </div>

                    <!-- Footer Buttons -->
                    <div class="pt-4 flex justify-end gap-3 border-t border-slate-100">
                        <button type="button" @click="isAddCustomerOpen = false" class="px-5 py-2.5 rounded-xl font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 transition text-sm">
                            Cancel
                        </button>
                        <button type="submit" class="px-5 py-2.5 rounded-xl font-bold text-white bg-blue-600 hover:bg-blue-700 transition text-sm shadow-md">
                            Save Customer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- ============================================================
         SUMMARY METRICS CARDS
    ============================================================ --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">

                <!-- Total Received Stock Card -->
        <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm hover:shadow-md transition relative overflow-hidden group">
            <div class="flex justify-between items-start">
                <div>
                    <div class="text-xs text-slate-500 uppercase font-bold tracking-wider">Total Received Stock</div>

                    <!-- shows totalDevicesCount -->
                    <div class="text-4xl font-black text-blue-950 mt-3">{{ $myStockCount ?? 0 }}</div>
                    
                    <div class="text-xs text-emerald-600 font-semibold mt-2 flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        <span>Total Customer Devices</span>
                    </div>
                </div>
                <div class="p-3 bg-blue-50 text-blue-600 rounded-2xl group-hover:scale-110 transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                    </svg>
                </div>
            </div>
        </div>


        {{-- Total Customers Card --}}
        <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm hover:shadow-md transition relative overflow-hidden group">
            <div class="flex justify-between items-start">
                <div>
                    <div class="text-xs text-slate-500 uppercase font-bold tracking-wider">Total Customers</div>
                    <div class="text-4xl font-black text-blue-950 mt-3">{{ $totalCustomersCount ?? 0 }}</div>
                    <div class="text-xs text-emerald-600 font-semibold mt-2 flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path></svg>
                        <span>Active Lead Records</span>
                    </div>
                </div>
                <div class="p-3 bg-blue-50 text-blue-600 rounded-2xl group-hover:scale-110 transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                </div>
            </div>
        </div>

        {{-- Pending Dispatch / Stock Card --}}
        <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm hover:shadow-md transition relative overflow-hidden group">
            <div class="flex justify-between items-start">
                <div>
                    <div class="text-xs text-slate-500 uppercase font-bold tracking-wider">Device Allocation</div>
                    <div class="text-4xl font-black text-blue-950 mt-3">Ready</div>
                    <div class="text-xs text-slate-400 mt-2">IMEI Validation Active</div>
                </div>
                <div class="p-3 bg-amber-50 text-amber-600 rounded-2xl group-hover:scale-110 transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path></svg>
                </div>
            </div>
        </div>

        {{-- Commission Rate Highlight --}}
        <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm hover:shadow-md transition relative overflow-hidden group">
            <div class="flex justify-between items-start">
                <div>
                    <div class="text-xs text-slate-500 uppercase font-bold tracking-wider">Commission Tier</div>
                    <div class="text-4xl font-black text-emerald-600 mt-3">Tier 1</div>
                    <div class="text-xs text-emerald-600 font-semibold mt-2">Bonus per bulk device</div>
                </div>
                <div class="p-3 bg-emerald-50 text-emerald-600 rounded-2xl group-hover:scale-110 transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
            </div>
        </div>

        {{-- Total Commission Card --}}
<div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm hover:shadow-md transition relative overflow-hidden group">
    <div class="flex justify-between items-start">
        <div>
            <div class="text-xs text-slate-500 uppercase font-bold tracking-wider">Earned Commission</div>
            <div class="text-3xl font-black text-emerald-600 mt-2">
                LKR {{ number_format($totalCommission ?? 0) }}
            </div>
            <div class="text-[11px] text-slate-500 font-semibold mt-2 flex items-center gap-1">
                <span>Rate: LKR {{ number_format($ratePerDevice ?? 1000) }} / Device</span>
            </div>
        </div>
        <div class="p-3 bg-emerald-50 text-emerald-600 rounded-2xl group-hover:scale-110 transition">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
        </div>
    </div>
</div>

    </div>

        {{-- ============================================================
            MY ALLOCATED STOCKS / DEVICES TABLE
        ============================================================ --}}
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                <div>
                    <h3 class="font-black text-slate-900 text-lg">My Allocated Stocks</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Devices transferred to your dealer profile by Admin</p>
                </div>
                <span class="px-3 py-1 rounded-full bg-blue-50 text-blue-700 text-xs font-bold border border-blue-100">
                    Total: {{ $allocatedDevices->count() }}
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-sm">
                    <thead class="bg-slate-50 text-slate-500 font-bold text-xs uppercase tracking-wider border-b border-slate-100">
                        <tr>
                            <th class="p-4">Device Category</th>
                            <th class="p-4">IMEI Number</th>
                            <th class="p-4">SIM Number</th>
                            <th class="p-4">Status</th>
                            <th class="p-4">Allocated Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium text-slate-700">
                        @forelse($allocatedDevices as $device)
                            <tr class="hover:bg-slate-50/80 transition">
                                <td class="p-4 font-bold text-slate-900">
                                    {{ $device->device_category }}
                                </td>
                                <td class="p-4 font-mono text-xs text-blue-600 font-bold">
                                    {{ $device->imei_number }}
                                </td>
                                <td class="p-4 font-mono text-xs">
                                    {{ $device->sim_number ?? 'N/A' }}
                                </td>
                                <td class="p-4">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                        {{ $device->status }}
                                    </span>
                                </td>
                                <td class="p-4 text-xs text-slate-500">
                                    {{ $device->allocated_at ? \Carbon\Carbon::parse($device->allocated_at)->format('d M Y, h:i A') : 'N/A' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="p-8 text-center text-slate-400 italic">
                                    No stock devices allocated to your profile yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        
    </div>


</div>

@endif

@endsection