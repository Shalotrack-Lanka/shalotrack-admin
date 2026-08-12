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
         QUICK FEATURES & ANNOUNCEMENTS SECTION (Viva Highlights)
    ============================================================ --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Feature 1: System SLA Notice --}}
        <div class="bg-gradient-to-br from-blue-900 to-indigo-950 p-6 rounded-3xl text-white shadow-md flex flex-col justify-between">
            <div>
                <div class="w-10 h-10 rounded-xl bg-blue-500/20 flex items-center justify-center text-blue-300 mb-4 border border-blue-400/20">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                </div>
                <h3 class="font-bold text-lg">Instant Customer Registration</h3>
                <p class="text-xs text-slate-300 leading-relaxed mt-2">
                    Enter customer specifications along with 15-digit unique IMEI numbers. Multi-device requests automatically consolidate per customer record.
                </p>
            </div>
            <div class="mt-6 pt-4 border-t border-blue-800/60 flex justify-between items-center text-xs text-blue-300 font-semibold">
                <span>ShaloTrack Dealer Tool</span>
                <span class="text-emerald-400 font-bold">● Active</span>
            </div>
        </div>

        {{-- Feature 2: Commission Guidelines --}}
        <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm flex flex-col justify-between">
            <div>
                <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center text-emerald-600 mb-4 border border-emerald-100">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
                <h3 class="font-bold text-slate-800 text-base">Dealer Commission Policy</h3>
                <p class="text-xs text-slate-500 leading-relaxed mt-2">
                    Higher device counts per customer lead increase dealer commission yield. Make sure to assign valid IMEI digits for instant system verification.
                </p>
            </div>
            <div class="mt-6 pt-4 border-t border-slate-100 flex items-center justify-between text-xs text-slate-400">
                <span>Policy Version 2.0</span>
                <span class="font-bold text-blue-950">Dealer Terms Applied</span>
            </div>
        </div>

        {{-- Feature 3: Quick Navigation Widget --}}
        <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm flex flex-col justify-between">
            <div>
                <div class="w-10 h-10 rounded-xl bg-orange-50 flex items-center justify-center text-orange-600 mb-4 border border-orange-100">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                </div>
                <h3 class="font-bold text-slate-800 text-base">Quick Shortcuts</h3>
                <p class="text-xs text-slate-500 leading-relaxed mt-2">
                    Access your full customer inventory or view pending setup statuses easily using the navigation links.
                </p>
            </div>
            <div class="mt-6 pt-4 border-t border-slate-100">
                <a href="{{ route('dealer.customers.index') }}" class="inline-flex items-center gap-1.5 text-xs font-bold text-blue-600 hover:text-blue-800 transition">
                    <span>View Customer List</span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                </a>
            </div>
        </div>

    </div>

    {{-- ============================================================
         CUSTOMER / VEHICLE API INFORMATION
    ============================================================ --}}
    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200/80 rounded-3xl p-6 shadow-sm">
        <div class="flex items-start gap-4">
            <div class="w-11 h-11 bg-blue-950 text-white rounded-2xl flex items-center justify-center shrink-0 shadow-md">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <div>
                <h3 class="font-bold text-blue-950 text-base">Vehicles API Integration Status</h3>
                <p class="text-xs text-slate-600 leading-relaxed mt-1">
                    Vehicle Tracking and Live GPS Mapping modules are currently undergoing backend synchronization. Dealer-to-Vehicle allocation APIs will be enabled full telemetry setup.
                </p>
            </div>
        </div>
    </div>

</div>

@endif

@endsection