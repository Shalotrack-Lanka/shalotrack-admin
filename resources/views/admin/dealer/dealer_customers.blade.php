@extends('layouts.admin')

@section('title', 'Customers Added by Dealers')

@section('content')
<div class="space-y-5">

    {{-- Header --}}
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <h1 class="text-2xl font-black text-blue-950">Customers Added by Dealers</h1>
            <p class="text-xs text-slate-500 mt-0.5">
                Dealer-submitted leads cross-referenced with live ShaloTrack app accounts and registered vehicles.
            </p>
        </div>
        <div class="flex items-center gap-3">
            <span class="bg-blue-100 text-blue-900 border border-blue-200 py-1 px-3 rounded-full text-xs font-black">
                {{ $customerAds->count() }} Records
            </span>
            <span class="bg-emerald-100 text-emerald-800 border border-emerald-200 py-1 px-3 rounded-full text-xs font-black">
                {{ $customerAds->filter(fn($c) => $c->appAccount !== null)->count() }} Live on App
            </span>
            <a href="{{ route('admin.dealer-customers.report') }}" target="_blank"
               class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-4 py-2 rounded-lg shadow-sm transition inline-flex items-center gap-1.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Generate Report
            </a>
        </div>
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-slate-50 text-slate-500 uppercase font-extrabold tracking-wider border-b border-slate-200 text-[11px]">
                    <tr>
                        <th class="px-4 py-3">Customer Lead</th>
                        <th class="px-4 py-3">Contact / Email</th>
                        <th class="px-4 py-3">NIC / ID</th>
                        <th class="px-4 py-3">Assigned IMEIs</th>
                        <th class="px-4 py-3">Source Dealer</th>
                        <th class="px-4 py-3 text-center">App Status</th>
                        <th class="px-4 py-3">App Account</th>
                        <th class="px-4 py-3">Vehicles & GPS</th>
                        <th class="px-4 py-3 whitespace-nowrap">Date Added</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @forelse($customerAds as $ad)
                        @php
                            $app      = $ad->appAccount;
                            $vehicles = $ad->appVehicles;
                            $isLive   = $app !== null;
                        @endphp
                        <tr class="hover:bg-slate-50 transition {{ $isLive ? 'border-l-4 border-l-emerald-400' : '' }}">

                            {{-- Customer lead name --}}
                            <td class="px-4 py-3 font-bold text-blue-950">
                                {{ $ad->name }}
                            </td>

                            {{-- Contact + email --}}
                            <td class="px-4 py-3">
                                <div class="font-mono text-[11px] text-slate-700">{{ $ad->contact }}</div>
                                @if($ad->email)
                                    <div class="text-slate-500 text-[10px] truncate max-w-[160px]">{{ $ad->email }}</div>
                                @endif
                            </td>

                            {{-- NIC --}}
                            <td class="px-4 py-3 text-slate-600">{{ $ad->nic_or_id ?: '—' }}</td>

                            {{-- IMEIs --}}
                            <td class="px-4 py-3">
                                @if(!empty($ad->imei_numbers) && count($ad->imei_numbers) > 0)
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($ad->imei_numbers as $imei)
                                            <span class="font-mono text-[10px] bg-slate-100 text-slate-700 px-1.5 py-0.5 rounded">{{ $imei }}</span>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-slate-400 italic text-[11px]">None</span>
                                @endif
                            </td>

                            {{-- Dealer --}}
                            <td class="px-4 py-3">
                                <div class="font-bold text-slate-700">{{ $ad->dealer->full_name ?? 'N/A' }}</div>
                                <div class="text-slate-400 text-[10px]">
                                    {{ ucfirst(str_replace('_', ' ', $ad->dealer->region ?? '')) }}
                                </div>
                            </td>

                            {{-- App status --}}
                            <td class="px-4 py-3 text-center">
                                @if($isLive)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 border border-emerald-300 text-[10px] font-bold">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 inline-block"></span>
                                        Live
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-slate-100 text-slate-500 border border-slate-200 text-[10px] font-bold">
                                        Not Registered
                                    </span>
                                @endif
                            </td>

                            {{-- App account details --}}
                            <td class="px-4 py-3">
                                @if($isLive)
                                    <div class="font-bold text-slate-800">{{ $app->full_name }}</div>
                                    <div class="text-slate-500 text-[10px]">{{ $app->email }}</div>
                                    <div class="text-slate-400 text-[10px] font-mono">{{ $app->phone_number }}</div>
                                @else
                                    <span class="text-slate-400 italic text-[11px]">—</span>
                                @endif
                            </td>

                            {{-- Vehicles --}}
                            <td class="px-4 py-3 min-w-[200px]">
                                @if($isLive && $vehicles->isNotEmpty())
                                    <div class="space-y-1">
                                        @foreach($vehicles as $v)
                                            <div class="flex items-center gap-2 text-[11px]">
                                                <span class="font-bold text-blue-950">{{ $v->vehicle_number ?? 'No plate' }}</span>
                                                <span class="text-slate-500">{{ $v->make }} {{ $v->model }}</span>
                                                @if($v->has_gps_device)
                                                    <span class="px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 text-[10px] font-bold border border-blue-200">
                                                        GPS · {{ $v->imei }}
                                                    </span>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @elseif($isLive)
                                    <span class="text-slate-400 text-[11px] italic">Registered, no vehicles yet</span>
                                @else
                                    <span class="text-slate-300 text-[11px]">—</span>
                                @endif
                            </td>

                            {{-- Date --}}
                            <td class="px-4 py-3 text-slate-400 text-[10px] whitespace-nowrap">
                                {{ $ad->created_at?->format('d M Y') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-8 text-center text-slate-400">
                                No customer records submitted by dealers yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection