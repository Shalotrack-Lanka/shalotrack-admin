@extends('layouts.admin')

@section('title', 'Customers Added by Dealers')

@section('content')
<div class="max-w-7xl mx-auto">

    <!-- Header Section -->
    <div class="mb-8 flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-black text-blue-950">Customers Added by Dealers</h1>
            <p class="text-slate-500 mt-1">Overview of all customer leads submitted by dealers.</p>
        </div>
        <span class="bg-blue-100 text-blue-900 border border-blue-200 py-1.5 px-4 rounded-full text-xs font-black">
            Total Records: {{ $customerAds->count() }}
        </span>
    </div>

    <!-- Table Card -->
    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden mb-10">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm whitespace-nowrap">
                <thead class="bg-slate-50 text-slate-500 text-xs uppercase font-extrabold tracking-widest border-b border-slate-200">
                    <tr>
                        <th class="px-6 py-5">Customer Name</th>
                        <th class="px-6 py-5">Contact</th>
                        <th class="px-6 py-5">NIC / ID</th>
                        <th class="px-6 py-5">Devices Req.</th>
                        <th class="px-6 py-5">Dealer ID</th>
                        <th class="px-6 py-5">Dealer Name</th>
                        <th class="px-6 py-5">Dealer Region</th>
                        <th class="px-6 py-5">Date Added</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-200 bg-white">
                    @forelse($customerAds as $ad)
                        <tr class="hover:bg-slate-50 transition">
                            <!-- Customer Name -->
                            <td class="px-6 py-5 font-bold text-blue-950">
                                {{ $ad->name }}
                            </td>

                            <!-- Customer Contact -->
                            <td class="px-6 py-5 font-mono text-xs text-slate-600">
                                {{ $ad->contact }}
                            </td>

                            <!-- NIC / ID -->
                            <td class="px-6 py-5 text-slate-600">
                                {{ $ad->nic_or_id ?: '-' }}
                            </td>

                            <!-- Devices Count -->
                            <td class="px-6 py-5">
                                <span class="inline-flex items-center justify-center px-3 py-1 rounded-full bg-orange-50 text-orange-600 border border-orange-200 font-black text-xs">
                                    {{ $ad->no_of_devices }}
                                </span>
                            </td>

                            <!-- Dealer ID -->
                            <td class="px-6 py-5 font-mono text-xs text-slate-500">
                                #{{ $ad->dealer_id }}
                            </td>

                            <!-- Dealer Name -->
                            <td class="px-6 py-5 font-bold text-slate-700">
                                {{ $ad->dealer->full_name ?? $ad->dealer->name ?? 'N/A' }}
                            </td>

                            <!-- Dealer Region -->
                            <td class="px-6 py-5 text-slate-600">
                                <span class="bg-slate-100 text-slate-700 px-2.5 py-1 rounded-lg text-xs font-semibold">
                                    {{ $ad->dealer->region ?? $ad->dealer->city ?? 'N/A' }}
                                </span>
                            </td>

                            <!-- Date Added -->
                            <td class="px-6 py-5 text-slate-400 text-xs">
                                {{ $ad->created_at ? $ad->created_at->format('d M Y, h:i A') : '-' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center text-slate-400">
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