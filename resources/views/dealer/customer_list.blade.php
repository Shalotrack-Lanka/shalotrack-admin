@extends('layouts.dealer')

@section('title', 'Customer List')

@section('content')
<!-- Add x-data to the main container for the assign device modal -->
<div class="max-w-7xl mx-auto space-y-5" x-data="{ assignModalOpen: false, selectedCustomerId: null, selectedCustomerName: '' }">

    @if(session('success'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
             class="p-3 bg-green-100 border border-green-300 text-green-800 rounded-xl flex justify-between items-center text-xs">
            <span class="font-bold">{{ session('success') }}</span>
            <button @click="show = false" class="text-green-600 font-bold">&times;</button>
        </div>
    @endif
    @if($errors->any())
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 6000)"
             class="p-3 bg-red-100 border border-red-300 text-red-800 rounded-xl flex justify-between items-center text-xs mb-4">
            <ul class="list-disc pl-5 font-bold">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
            <button @click="show = false" class="text-red-600 font-bold">&times;</button>
        </div>
    @endif

    {{-- Header --}}
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <h1 class="text-2xl font-black text-blue-950">My Customer List</h1>
            <p class="text-xs text-slate-500 mt-0.5">Your registered customer leads and their ShaloTrack app status.</p>
        </div>
        <span class="bg-blue-50 text-blue-950 border border-blue-200 py-1 px-3 rounded-full text-xs font-black">
            {{ collect($customerAds ?? [])->count() }} Customers
        </span>
    </div>

    {{-- Search --}}
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <form action="{{ route('dealer.customers.index') }}" method="GET"
              class="flex flex-col md:flex-row gap-3 items-center">
            <div class="relative flex-1">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </div>
                <input type="text" name="search" value="{{ $search ?? '' }}"
                       placeholder="Search by name, contact, email, NIC, or IMEI..."
                       class="w-full pl-9 pr-4 py-2.5 text-xs border border-slate-300 rounded-xl focus:border-blue-500 focus:ring focus:ring-blue-200">
            </div>
            @if(!empty($search))
                <a href="{{ route('dealer.customers.index') }}"
                   class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition">
                    Clear
                </a>
            @endif
            <button type="submit"
                    class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl shadow-sm transition">
                Search
            </button>
        </form>
    </div>

    {{-- Customer cards --}}
    @forelse($customerAds ?? [] as $customer)
        @php
            $app = null;

            // 1. Email එක තිබේ නම් exact email එකෙන්ම සොයන්න (Priority 1)
            if (!empty($customer->email)) {
                $app = \App\Models\CustomerAd::whereRaw('LOWER(email) = ?', [strtolower(trim($customer->email))])->first();
            }

            // 2. Email එකෙන් හමු නොවූයේ නම් පමණක් Phone number එකෙන් සොයන්න (Priority 2)
            if (!$app && !empty($customer->contact)) {
                $digitsOnly = preg_replace('/[^0-9]/', '', $customer->contact);
                $shortPhone = strlen($digitsOnly) >= 9 ? substr($digitsOnly, -9) : $digitsOnly;

                $app = \App\Models\CustomerAd::where('phone_number', 'LIKE', '%' . $shortPhone)->first();
            }

            // 3. හමුවූ CustomerAd එකේ customer_id එකෙන් VehicleAd records ලබා ගැනීම
            $vehicles = $app 
                ? \App\Models\VehicleAd::where('customer_id', $app->customer_id)->get() 
                : collect();

            $isLive = $app !== null;
        @endphp

        <div class="bg-white rounded-2xl border {{ $isLive ? 'border-emerald-200' : 'border-slate-200' }} shadow-sm overflow-hidden mb-4">

            {{-- Card header --}}
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between
                        px-5 py-4 {{ $isLive ? 'bg-emerald-50/60' : 'bg-slate-50' }} border-b border-slate-100 gap-3">

                <div class="flex items-center gap-3">
                    {{-- Avatar --}}
                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-black text-white flex-shrink-0
                                {{ $isLive ? 'bg-emerald-600' : 'bg-slate-400' }}">
                        {{ strtoupper(substr($customer->name, 0, 1)) }}
                    </div>
                    <div>
                        <div class="font-black text-sm text-blue-950">{{ $customer->name }}</div>
                        <div class="text-[11px] text-slate-500 mt-0.5 space-x-2">
                            <span class="font-mono">{{ $customer->contact }}</span>
                            @if($customer->email)
                                <span>&middot;</span>
                                <span>{{ $customer->email }}</span>
                            @endif
                            @if($customer->nic_or_id)
                                <span>&middot;</span>
                                <span>NIC: {{ $customer->nic_or_id }}</span>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-2 flex-wrap">
                    
                    {{-- 🔹 Assign New Device Button 🔹 --}}
                    <button 
                        type="button"
                        @click="assignModalOpen = true; selectedCustomerId = '{{ $customer->id }}'; selectedCustomerName = '{{ addslashes($customer->name) }}'" 
                        class="flex items-center gap-1.5 px-3 py-1.5 text-[11px] font-bold text-blue-700 bg-blue-100 border border-blue-200 rounded-lg hover:bg-blue-200 transition">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        Assign Device
                    </button>

                    {{-- App status badge --}}
                    @if($isLive)
                        <span class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-emerald-100 text-emerald-700 border border-emerald-300 text-[11px] font-bold">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 inline-block"></span>
                            Active on App
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-slate-100 text-slate-500 border border-slate-200 text-[11px] font-bold">
                            <span class="w-1.5 h-1.5 rounded-full bg-slate-400 inline-block"></span>
                            Not Registered
                        </span>
                    @endif

                    {{-- Delete --}}
                    <form action="{{ route('dealer.customer-ad.destroy', $customer->id) }}" method="POST"
                          onsubmit="return confirm('Delete {{ addslashes($customer->name) }}?');">
                        @csrf @method('DELETE')
                        <button type="submit"
                                class="p-1.5 text-red-400 hover:text-red-700 hover:bg-red-50 rounded-lg transition"
                                title="Delete customer">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                        </button>
                    </form>
                </div>
            </div>

            {{-- Card body: two columns --}}
            <div class="grid grid-cols-1 md:grid-cols-2 divide-y md:divide-y-0 md:divide-x divide-slate-100">

                {{-- Left: Lead info --}}
                <div class="p-4 space-y-2 text-xs">
                    <div class="text-[10px] font-black text-slate-400 uppercase tracking-wider mb-2 flex justify-between items-center">
                        <span>Lead Details</span>
                    </div>

                    @if(!empty($customer->imei_numbers) && count($customer->imei_numbers) > 0)
                        <div>
                            <span class="font-bold text-slate-600">Assigned IMEIs:</span>
                            <div class="mt-1 flex flex-wrap gap-1">
                                @foreach($customer->imei_numbers as $imei)
                                    <span class="font-mono text-[10px] bg-slate-100 text-slate-700 px-2 py-0.5 rounded">{{ $imei }}</span>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <div class="text-slate-400 italic">No devices assigned yet.</div>
                    @endif

                    @if($customer->address)
                        <div><span class="font-bold text-slate-600">Address:</span> {{ $customer->address }}</div>
                    @endif
                    <div class="text-slate-400 text-[10px]">Added {{ $customer->created_at?->format('d M Y') }}</div>
                </div>

                {{-- Right: App account + vehicles --}}
                <div class="p-4 text-xs">
                    @if($isLive)
                        <div class="text-[10px] font-black text-emerald-600 uppercase tracking-wider mb-2">ShaloTrack App Account</div>
                        <div class="space-y-1 mb-3">
                            <div><span class="font-bold text-slate-600">Registered Name:</span> {{ $app->full_name }}</div>
                            <div><span class="font-bold text-slate-600">Email:</span> {{ $app->email }}</div>
                            <div><span class="font-bold text-slate-600">Phone:</span> {{ $app->phone_number }}</div>
                            <div><span class="font-bold text-slate-600">Vehicles:</span> {{ $vehicles->count() }}</div>
                        </div>

                        @if($vehicles->isNotEmpty())
                            <div class="text-[10px] font-black text-slate-400 uppercase tracking-wider mb-1.5">Registered Vehicles</div>
                            <div class="space-y-1.5">
                                @foreach($vehicles as $v)
                                    <div class="flex items-center gap-2 bg-slate-50 rounded-lg px-3 py-2">
                                        <div class="flex-1 min-w-0">
                                            <div class="font-bold text-blue-950">{{ $v->vehicle_number ?? 'No plate' }}</div>
                                            <div class="text-slate-500 text-[10px]">
                                                {{ $v->make }} {{ $v->model }}
                                                @if($v->year) ({{ $v->year }}) @endif
                                            </div>
                                        </div>
                                        @if($v->has_gps_device)
                                            <span class="flex-shrink-0 inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 border border-blue-200 text-[10px] font-bold">
                                                <span class="w-1.5 h-1.5 rounded-full bg-blue-500 inline-block"></span>
                                                GPS Active
                                                @if($v->imei)
                                                    <span class="font-mono ml-1">{{ $v->imei }}</span>
                                                @endif
                                            </span>
                                        @else
                                            <span class="flex-shrink-0 px-2 py-0.5 rounded-full bg-slate-100 text-slate-500 text-[10px] font-bold">No GPS</span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="text-slate-400 italic text-[11px]">No vehicles registered yet.</div>
                        @endif
                    @else
                        <div class="text-[10px] font-black text-slate-400 uppercase tracking-wider mb-2">ShaloTrack App Account</div>
                        <div class="text-slate-400 text-[11px] italic">
                            This customer has not yet created a ShaloTrack account.
                            They need to download the app and register using the same
                            phone number or email you recorded.
                        </div>
                    @endif
                </div>

            </div>
        </div>
    @empty
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-10 text-center text-slate-400 text-sm">
            No customers added yet.
        </div>
    @endforelse

    {{-- 🔹 Assign Device Modal 🔹 --}}
    <div x-show="assignModalOpen" 
         x-cloak
         style="display: none;" 
         class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm px-4">
        
        <div @click.outside="assignModalOpen = false" 
             x-show="assignModalOpen"
             x-transition.opacity.duration.300ms
             class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col">
            
            <!-- Modal Header -->
            <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50 flex justify-between items-center">
                <h3 class="text-base font-black text-slate-800">
                    Assign Device to <span x-text="selectedCustomerName" class="text-blue-600"></span>
                </h3>
                <button @click="assignModalOpen = false" type="button" class="text-slate-400 hover:text-slate-700 bg-white hover:bg-slate-100 rounded-full p-1.5 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>

            <!-- Modal Body (Form) -->
            <form action="{{ route('dealer.customers.assign_new_device_from_list') }}" method="POST">
                @csrf
                <div class="p-6">
                    <input type="hidden" name="customer_id" x-bind:value="selectedCustomerId">

                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wide mb-2">Available Stock Devices</label>
                    
                    @if(isset($availableDevices) && $availableDevices->count() > 0)
                        <select name="shdevice_id" required class="w-full px-4 py-3 rounded-xl border border-slate-300 focus:ring-4 focus:ring-blue-100 focus:border-blue-500 outline-none text-sm font-medium text-slate-700 bg-white transition-all">
                            <option value="" disabled selected>-- Select a device to assign --</option>
                            @foreach($availableDevices as $device)
                                <option value="{{ $device->shdevice_id }}">
                                    {{ $device->device_category ?? 'Unknown Category' }} | IMEI: {{ $device->imei_number }} | SIM: {{ $device->sim_number }}
                                </option>
                            @endforeach
                        </select>
                        <p class="text-[11px] text-slate-500 mt-3 flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            Only unassigned devices are listed here.
                        </p>
                    @else
                        <div class="p-4 bg-red-50 text-red-700 rounded-xl text-sm border border-red-100 flex items-start gap-3">
                            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                            <p class="font-medium leading-relaxed">You have no available devices in stock. Please contact admin to restock.</p>
                        </div>
                    @endif
                </div>

                <!-- Modal Footer -->
                <div class="px-6 py-4 border-t border-slate-100 bg-slate-50 flex justify-end gap-3">
                    <button type="button" @click="assignModalOpen = false" class="px-5 py-2 text-sm font-bold text-slate-600 bg-white border border-slate-300 rounded-xl hover:bg-slate-50 transition">
                        Cancel
                    </button>
                    @if(isset($availableDevices) && $availableDevices->count() > 0)
                        <button type="submit" class="px-5 py-2 text-sm font-bold text-white bg-blue-600 rounded-xl hover:bg-blue-700 hover:shadow-lg hover:shadow-blue-200 transition">
                            Confirm Assignment
                        </button>
                    @endif
                </div>
            </form>
        </div>
    </div>

</div>
@endsection