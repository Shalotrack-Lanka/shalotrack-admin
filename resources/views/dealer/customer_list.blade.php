@extends('layouts.dealer')

@section('title', 'Customer List')

@section('content')
<div class="max-w-7xl mx-auto">

    {{-- Success Message Alert --}}
    @if(session('success'))
        <div x-data="{ show: true }" 
             x-show="show" 
             x-init="setTimeout(() => show = false, 4000)" 
             class="mb-4 p-3 bg-green-100 border border-green-300 text-green-800 rounded-xl flex justify-between items-center shadow-sm text-xs">
            <div class="flex items-center gap-2">
                <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <span class="font-bold">{{ session('success') }}</span>
            </div>
            <button @click="show = false" class="text-green-600 hover:text-green-800 font-bold">&times;</button>
        </div>
    @endif

    {{-- Page Header --}}
    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-black text-blue-950">Customer List</h1>
            <p class="text-xs text-slate-500 mt-0.5">Manage and view all your requested customer ad details.</p>
        </div>
        <span class="bg-blue-50 text-blue-950 border border-blue-200 py-1 px-3 rounded-full text-xs font-black">
            Total: {{ $customerAds->count() }} Customers
        </span>
    </div>

    {{-- Customer Table Card --}}
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden mb-8">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs whitespace-nowrap">
                <thead class="bg-slate-50 text-slate-500 uppercase font-extrabold tracking-wider border-b border-slate-200 text-[11px]">
                    <tr>
                        <th class="px-4 py-3">Customer Name</th>
                        <th class="px-4 py-3">Contact No</th>
                        <th class="px-4 py-3">NIC / ID</th>
                        <th class="px-4 py-3 text-center">Devices</th>
                        <th class="px-4 py-3">IMEI List</th>
                        <th class="px-4 py-3">Address</th>
                        <th class="px-4 py-3">Date Added</th>
                        <th class="px-4 py-3 text-center">Action</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-200 bg-white">
                    @forelse($customerAds as $customer)
                        <tr class="hover:bg-slate-50 transition">
                            <td class="px-4 py-3 font-bold text-blue-950">{{ $customer->name }}</td>
                            <td class="px-4 py-3 text-slate-600 font-mono text-[11px]">{{ $customer->contact }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $customer->nic_or_id ?: '-' }}</td>

                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex items-center justify-center min-w-[28px] px-2 py-0.5 rounded-full bg-orange-50 text-orange-600 border border-orange-200 font-black text-[11px]">
                                    {{ $customer->no_of_devices }}
                                </span>
                            </td>

                            {{-- IMEI Numbers Dropdown --}}
                            <td class="px-4 py-3">
                                @if(!empty($customer->imei_numbers) && count($customer->imei_numbers) > 0)
                                    <div class="relative min-w-[140px]">
                                        <select class="w-full text-[11px] font-mono bg-slate-50 border border-slate-200 text-slate-700 py-1 px-2 pr-6 rounded-lg focus:ring-1 focus:ring-blue-500 cursor-pointer">
                                            @foreach($customer->imei_numbers as $index => $imei)
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

                            <td class="px-4 py-3 text-slate-500 max-w-[150px] truncate">{{ $customer->address ?: '-' }}</td>
                            <td class="px-4 py-3 text-slate-400 text-[11px]">{{ $customer->created_at ? $customer->created_at->format('d M Y, h:i A') : '-' }}</td>

                            {{-- Action Column --}}
                            <td class="px-4 py-3 text-center">
                                <form action="{{ route('dealer.customer-ad.destroy', $customer->id) }}" method="POST" onsubmit="return confirm('Are you sure you want to delete this customer?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="p-1 text-red-500 hover:text-red-700 hover:bg-red-50 rounded-lg transition" title="Delete Customer">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                        </svg>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-slate-400">
                                No customers added yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            
        </div>
    </div>

</div>
@endsection