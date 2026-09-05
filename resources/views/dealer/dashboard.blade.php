@extends('layouts.dealer')

@section('title', 'Dealer Dashboard')

@section('content')

@if(!$dealer)

    <div class="max-w-4xl mx-auto mt-10">
        <div class="bg-red-50 border border-red-200 rounded-3xl p-8 shadow-sm">
            <h2 class="text-xl font-black text-red-700 mb-3">Dealer Account Not Linked</h2>
            <p class="text-sm text-red-600 leading-relaxed">Your login account is not linked to a dealer profile. Please contact the administrator.</p>
        </div>
    </div>

@else

<div class="max-w-7xl mx-auto space-y-8">

    {{-- Success Alert Message --}}
    @if(session('success'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)" 
            class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl flex justify-between items-center shadow-sm">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                <span class="font-bold text-sm">{{ session('success') }}</span>
            </div>
            <button @click="show = false" class="text-emerald-600 hover:text-emerald-800 font-bold">&times;</button>
        </div>
    @endif

    {{-- Validation Errors Display --}}
    @if($errors->has('assign'))
        <div x-data="{ show: true }" x-show="show" class="p-4 bg-red-50 border border-red-200 text-red-800 rounded-2xl flex justify-between items-center shadow-sm">
            <div class="flex items-center gap-2">
                <span class="text-red-600 font-bold">⚠️</span>
                <span class="font-bold text-sm">{{ $errors->first('assign') }}</span>
            </div>
            <button @click="show = false" class="text-red-600 hover:text-red-800 font-bold">&times;</button>
        </div>
    @endif

    {{-- WELCOME SECTION & ADD CUSTOMER MODAL --}}
    <div x-data="{ 
            isAddCustomerOpen: {{ $errors->has('name') || $errors->has('contact') || $errors->has('nic_or_id') ? 'true' : 'false' }},
            hasDevice: {{ old('has_device') || old('no_of_devices', 0) > 0 ? 'true' : 'false' }},
            deviceCount: {{ old('no_of_devices', 1) }}
         }" 
         class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6 bg-gradient-to-r from-[#0B1B3F] via-slate-900 to-[#102A63] p-8 rounded-3xl text-white shadow-xl relative overflow-hidden">
        
        <div class="absolute -right-10 -bottom-10 w-48 h-48 bg-blue-500/10 rounded-full blur-2xl pointer-events-none"></div>

        <div class="relative z-10 max-w-xl">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-500/20 text-blue-300 text-xs font-semibold mb-3 border border-blue-400/20">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                Dealer Portal Active
            </div>
            <h1 class="text-2xl sm:text-3xl font-black tracking-tight">
                Welcome back, {{ $dealer->full_name ?? Auth::user()->full_name }} 👋
            </h1>
            <p class="text-slate-300 text-sm mt-1 leading-relaxed">
                Manage your customer requests, device allocations, and performance metrics from one place.
            </p>
        </div>

        {{-- ADD CUSTOMER BUTTON --}}
        <div class="relative z-10 shrink-0 w-full sm:w-auto">
            <button @click="isAddCustomerOpen = true" 
                    type="button"
                    class="w-full sm:w-auto bg-blue-600 hover:bg-blue-500 text-white font-bold py-3.5 px-6 rounded-2xl shadow-lg hover:shadow-blue-500/30 transition duration-200 flex items-center justify-center gap-2.5 group cursor-pointer border border-blue-400/30">
                <div class="bg-white/20 p-1.5 rounded-xl group-hover:scale-110 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path></svg>
                </div>
                <span class="text-sm font-extrabold tracking-wide">Add Customer</span>
            </button>
        </div>

        {{-- ADD CUSTOMER MODAL --}}
        <div x-show="isAddCustomerOpen" 
             x-cloak
             style="display: none;"
             class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 backdrop-blur-md p-4">
            
            <div @click.away="isAddCustomerOpen = false" 
                 x-transition
                 class="bg-white text-slate-800 rounded-3xl shadow-2xl w-full max-w-md overflow-hidden max-h-[90vh] flex flex-col border border-slate-100">
                 
                <div class="bg-[#0B1B3F] px-6 py-4 flex justify-between items-center shrink-0 text-white">
                    <div>
                        <h3 class="font-bold text-base">Add New Customer</h3>
                        <p class="text-[11px] text-blue-200">Submit new customer lead details</p>
                    </div>
                    <button type="button" @click="isAddCustomerOpen = false" class="text-slate-300 hover:text-white transition cursor-pointer">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>

                <form action="{{ route('dealer.customer-ad.store') }}" method="POST" class="p-5 overflow-y-auto space-y-3.5">
                    @csrf
                    
                    @if($errors->any() && !$errors->has('assign'))
                        <div class="p-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-xs font-semibold space-y-1">
                            <p class="font-bold text-xs mb-1 text-red-800">Please fix validation errors:</p>
                            @foreach ($errors->all() as $error)
                                <p>• {{ $error }}</p>
                            @endforeach
                        </div>
                    @endif

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Customer Name *</label>
                        <input type="text" name="name" value="{{ old('name') }}" required 
                               class="w-full border-slate-300 rounded-xl focus:border-blue-500 focus:ring focus:ring-blue-200 text-xs py-2 px-3">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Contact No *</label>
                            <input type="text" name="contact" value="{{ old('contact') }}" maxlength="10" required 
                                   placeholder="07XXXXXXXX"
                                   class="w-full border-slate-300 rounded-xl focus:border-blue-500 focus:ring focus:ring-blue-200 text-xs py-2 px-3">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">NIC / ID</label>
                            <input type="text" name="nic_or_id" value="{{ old('nic_or_id') }}" 
                                   class="w-full border-slate-300 rounded-xl focus:border-blue-500 focus:ring focus:ring-blue-200 text-xs py-2 px-3">
                        </div>
                    </div>

                    <div class="p-2.5 bg-blue-50/60 border border-blue-100 rounded-xl flex items-center gap-2.5">
                        <input type="checkbox" 
                               id="has_device_checkbox" 
                               name="has_device" 
                               value="1"
                               x-model="hasDevice" 
                               class="w-4 h-4 text-blue-600 border-slate-300 rounded focus:ring-blue-500 cursor-pointer">
                        <label for="has_device_checkbox" class="text-xs font-bold text-slate-700 cursor-pointer select-none">
                            Customer required devices?
                        </label>
                    </div>

                    <input type="hidden" name="no_of_devices" :value="hasDevice ? deviceCount : 0">

                    <div x-show="hasDevice" x-cloak class="space-y-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">No of Devices Required *</label>
                            <input type="number" 
                                   x-model.number="deviceCount" 
                                   min="1" 
                                   max="50" 
                                   :required="hasDevice"
                                   class="w-full border-slate-300 rounded-xl focus:border-blue-500 focus:ring focus:ring-blue-200 text-xs py-2 px-3">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Address</label>
                        <textarea name="address" rows="2" class="w-full border-slate-300 rounded-xl focus:border-blue-500 focus:ring focus:ring-blue-200 text-xs p-2.5">{{ old('address') }}</textarea>
                    </div>

                    <div class="pt-3 flex justify-end gap-2.5 border-t border-slate-100">
                        <button type="button" @click="isAddCustomerOpen = false" class="px-4 py-2 rounded-xl font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 transition text-xs cursor-pointer">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 rounded-xl font-bold text-white bg-blue-600 hover:bg-blue-700 transition text-xs shadow-md cursor-pointer">
                            Save Customer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- METRICS CARDS --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm hover:shadow-md transition relative overflow-hidden group">
            <div class="flex justify-between items-start">
                <div>
                    <div class="text-xs text-slate-500 uppercase font-bold tracking-wider">Total Available Stock</div>
                    <div class="text-4xl font-black text-blue-950 mt-3">{{ $myStockCount ?? 0 }}</div>
                </div>
                <div class="p-3 bg-blue-50 text-blue-600 rounded-2xl group-hover:scale-110 transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                </div>
            </div>
        </div>

        <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm hover:shadow-md transition relative overflow-hidden group">
            <div class="flex justify-between items-start">
                <div>
                    <div class="text-xs text-slate-500 uppercase font-bold tracking-wider">Total Customers</div>
                    <div class="text-4xl font-black text-blue-950 mt-3">{{ $totalCustomersCount ?? 0 }}</div>
                </div>
                <div class="p-3 bg-blue-50 text-blue-600 rounded-2xl group-hover:scale-110 transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                </div>
            </div>
        </div>

        <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm hover:shadow-md transition relative overflow-hidden group">
            <div class="flex justify-between items-start">
                <div>
                    <div class="text-xs text-slate-500 uppercase font-bold tracking-wider">Device Allocation</div>
                    <div class="text-4xl font-black text-blue-950 mt-3">Ready</div>
                </div>
                <div class="p-3 bg-amber-50 text-amber-600 rounded-2xl group-hover:scale-110 transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path></svg>
                </div>
            </div>
        </div>

        <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm hover:shadow-md transition relative overflow-hidden group">
            <div class="flex justify-between items-start">
                <div>
                    <div class="text-xs text-slate-500 uppercase font-bold tracking-wider">Earned Commission</div>
                    <div class="text-3xl font-black text-emerald-600 mt-2">LKR {{ number_format($totalCommission ?? 0) }}</div>
                </div>
                <div class="p-3 bg-emerald-50 text-emerald-600 rounded-2xl group-hover:scale-110 transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
            </div>
        </div>
    </div>

    {{-- ALLOCATION TABLES SECTION --}}
    <div class="space-y-8" x-data="{ isAssignModalOpen: false, selectedDeviceId: '', selectedImei: '' }">

        {{-- AVAILABLE STOCKS TABLE --}}
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                <div>
                    <h3 class="font-black text-slate-900 text-lg">My Available Stocks</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Unassigned devices ready to be allocated</p>
                </div>
                <span class="px-3 py-1 rounded-full bg-blue-50 text-blue-700 text-xs font-bold border border-blue-100">
                    Available: {{ $allocatedDevices->count() }}
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
                            <th class="p-4 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium text-slate-700">
                        @forelse($allocatedDevices as $device)
                            <tr class="hover:bg-slate-50/80 transition">
                                <td class="p-4 font-bold text-slate-900">{{ $device->device_category }}</td>
                                <td class="p-4 font-mono text-xs text-blue-600 font-bold">{{ $device->imei_number }}</td>
                                <td class="p-4 font-mono text-xs">{{ $device->sim_number ?? 'N/A' }}</td>
                                <td class="p-4">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                        {{ $device->status }}
                                    </span>
                                </td>
                                <td class="p-4 text-center">
                                    <button type="button" 
                                            @click="selectedDeviceId = '{{ $device->shdevice_id }}'; selectedImei = '{{ $device->imei_number }}'; isAssignModalOpen = true"
                                            class="p-2 bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white rounded-xl transition shadow-sm inline-flex items-center gap-1.5 text-xs font-bold cursor-pointer">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path></svg>
                                        <span>Assign Customer</span>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="p-8 text-center text-slate-400 italic">No available stock devices left.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ASSIGNED STOCKS HISTORY TABLE --}}
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                <div>
                    <h3 class="font-black text-slate-900 text-lg">Assigned Stocks History</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Devices assigned to customers</p>
                </div>
                <span class="px-3 py-1 rounded-full bg-emerald-50 text-emerald-700 text-xs font-bold border border-emerald-100">
                    Assigned: {{ $assignedDevices->count() }}
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-sm">
                    <thead class="bg-slate-50 text-slate-500 font-bold text-xs uppercase tracking-wider border-b border-slate-100">
                        <tr>
                            <th class="p-4">Customer Name</th>
                            <th class="p-4">IMEI Number</th>
                            <th class="p-4">Device Category</th>
                            <th class="p-4">SIM Number</th>
                            <th class="p-4">Status</th>
                            <th class="p-4 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium text-slate-700">
                        @forelse($assignedDevices as $device)
                            <tr class="hover:bg-slate-50/80 transition">
                                <td class="p-4 font-bold text-emerald-700 flex items-center gap-2">
                                    <div class="w-7 h-7 rounded-full bg-emerald-100 text-emerald-800 font-black text-xs flex items-center justify-center">
                                        {{ strtoupper(substr($device->assignedCustomer->name ?? 'C', 0, 1)) }}
                                    </div>
                                    <span>{{ $device->assignedCustomer->name ?? 'N/A' }}</span>
                                </td>
                                <td class="p-4 font-mono text-xs text-blue-600 font-bold">{{ $device->imei_number }}</td>
                                <td class="p-4 font-bold text-slate-900">{{ $device->device_category }}</td>
                                <td class="p-4 font-mono text-xs">{{ $device->sim_number ?? 'N/A' }}</td>
                                <td class="p-4">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                        Assigned
                                    </span>
                                </td>
                                <td class="p-4 text-center">
                                    <form action="{{ route('dealer.unassign-device', $device->shdevice_id) }}" 
                                          method="POST" 
                                          onsubmit="return confirm('Are you sure you want to unassign this device and move it back to stock?');"
                                          class="inline-block">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" 
                                                title="Unassign Device"
                                                class="p-2 text-red-600 hover:text-white bg-red-50 hover:bg-red-600 rounded-xl transition shadow-sm cursor-pointer inline-flex items-center gap-1 text-xs font-bold">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                            <span>Unassign</span>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="p-8 text-center text-slate-400 italic">No stock devices assigned to customers yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ASSIGN DEVICE MODAL (FILTERED CUSTOMERS) --}}
        <div x-show="isAssignModalOpen" x-cloak style="display: none;" 
             class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 backdrop-blur-md px-4">
            <div @click.away="isAssignModalOpen = false" class="bg-white text-slate-800 rounded-3xl shadow-2xl w-full max-w-md overflow-hidden border border-slate-100">
                <div class="bg-[#0B1B3F] px-6 py-4 flex justify-between items-center text-white">
                    <div>
                        <h3 class="font-bold text-lg">Assign Stock to Customer</h3>
                        <p class="text-xs text-blue-200">IMEI: <span x-text="selectedImei" class="font-mono font-bold"></span></p>
                    </div>
                    <button type="button" @click="isAssignModalOpen = false" class="text-slate-300 hover:text-white transition cursor-pointer">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>

                <form action="{{ route('dealer.assign-device') }}" method="POST" class="p-6 space-y-4">
                    @csrf
                    <input type="hidden" name="shdevice_id" :value="selectedDeviceId">

                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-1">Select Customer *</label>
                        <select name="customer_id" required class="w-full border-slate-300 rounded-xl focus:border-blue-500 focus:ring focus:ring-blue-200 text-sm">
                            <option value="">-- Choose Customer --</option>
                            @foreach($dealerCustomers as $cust)
                                @if((int)$cust->no_of_devices > 0)
                                    <option value="{{ $cust->id }}">
                                        {{ $cust->name }} (Pending: {{ $cust->no_of_devices }})
                                    </option>
                                @else
                                    <option value="{{ $cust->id }}" disabled class="bg-slate-100 text-slate-400">
                                        {{ $cust->name }} (Completed - 0 Pending)
                                    </option>
                                @endif
                            @endforeach
                        </select>
                    </div>

                    <div class="pt-4 flex justify-end gap-3 border-t border-slate-100">
                        <button type="button" @click="isAssignModalOpen = false" class="px-5 py-2.5 rounded-xl font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 transition text-sm cursor-pointer">Cancel</button>
                        <button type="submit" class="px-5 py-2.5 rounded-xl font-bold text-white bg-blue-600 hover:bg-blue-700 transition text-sm shadow-md cursor-pointer">Assign Device</button>
                    </div>
                </form>
            </div>
        </div>

    </div>

</div>

{{-- Firebase Web Push Notifications Listener --}}
<script src="https://www.gstatic.com/firebasejs/9.0.0/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/9.0.0/firebase-messaging-compat.js"></script>

<script>
    const firebaseConfig = {
        apiKey: "{{ config('services.firebase.api_key') }}",
        authDomain: "{{ config('services.firebase.auth_domain') }}",
        projectId: "{{ config('services.firebase.project_id') }}",
        storageBucket: "{{ config('services.firebase.storage_bucket') }}",
        messagingSenderId: "{{ config('services.firebase.messaging_sender_id') }}",
        appId: "{{ config('services.firebase.app_id') }}"
    };

    const vapidKey = "{{ config('services.firebase.vapid_key') }}";

    if (typeof firebase !== 'undefined' && firebaseConfig.apiKey) {
        firebase.initializeApp(firebaseConfig);
        const messaging = firebase.messaging();

        if ('serviceWorker' in navigator) {
            const swUrl = `/firebase-messaging-sw.js?` + new URLSearchParams(firebaseConfig).toString();
            
            navigator.serviceWorker.register(swUrl)
                .then((registration) => {
                    messaging.useServiceWorker(registration);
                    return Notification.requestPermission();
                })
                .then((permission) => {
                    if (permission === 'granted') {
                        return messaging.getToken({ vapidKey: vapidKey });
                    }
                })
                .then((currentToken) => {
                    if (currentToken) {
                        console.log('FCM Token Active');
                    }
                })
                .catch((err) => {
                    console.error('Firebase Setup Error:', err);
                });
        }

        messaging.onMessage((payload) => {
            const notificationTitle = payload.notification.title;
            const notificationOptions = {
                body: payload.notification.body,
                icon: payload.notification.icon || '/images/logo.png'
            };
            new Notification(notificationTitle, notificationOptions);
        });
    }
</script>

@endif

@endsection