@extends('layouts.dealer')

@section('title', 'Customer List')

@section('content')
<div class="max-w-7xl mx-auto space-y-5">

    @if(session('success'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
             class="p-3 bg-green-100 border border-green-300 text-green-800 rounded-xl flex justify-between items-center text-xs">
            <span class="font-bold">{{ session('success') }}</span>
            <button @click="show = false" class="text-green-600 font-bold">&times;</button>
        </div>
    @endif

    {{-- Header --}}
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <h1 class="text-2xl font-black text-blue-950">My Customer List</h1>
            <p class="text-xs text-slate-500 mt-0.5">Your registered customer leads and their ShaloTrack app status.</p>
        </div>
        <span class="bg-blue-50 text-blue-950 border border-blue-200 py-1 px-3 rounded-full text-xs font-black">
            {{ $customerAds->count() }} Customers
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
    @forelse($customerAds as $customer)
        @php
            $app      = $customer->appAccount;      // CustomerAd|null
            $vehicles = $customer->appVehicles;     // Collection<VehicleAd>
            $isLive   = $app !== null;
        @endphp

        <div class="bg-white rounded-2xl border {{ $isLive ? 'border-emerald-200' : 'border-slate-200' }} shadow-sm overflow-hidden">

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
                    {{-- App status badge --}}
                    @if($isLive)
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-emerald-100 text-emerald-700 border border-emerald-300 text-[11px] font-bold">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 inline-block"></span>
                            Active on App
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-slate-100 text-slate-500 border border-slate-200 text-[11px] font-bold">
                            <span class="w-1.5 h-1.5 rounded-full bg-slate-400 inline-block"></span>
                            Not Registered
                        </span>
                    @endif

                    {{-- Pending devices badge --}}
                    @if($customer->no_of_devices > 0)
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-orange-50 text-orange-600 border border-orange-200 text-[11px] font-bold">
                            {{ $customer->no_of_devices }} device(s) pending
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
                    <div class="text-[10px] font-black text-slate-400 uppercase tracking-wider mb-2">Lead Details</div>

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

</div>
@endsection