@extends('layouts.dealer')

@section('title', 'Customer List')

@section('content')
<div class="max-w-7xl mx-auto">

    {{-- Page Header --}}
    <div class="mb-8 flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-black text-blue-950">Customer List</h1>
            <p class="text-slate-500 mt-1">Manage and view all your requested customer ad details.</p>
        </div>
        <span class="bg-blue-50 text-blue-950 border border-blue-200 py-1.5 px-4 rounded-full text-xs font-black">
            Total: {{ $customerAds->count() }} Customers
        </span>
    </div>

    {{-- Customer Table Card --}}
    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden mb-10">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm whitespace-nowrap">
                <thead class="bg-slate-50 text-slate-500 text-xs uppercase font-extrabold tracking-widest border-b border-slate-200">
                    <tr>
                        <th class="px-8 py-5">Customer Name</th>
                        <th class="px-8 py-5">Contact No</th>
                        <th class="px-8 py-5">NIC / ID</th>
                        <th class="px-8 py-5">No of Devices</th>
                        <th class="px-8 py-5">Address</th>
                        <th class="px-8 py-5">Date Added</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-200 bg-white">
                    @forelse($customerAds as $customer)
                        <tr class="hover:bg-slate-50 transition">
                            {{-- Name --}}
                            <td class="px-8 py-5 font-bold text-blue-950">
                                {{ $customer->name }}
                            </td>

                            {{-- Contact --}}
                            <td class="px-8 py-5 text-slate-600 font-mono text-xs">
                                {{ $customer->contact }}
                            </td>

                            {{-- NIC / ID --}}
                            <td class="px-8 py-5 text-slate-600">
                                {{ $customer->nic_or_id ?: '-' }}
                            </td>

                            {{-- No of Devices --}}
                            <td class="px-8 py-5">
                                <span class="inline-flex items-center justify-center min-w-[35px] px-3 py-1 rounded-full bg-orange-50 text-orange-600 border border-orange-200 font-black text-xs">
                                    {{ $customer->no_of_devices }}
                                </span>
                            </td>

                            {{-- Address --}}
                            <td class="px-8 py-5 text-slate-500 max-w-xs truncate">
                                {{ $customer->address ?: '-' }}
                            </td>

                            {{-- Date --}}
                            <td class="px-8 py-5 text-slate-400 text-xs">
                                {{ $customer->created_at ? $customer->created_at->format('d M Y, h:i A') : '-' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-8 py-12 text-center text-slate-400">
                                No customers added yet. Click "Add Customer" to add a new one.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection