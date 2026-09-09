@extends('layouts.dealer')

@section('title', 'Device Command Center')

@section('content')
<div class="max-w-7xl mx-auto space-y-5">

    {{-- Header --}}
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <h1 class="text-2xl font-black text-blue-950">Device Command Center</h1>
            <p class="text-xs text-slate-500 mt-0.5">Send real-time commands to your connected GPS devices.</p>
        </div>
        <div class="flex items-center gap-2">
            @if($gatewayOnline)
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-bold">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 inline-block animate-pulse"></span>
                    Gateway Online
                </span>
            @else
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-red-50 text-red-600 border border-red-200 text-xs font-bold">
                    <span class="w-2 h-2 rounded-full bg-red-500 inline-block animate-pulse"></span>
                    Gateway Offline
                </span>
            @endif

            <span class="px-3 py-1 rounded-full bg-blue-50 text-blue-900 border border-blue-200 text-xs font-black">
                {{ $connectedDevices->count() }} Connected
            </span>
        </div>
    </div>

    {{-- Gateway Warning Alert --}}
    @if(!$gatewayOnline)
        <div class="p-4 bg-red-50 border border-red-200 rounded-2xl flex items-center gap-3 text-red-700 text-xs font-semibold">
            <svg class="w-5 h-5 flex-shrink-0 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <span>The gateway command API is currently unreachable. Commands cannot be sent until the gateway is restored.</span>
        </div>
    @endif

    {{-- Table --}}
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-slate-50 text-slate-500 uppercase font-extrabold tracking-wider border-b border-slate-200 text-[11px]">
                    <tr>
                        <th class="px-5 py-3.5">Vehicle</th>
                        <th class="px-5 py-3.5">IMEI</th>
                        <th class="px-5 py-3.5">Customer</th>
                        <th class="px-5 py-3.5">Status</th>
                        <th class="px-5 py-3.5">Last Seen</th>
                        <th class="px-5 py-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @forelse($vehicles as $vehicle)
                        <tr class="hover:bg-slate-50 transition">
                            <td class="px-5 py-4 font-black text-blue-950">
                                {{ $vehicle->vehicle_number ?? 'No Plate' }}
                            </td>
                            <td class="px-5 py-4 font-mono text-slate-600">
                                {{ $vehicle->imei ?? '—' }}
                            </td>
                            <td class="px-5 py-4 font-bold text-slate-700">
                                {{ $vehicle->customer_name ?? '—' }}
                            </td>
                            <td class="px-5 py-4">
                                @if($vehicle->is_online)
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-100 text-emerald-700 border border-emerald-300 text-[10px] font-bold">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 inline-block"></span>
                                        Online
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-slate-100 text-slate-600 border border-slate-200 text-[10px] font-bold">
                                        <span class="w-1.5 h-1.5 rounded-full bg-slate-400 inline-block"></span>
                                        Offline
                                    </span>
                                @endif
                            </td>
                            <td class="px-5 py-4 text-slate-500 font-mono">
                                {{ $vehicle->last_seen ? \Carbon\Carbon::parse($vehicle->last_seen)->diffForHumans() : '—' }}
                            </td>
                            <td class="px-5 py-4 text-right space-x-2">
                                <button @if(!$gatewayOnline) disabled @endif
                                        class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-lg transition disabled:bg-slate-100 disabled:text-slate-400 disabled:cursor-not-allowed">
                                    Send Command
                                </button>
                                <button class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-lg transition">
                                    History
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center text-slate-400">
                                No registered GPS devices found for your customers.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection