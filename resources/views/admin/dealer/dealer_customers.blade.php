@extends('layouts.admin')

@section('title', 'Customers Added by Dealers')

@section('content')
<div class="max-w-7xl mx-auto">

    <!-- Header Sections -->
    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-black text-blue-950">Customers Added by Dealers</h1>
            <p class="text-xs text-slate-500 mt-0.5">Overview of all customer leads submitted by dealers.</p>
        </div>
        <span class="bg-blue-100 text-blue-900 border border-blue-200 py-1 px-3 rounded-full text-xs font-black">
            Total Records: {{ $customerAds->count() }}
        </span>
        
    <a href="{{ route('admin.dealer-customers.report') }}" target="_blank" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-4 py-2 rounded-lg shadow-sm transition inline-flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
        Generate Report
    </a>
    </div>


    <!-- Table Card -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden mb-8">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs whitespace-nowrap">
                <thead class="bg-slate-50 text-slate-500 uppercase font-extrabold tracking-wider border-b border-slate-200 text-[11px]">
                    <tr>
                        <th class="px-4 py-3">Customer Name</th>
                        <th class="px-4 py-3">Contact</th>
                        <th class="px-4 py-3">NIC / ID</th>
                        <th class="px-4 py-3 text-center">Devices</th>
                        <th class="px-4 py-3">IMEI List</th> {{-- Added IMEI Column --}}
                        <th class="px-4 py-3">Dealer ID</th>
                        <th class="px-4 py-3">Dealer Name</th>
                        <th class="px-4 py-3">Dealer Region</th>
                        <th class="px-4 py-3">Date Added</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-200 bg-white">
                    @forelse($customerAds as $ad)
                        <tr class="hover:bg-slate-50 transition">
                            <!-- Customer Name -->
                            <td class="px-4 py-3 font-bold text-blue-950">
                                {{ $ad->name }}
                            </td>

                            <!-- Customer Contact -->
                            <td class="px-4 py-3 font-mono text-[11px] text-slate-600">
                                {{ $ad->contact }}
                            </td>

                            <!-- NIC / ID -->
                            <td class="px-4 py-3 text-slate-600">
                                {{ $ad->nic_or_id ?: '-' }}
                            </td>

                            <!-- Devices Count -->
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex items-center justify-center min-w-[28px] px-2 py-0.5 rounded-full bg-orange-50 text-orange-600 border border-orange-200 font-black text-[11px]">
                                    {{ $ad->no_of_devices }}
                                </span>
                            </td>

                            <!-- IMEI Numbers Dropdown -->
                            <td class="px-4 py-3">
                                @if(!empty($ad->imei_numbers) && count($ad->imei_numbers) > 0)
                                    <div class="relative min-w-[130px]">
                                        <select class="w-full text-[11px] font-mono bg-slate-50 border border-slate-200 text-slate-700 py-1 px-2 pr-6 rounded-lg focus:ring-1 focus:ring-blue-500 cursor-pointer">
                                            @foreach($ad->imei_numbers as $index => $imei)
                                                <option value="{{ $imei }}">
                                                    IMEI {{ $index + 1 }}: {{ $imei }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                @else
                                    <span class="text-slate-400 italic text-[11px]">No IMEIs</span>
                                @endif
                            </td>

                            <!-- Dealer ID -->
                            <td class="px-4 py-3 font-mono text-[11px] text-slate-500">
                                #{{ $ad->dealer_id }}
                            </td>

                            <!-- Dealer Name -->
                            <td class="px-4 py-3 font-bold text-slate-700">
                                {{ $ad->dealer->full_name ?? $ad->dealer->name ?? 'N/A' }}
                            </td>

                            <!-- Dealer Region -->
                            <td class="px-4 py-3 text-slate-600">
                                <span class="bg-slate-100 text-slate-700 px-2 py-0.5 rounded-md text-[11px] font-semibold">
                                    {{ $ad->dealer->region ?? $ad->dealer->city ?? 'N/A' }}
                                </span>
                            </td>

                            <!-- Date Added -->
                            <td class="px-4 py-3 text-slate-400 text-[11px]">
                                {{ $ad->created_at ? $ad->created_at->format('d M Y, h:i A') : '-' }}
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