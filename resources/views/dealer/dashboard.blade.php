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

<div class="max-w-7xl mx-auto">

 {{-- ============================================================
     WELCOME SECTION & ADD CUSTOMER MODAL
============================================================ --}}
<div x-data="{ 
        isAddCustomerOpen: {{ $errors->any() ? 'true' : 'false' }},
        deviceCount: {{ old('no_of_devices', 1) }}
     }" 
     class="mb-8 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">

    <div>
        <h1 class="text-3xl font-black text-blue-950">
            Welcome back, {{ $dealer->full_name ?? Auth::user()->full_name }}
        </h1>
        <p class="text-slate-500 mt-2">
            Here is what's happening with your stock and devices today.
        </p>
    </div>

    <!-- Add Customer Button -->
    <button @click="isAddCustomerOpen = true" 
            class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-6 rounded-2xl shadow-sm transition flex items-center gap-2">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
        </svg>
        Add Customer
    </button>

    <!-- Alpine.js Modal Background -->
    <div x-show="isAddCustomerOpen" 
         x-cloak
         style="display: none;"
         class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 backdrop-blur-sm px-4">
        
        <!-- Modal Content -->
        <div @click.away="isAddCustomerOpen = false" 
             x-transition
             class="bg-white rounded-3xl shadow-xl w-full max-w-lg overflow-hidden max-h-[90vh] flex flex-col">
             
            <!-- Header -->
            <div class="bg-blue-950 px-6 py-4 flex justify-between items-center shrink-0">
                <h3 class="text-white font-bold text-lg">Add New Customer</h3>
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
                           class="w-full border-slate-300 rounded-xl focus:border-blue-500 focus:ring focus:ring-blue-200">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-1">Contact No (10 Digits) *</label>
                        <input type="text" name="contact" value="{{ old('contact') }}" maxlength="10" required 
                               placeholder="07XXXXXXXX"
                               class="w-full border-slate-300 rounded-xl focus:border-blue-500 focus:ring focus:ring-blue-200">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-1">NIC / ID</label>
                        <input type="text" name="nic_or_id" value="{{ old('nic_or_id') }}" 
                               class="w-full border-slate-300 rounded-xl focus:border-blue-500 focus:ring focus:ring-blue-200">
                    </div>
                </div>

                <!-- Devices Count -->
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">No of Devices Required *</label>
                    <input type="number" name="no_of_devices" x-model.number="deviceCount" min="1" max="50" required 
                           class="w-full border-slate-300 rounded-xl focus:border-blue-500 focus:ring focus:ring-blue-200">
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
                    <textarea name="address" rows="2" class="w-full border-slate-300 rounded-xl focus:border-blue-500 focus:ring focus:ring-blue-200">{{ old('address') }}</textarea>
                </div>

                <!-- Footer Buttons -->
                <div class="pt-4 flex justify-end gap-3">
                    <button type="button" @click="isAddCustomerOpen = false" class="px-5 py-2.5 rounded-xl font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 transition">
                        Cancel
                    </button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl font-bold text-white bg-blue-600 hover:bg-blue-700 transition">
                        Save Customer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

    {{-- ============================================================
         SUMMARY CARDS
    ============================================================ --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-5 mb-10">

        {{-- Total Stock Received --}}
        <div class="bg-white p-6 rounded-3xl
                    border border-slate-200 border-l-4 border-l-orange-500
                    shadow-sm hover:shadow-md transition">

            <div class="text-xs text-slate-500 uppercase font-bold tracking-wider">
                Total Stock Received
            </div>

            <div class="text-4xl font-black text-blue-950 mt-3">
                {{ $totalStockReceived }}
            </div>

            <div class="text-xs text-slate-400 mt-2">
                All transferred stock
            </div>

        </div>

        {{-- Ready For Activation --}}
        <div class="bg-white p-6 rounded-3xl
                    border border-slate-200 border-l-4 border-l-green-500
                    shadow-sm hover:shadow-md transition">

            <div class="text-xs text-slate-500 uppercase font-bold tracking-wider">
                Ready for Activation
            </div>

            <div class="text-4xl font-black text-blue-950 mt-3">
                {{ $readyForActivationCount }}
            </div>

            <div class="text-xs text-slate-400 mt-2">
                Not activated devices
            </div>

        </div>


        {{-- Customers --}}
        <div class="bg-slate-50 p-6 rounded-3xl
                    border border-slate-200">

            <div class="text-xs text-slate-500 uppercase font-bold tracking-wider">
                Total Customers
            </div>

            <div class="text-sm text-slate-400 font-semibold mt-5">
                Not linked yet
            </div>

        </div>


        {{-- Vehicles --}}
        <div class="bg-slate-50 p-6 rounded-3xl
                    border border-slate-200">

            <div class="text-xs text-slate-500 uppercase font-bold tracking-wider">
                Active Vehicles
            </div>

            <div class="text-sm text-slate-400 font-semibold mt-5">
                Not linked yet
            </div>

        </div>

    </div>

    {{-- ============================================================
         MY ASSIGNED DEVICES (IMEI-level detail)
         Uses $allocatedDevices, already built and passed by
         DealerDashboardController — no controller change needed.
    ============================================================ --}}
    <div class="bg-white rounded-3xl
                border border-slate-200
                shadow-sm overflow-hidden mb-10">

        {{-- Header --}}
        <div class="p-6 md:p-8
                    flex justify-between items-center
                    border-b border-slate-200">

            <div>

                <h2 class="font-extrabold text-blue-950 text-lg
                           uppercase tracking-wider">

                    My Assigned Devices

                </h2>

                <p class="text-xs text-slate-400 mt-1">
                    Individual IMEI devices allocated to you
                </p>

            </div>


            <span class="bg-orange-50 text-orange-600
                         border border-orange-200
                         py-1.5 px-4 rounded-full
                         text-xs font-black">

                {{ $allocatedDeviceCount }} Devices

            </span>

        </div>


        {{-- Device Table --}}
        <div class="overflow-x-auto">

            <table class="w-full text-left text-sm whitespace-nowrap">

                <thead class="bg-slate-50
                              text-slate-500 text-xs
                              uppercase font-extrabold
                              tracking-widest
                              border-b border-slate-200">

                    <tr>

                        <th class="px-8 py-5">
                            IMEI Number
                        </th>

                        <th class="px-8 py-5">
                            SIM Number
                        </th>

                        <th class="px-8 py-5">
                            Device Type
                        </th>

                        <th class="px-8 py-5">
                            Status
                        </th>

                        <th class="px-8 py-5">
                            Allocation Date
                        </th>

                    </tr>

                </thead>


                <tbody class="divide-y divide-slate-200 bg-white">

                    @forelse($allocatedDevices as $device)

                        <tr class="hover:bg-slate-50 transition">

                            {{-- IMEI --}}
                            <td class="px-8 py-5 font-mono text-xs text-slate-600">
                                {{ $device->imei_number }}
                            </td>

                            {{-- SIM --}}
                            <td class="px-8 py-5 text-slate-600">
                                {{ $device->sim_number ?? '-' }}
                            </td>

                            {{-- Device Type --}}
                            <td class="px-8 py-5 font-bold text-blue-950">
                                {{ $device->deviceType->device_category ?? $device->device_category ?? '-' }}
                                @if($device->deviceType?->model)
                                    <span class="text-slate-400 font-normal">— {{ $device->deviceType->model }}</span>
                                @endif
                            </td>

                            {{-- Status --}}
                            <td class="px-8 py-5">
                                @php
                                    $statusStyles = match(strtolower(trim((string) $device->status))) {
                                        'activated' => 'bg-green-50 text-green-700 border-green-200',
                                        'temporarily stopped' => 'bg-red-50 text-red-700 border-red-200',
                                        default => 'bg-slate-100 text-slate-500 border-slate-200',
                                    };
                                @endphp
                                <span class="inline-flex items-center px-3 py-1
                                             rounded-full text-xs font-bold
                                             border {{ $statusStyles }}">
                                    {{ $device->status }}
                                </span>
                            </td>

                            {{-- Allocation Date --}}
                            <td class="px-8 py-5 text-slate-500">
                                @if($device->allocated_at)
                                    {{ $device->allocated_at->format('d M Y, h:i A') }}
                                @else
                                    <span class="text-slate-400 italic text-xs">Not recorded</span>
                                @endif
                            </td>

                        </tr>

                    @empty

                        <tr>

                            <td colspan="5"
                                class="px-8 py-12
                                       text-center text-slate-400">

                                No devices have been assigned to you yet.

                            </td>

                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>

    </div>


    {{-- ============================================================
         STOCK SUMMARY + RECENT ACTIVITIES
    ============================================================ --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-10">


        {{-- ========================================================
             STOCK SUMMARY
        ======================================================== --}}
        <div id="stock-summary"
             class="bg-white p-7 rounded-3xl
                    border border-slate-200 shadow-sm">

            <h2 class="font-extrabold text-blue-950 text-base mb-6
                       uppercase tracking-wider flex items-center">

                <span class="bg-orange-100 text-orange-600
                             p-2 rounded-xl mr-3
                             border border-orange-200">

                    <svg class="w-5 h-5"
                         fill="none"
                         stroke="currentColor"
                         viewBox="0 0 24 24">

                        <path stroke-linecap="round"
                              stroke-linejoin="round"
                              stroke-width="2"
                              d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4">
                        </path>

                    </svg>

                </span>

                Stock Summary

            </h2>


            <div class="space-y-4">

                {{-- Total Stock Received --}}
                <div class="flex justify-between items-center
                            bg-slate-50 p-4 rounded-2xl
                            border border-slate-200">

                    <div>

                        <div class="text-sm font-bold text-slate-700">
                            Total Stock Received
                        </div>

                        <div class="text-xs text-slate-400 mt-1">
                            Total quantity from all stock transfers
                        </div>

                    </div>

                    <span class="font-black text-2xl text-blue-950">
                        {{ $totalStockReceived }}
                    </span>

                </div>


                {{-- Latest Stock Received --}}
                <div class="flex justify-between items-center
                            bg-slate-50 p-4 rounded-2xl
                            border border-slate-200">

                    <div>

                        <div class="text-sm font-bold text-slate-700">
                            Latest Stock Received
                        </div>

                        <div class="text-xs text-slate-400 mt-1">

                            @if($transfers->isNotEmpty())

                                {{ $transfers->first()->created_at->format('d M Y') }}

                            @else

                                No stock transfers yet

                            @endif

                        </div>

                    </div>

                    <span class="font-black text-2xl text-orange-500">
                        {{ $latestStockReceived }}
                    </span>

                </div>

                {{-- Ready For Activation --}}
                <div class="flex justify-between items-center
                            bg-slate-50 p-4 rounded-2xl
                            border border-slate-200">

                    <div>

                        <div class="text-sm font-bold text-slate-700">
                            Ready for Activation
                        </div>

                        <div class="text-xs text-slate-400 mt-1">
                            Allocated devices with Not Activated status
                        </div>

                    </div>

                    <span class="font-black text-2xl text-green-600">
                        {{ $readyForActivationCount }}
                    </span>

                </div>

            </div>

        </div>



        {{-- ========================================================
             RECENT ACTIVITIES
        ======================================================== --}}
        <div class="bg-white p-7 rounded-3xl
                    border border-slate-200 shadow-sm">

            <h2 class="font-extrabold text-blue-950 text-base mb-6
                       uppercase tracking-wider">

                Recent Activities

            </h2>


            <div class="space-y-4">

                @forelse($recentActivity as $activity)

                    <div class="flex gap-4 items-start
                                p-4 bg-slate-50
                                rounded-2xl border border-slate-200">

                        {{-- Activity Icon --}}
                        <div class="w-10 h-10 flex-shrink-0
                                    bg-orange-100 text-orange-600
                                    rounded-xl flex items-center justify-center">

                            <svg class="w-5 h-5"
                                 fill="none"
                                 stroke="currentColor"
                                 viewBox="0 0 24 24">

                                <path stroke-linecap="round"
                                      stroke-linejoin="round"
                                      stroke-width="2"
                                      d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4">
                                </path>

                            </svg>

                        </div>


                        <div>

                            <div class="text-xs text-slate-400 mb-1">

                                {{ $activity['date']
                                    ? $activity['date']->format('d M Y, h:i A')
                                    : '-' }}

                            </div>

                            <div class="text-sm font-bold text-slate-700">
                                {{ $activity['text'] }}
                            </div>

                        </div>

                    </div>

                @empty

                    <div class="text-center py-10 text-slate-400">

                        <p class="font-semibold">
                            No recent activities.
                        </p>

                    </div>

                @endforelse

            </div>

        </div>

    </div>


    {{-- ============================================================
         QUICK ACTIONS
    ============================================================ --}}
    <div class="bg-white p-7 rounded-3xl
                border border-slate-200 shadow-sm mb-10">

        <h2 class="font-extrabold text-blue-950 text-base mb-6
                   uppercase tracking-wider">

            Quick Actions

        </h2>


        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">

            {{-- Add Customer --}}
            <button type="button"
                    disabled
                    title="Customer API integration is not available yet"
                    class="p-4 rounded-2xl
                           bg-slate-100 border border-slate-200
                           text-slate-400 cursor-not-allowed
                           font-bold text-sm">

                Add Customer

            </button>


            {{-- View Customers --}}
            <button type="button"
                    disabled
                    title="Customer API integration is not available yet"
                    class="p-4 rounded-2xl
                           bg-slate-100 border border-slate-200
                           text-slate-400 cursor-not-allowed
                           font-bold text-sm">

                View Customers

            </button>


            {{-- Assign Device --}}
            <button type="button"
                    disabled
                    title="Device assignment to customers is not available yet"
                    class="p-4 rounded-2xl
                           bg-slate-100 border border-slate-200
                           text-slate-400 cursor-not-allowed
                           font-bold text-sm">

                Assign Device

            </button>


            {{-- View Vehicles --}}
            <button type="button"
                    disabled
                    title="Vehicle API integration is not available yet"
                    class="p-4 rounded-2xl
                           bg-slate-100 border border-slate-200
                           text-slate-400 cursor-not-allowed
                           font-bold text-sm">

                View Vehicles

            </button>

        </div>

    </div>



    {{-- ============================================================
         RECENT STOCK TRANSFERS
         IMPORTANT:
         Existing StockTransfer backend logic remains unchanged.
    ============================================================ --}}
    <div class="bg-white rounded-3xl
                border border-slate-200
                shadow-sm overflow-hidden mb-10">


        {{-- Header --}}
        <div class="p-6 md:p-8
                    flex justify-between items-center
                    border-b border-slate-200">

            <div>

                <h2 class="font-extrabold text-blue-950 text-lg
                           uppercase tracking-wider">

                    Recent Stock Transfers

                </h2>

                <p class="text-xs text-slate-400 mt-1">
                    Stock received from the company
                </p>

            </div>


            <span class="bg-orange-50 text-orange-600
                         border border-orange-200
                         py-1.5 px-4 rounded-full
                         text-xs font-black">

                {{ $transfers->count() }} Transfers

            </span>

        </div>


        {{-- Transfer Table --}}
        <div class="overflow-x-auto">

            <table class="w-full text-left text-sm whitespace-nowrap">

                <thead class="bg-slate-50
                              text-slate-500 text-xs
                              uppercase font-extrabold
                              tracking-widest
                              border-b border-slate-200">

                    <tr>

                        <th class="px-8 py-5">
                            Transfer Date
                        </th>

                        <th class="px-8 py-5">
                            Device Type
                        </th>

                        <th class="px-8 py-5">
                            Quantity
                        </th>

                        <th class="px-8 py-5">
                            Remarks
                        </th>

                    </tr>

                </thead>


                <tbody class="divide-y divide-slate-200 bg-white">

                    @forelse($transfers as $transfer)

                        <tr class="hover:bg-slate-50 transition">

                            {{-- Date --}}
                            <td class="px-8 py-5 text-slate-600">

                                {{ $transfer->created_at
                                    ? $transfer->created_at->format('d M Y, h:i A')
                                    : '-' }}

                            </td>


                            {{-- Device Type --}}
                            <td class="px-8 py-5
                                       font-bold text-blue-950">

                                {{ $transfer->stock?->deviceType?->model ?? 'Device' }}

                            </td>


                            {{-- Quantity --}}
                            <td class="px-8 py-5">

                                <span class="inline-flex
                                             items-center justify-center
                                             min-w-[45px]
                                             px-3 py-1.5
                                             rounded-lg
                                             bg-blue-50
                                             text-blue-950
                                             border border-blue-200
                                             font-black">

                                    {{ $transfer->quantity }}

                                </span>

                            </td>


                            {{-- Remarks --}}
                            <td class="px-8 py-5 text-slate-500">

                                {{ $transfer->remarks ?: '-' }}

                            </td>

                        </tr>

                    @empty

                        <tr>

                            <td colspan="4"
                                class="px-8 py-12
                                       text-center text-slate-400">

                                No stock transfers have been received yet.

                            </td>

                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>

    </div>


    {{-- ============================================================
         CUSTOMER / VEHICLE API INFORMATION
    ============================================================ --}}
    <div class="bg-blue-50
                border border-blue-200
                rounded-3xl p-7 mb-10">

        <div class="flex items-start gap-4">

            <div class="w-11 h-11
                        bg-blue-950 text-white
                        rounded-xl
                        flex items-center justify-center
                        flex-shrink-0">

                <svg class="w-5 h-5"
                     fill="none"
                     stroke="currentColor"
                     viewBox="0 0 24 24">

                    <path stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z">
                    </path>

                </svg>

            </div>


            <div>

                <h3 class="font-black text-blue-950 mb-2">
                    Customers & Vehicles API Setup Pending
                </h3>

                <p class="text-sm text-slate-600 leading-relaxed">

                    Customer and Vehicle management is not available yet.
                    There is currently no Dealer-to-Customer or
                    Dealer-to-Vehicle relationship available through the
                    current system integration.

                </p>

            </div>

        </div>

    </div>

</div>

@endif

@endsection