@extends('layouts.dealer')

@section('title', 'Dashboard')

@section('content')

<!-- Welcome Banner -->
<div class="bg-gradient-to-r from-blue-950 to-blue-900 border-b-4 border-orange-500 rounded-3xl p-8 md:p-10 mb-8 shadow-md text-white flex items-center justify-between overflow-hidden relative">
    <div class="relative z-10">
        <h1 class="text-3xl md:text-4xl font-extrabold mb-2 tracking-tight">
            Welcome back, {{ auth()->user()->full_name ?? 'Dealer' }} 👋
        </h1>
        <p class="text-blue-200 text-sm md:text-base opacity-90">Here is what's happening with your stock and devices today.</p>
    </div>
    <div class="hidden md:block opacity-10 absolute right-10 -bottom-4 transform rotate-12 scale-125 text-orange-400">
        <svg class="w-40 h-40" fill="currentColor" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/></svg>
    </div>
</div>

@if(!$dealer)
    <!-- No Dealer Linked Alert -->
    <div class="bg-amber-50 border border-amber-300 rounded-3xl p-8 flex items-start space-x-5 shadow-sm">
        <div class="bg-amber-100 p-3 rounded-2xl flex-shrink-0 mt-1">
            <svg class="w-8 h-8 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
        </div>
        <div>
            <h3 class="text-amber-800 font-bold text-xl">Account Not Linked</h3>
            <p class="text-amber-700 text-base mt-2 leading-relaxed">Your account isn't linked to a dealer record yet. Please contact an administrator to link your login to your dealer profile before this dashboard can show your data.</p>
        </div>
    </div>
@else

    <!-- Summary Cards -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-5 mb-10">
        
        <div class="bg-white p-6 rounded-3xl border-y border-r border-l-4 border-l-orange-500 border-slate-200 shadow-sm hover:shadow-lg transition-shadow relative overflow-hidden group">
            <div class="absolute -right-4 -bottom-4 opacity-5 group-hover:scale-110 group-hover:text-orange-500 transition-all duration-500">
                <svg class="w-28 h-28" fill="currentColor" viewBox="0 0 20 20"><path d="M7 3a1 1 0 000 2h6a1 1 0 100-2H7zM4 7a1 1 0 011-1h10a1 1 0 110 2H5a1 1 0 01-1-1zM2 11a2 2 0 012-2h12a2 2 0 012 2v4a2 2 0 01-2 2H4a2 2 0 01-2-2v-4z"></path></svg>
            </div>
            <div class="flex items-center gap-2 mb-2">
                <div class="w-2 h-6 bg-orange-500 rounded-full"></div>
                <div class="text-xs text-slate-500 uppercase font-bold tracking-wider">Available Devices</div>
            </div>
            <div class="text-4xl font-black text-blue-950 pl-4 mt-2">{{ $availableDevices->count() }}</div>
        </div>

        <div class="bg-white p-6 rounded-3xl border-y border-r border-l-4 border-l-blue-950 border-slate-200 shadow-sm hover:shadow-lg transition-shadow relative overflow-hidden group">
            <div class="absolute -right-4 -bottom-4 opacity-5 group-hover:scale-110 group-hover:text-blue-950 transition-all duration-500">
                <svg class="w-28 h-28" fill="currentColor" viewBox="0 0 20 20"><path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z"></path><path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm3 4a1 1 0 000 2h.01a1 1 0 100-2H7zm3 0a1 1 0 000 2h3a1 1 0 100-2h-3zm-3 4a1 1 0 100 2h.01a1 1 0 100-2H7zm3 0a1 1 0 100 2h3a1 1 0 100-2h-3z" clip-rule="evenodd"></path></svg>
            </div>
            <div class="flex items-center gap-2 mb-2">
                <div class="w-2 h-6 bg-blue-950 rounded-full"></div>
                <div class="text-xs text-slate-500 uppercase font-bold tracking-wider">Assigned Devices</div>
            </div>
            <div class="text-4xl font-black text-blue-950 pl-4 mt-2">{{ $allDevices->count() }}</div>
        </div>

        <div class="bg-slate-50 p-6 rounded-3xl border border-slate-200 opacity-80">
            <div class="text-xs text-slate-500 uppercase font-bold mb-1 tracking-wider">Total Customers</div>
            <div class="text-sm text-slate-400 font-medium mt-4 flex items-center"><svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg> Not linked yet</div>
        </div>

        <div class="bg-slate-50 p-6 rounded-3xl border border-slate-200 opacity-80">
            <div class="text-xs text-slate-500 uppercase font-bold mb-1 tracking-wider">Active Vehicles</div>
            <div class="text-sm text-slate-400 font-medium mt-4 flex items-center"><svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg> Not linked yet</div>
        </div>

        <div class="bg-slate-50 p-6 rounded-3xl border border-slate-200 opacity-80">
            <div class="text-xs text-slate-500 uppercase font-bold mb-1 tracking-wider">Stock Requests</div>
            <div class="text-sm text-slate-400 font-medium mt-4 flex items-center"><svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg> Feature pending</div>
        </div>
    </div>

    <!-- Main Grid Content -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">

        <!-- Left Column: Stock Summary + Activity -->
        <div class="lg:col-span-4 space-y-8">

            <!-- Stock Summary -->
            <div id="stock-summary" class="bg-white p-7 rounded-3xl border border-slate-200 shadow-sm">
                <h2 class="font-extrabold text-blue-950 text-base mb-6 uppercase tracking-wider flex items-center">
                    <span class="bg-orange-100 text-orange-600 p-2 rounded-xl mr-3 border border-orange-200">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                    </span>
                    Stock Summary
                </h2>
                
                <div class="space-y-4">
                    <div class="flex justify-between items-center bg-slate-50 hover:bg-slate-100 transition-colors p-4 rounded-2xl border border-slate-200">
                        <span class="text-sm font-semibold text-slate-600">Available Stock</span>
                        <span class="font-black text-2xl text-blue-950">{{ $availableDevices->count() }}</span>
                    </div>
                    
                    <div class="flex justify-between items-center bg-slate-50 hover:bg-slate-100 transition-colors p-4 rounded-2xl border border-slate-200">
                        <span class="text-sm font-semibold text-slate-600">Recently Received</span>
                        <span class="font-black text-2xl text-orange-500">{{ $transfers->take(1)->sum('quantity') }}</span>
                    </div>

                    @if($lowStock)
                        <div class="mt-6 bg-red-50 border border-red-200 text-red-700 text-sm p-4 rounded-2xl font-semibold flex items-start">
                            <svg class="w-5 h-5 mr-2 mt-0.5 flex-shrink-0 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                            Low stock alert! Under {{ $lowStockThreshold }} devices available. Time to restock.
                        </div>
                    @endif
                </div>
            </div>

            <!-- Recent Activities -->
            <div class="bg-white p-7 rounded-3xl border border-slate-200 shadow-sm">
                <h2 class="font-extrabold text-blue-950 text-base mb-6 uppercase tracking-wider flex items-center">
                    <span class="bg-orange-100 text-orange-600 p-2 rounded-xl mr-3 border border-orange-200">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </span>
                    Recent Activities
                </h2>
                
                <div class="space-y-2 mt-2">
                    @forelse($recentActivity as $item)
                        <div class="relative pl-6 pb-6 border-l-2 border-orange-200 last:border-transparent last:pb-0">
                            <div class="absolute w-3 h-3 bg-orange-500 rounded-full -left-[7.5px] top-1 ring-4 ring-white shadow-sm border border-orange-300"></div>
                            <div class="text-[11px] font-bold text-slate-400 mb-1 tracking-wide uppercase">{{ $item['date']->format('d M Y, h:i A') }}</div>
                            <div class="text-sm font-medium text-slate-700">{{ $item['text'] }}</div>
                        </div>
                    @empty
                        <div class="text-sm text-slate-400 text-center py-6 bg-slate-50 rounded-2xl border border-dashed border-slate-300">No recent activity found.</div>
                    @endforelse
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="bg-white p-7 rounded-3xl border border-slate-200 shadow-sm">
                <h2 class="font-extrabold text-blue-950 text-base mb-6 uppercase tracking-wider flex items-center">
                    <span class="bg-orange-100 text-orange-600 p-2 rounded-xl mr-3 border border-orange-200">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    </span>
                    Quick Actions
                </h2>
                <div class="space-y-3">
                    <span class="flex items-center justify-between px-5 py-3.5 rounded-2xl text-xs font-bold bg-slate-50 text-slate-500 border border-slate-200 cursor-not-allowed group transition-colors" title="Not built yet">
                        Add Customer <svg class="w-4 h-4 text-slate-300 group-hover:text-orange-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    </span>
                    <span class="flex items-center justify-between px-5 py-3.5 rounded-2xl text-xs font-bold bg-slate-50 text-slate-500 border border-slate-200 cursor-not-allowed group transition-colors" title="Not built yet">
                        View Customers <svg class="w-4 h-4 text-slate-300 group-hover:text-orange-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    </span>
                    <span class="flex items-center justify-between px-5 py-3.5 rounded-2xl text-xs font-bold bg-slate-50 text-slate-500 border border-slate-200 cursor-not-allowed group transition-colors" title="Not built yet">
                        Assign Device <svg class="w-4 h-4 text-slate-300 group-hover:text-orange-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    </span>
                    <span class="flex items-center justify-between px-5 py-3.5 rounded-2xl text-xs font-bold bg-slate-50 text-slate-500 border border-slate-200 cursor-not-allowed group transition-colors" title="Not built yet">
                        View Vehicles <svg class="w-4 h-4 text-slate-300 group-hover:text-orange-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    </span>
                </div>
            </div>
        </div>

        <!-- Right Column: Devices + Transfer History -->
        <div class="lg:col-span-8 space-y-8">

            <!-- Devices Table -->
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">
                <div class="p-6 md:p-8 flex justify-between items-center bg-white border-b border-slate-200">
                    <h2 class="font-extrabold text-blue-950 text-lg uppercase tracking-wider flex items-center">
                        <span class="bg-orange-100 text-orange-600 p-2.5 rounded-xl mr-3 border border-orange-200">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path></svg>
                        </span>
                        My Assigned Devices
                    </h2>
                    <span class="bg-blue-50 text-blue-950 border border-blue-200 py-1.5 px-4 rounded-full text-xs font-black tracking-wide">{{ $allDevices->count() }} Total</span>
                </div>
                
                <div class="overflow-x-auto w-full">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="bg-slate-50 text-slate-500 text-xs uppercase font-extrabold tracking-widest border-b border-slate-200">
                            <tr>
                                <th class="px-8 py-5">IMEI Number</th>
                                <th class="px-8 py-5">Model Category</th>
                                <th class="px-8 py-5">Current Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 text-slate-700 bg-white">
                            @forelse($allDevices as $device)
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="px-8 py-5 font-bold text-blue-950">{{ $device->imei_number }}</td>
                                    <td class="px-8 py-5 font-medium text-slate-600">{{ $device->device_category }}</td>
                                    <td class="px-8 py-5">
                                        <span class="inline-flex items-center px-3 py-1.5 rounded-lg text-[11px] font-black uppercase tracking-wider bg-orange-50 text-orange-600 border border-orange-200">
                                            {{ $device->status }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-8 py-12 text-center text-slate-400">
                                        <div class="flex flex-col items-center justify-center">
                                            <svg class="w-12 h-12 mb-3 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                                            <span class="font-medium text-slate-500">No devices have been assigned to you yet.</span>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Transfer History Table -->
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">
                <div class="p-6 md:p-8 flex justify-between items-center bg-white border-b border-slate-200">
                    <h2 class="font-extrabold text-blue-950 text-lg uppercase tracking-wider flex items-center">
                        <span class="bg-orange-100 text-orange-600 p-2.5 rounded-xl mr-3 border border-orange-200">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>
                        </span>
                        Recent Stock Transfers
                    </h2>
                </div>
                
                <div class="overflow-x-auto w-full">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="bg-slate-50 text-slate-500 text-xs uppercase font-extrabold tracking-widest border-b border-slate-200">
                            <tr>
                                <th class="px-8 py-5">Transfer Date</th>
                                <th class="px-8 py-5">Device Type</th>
                                <th class="px-8 py-5 text-center">Quantity</th>
                                <th class="px-8 py-5">Remarks</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 text-slate-700 bg-white">
                            @forelse($transfers as $transfer)
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="px-8 py-5 font-semibold text-slate-600">{{ $transfer->created_at->format('d M Y') }}</td>
                                    <td class="px-8 py-5 font-bold text-blue-950">{{ $transfer->stock->deviceType->model ?? '-' }}</td>
                                    <td class="px-8 py-5 text-center">
                                        <span class="font-black text-orange-600 bg-orange-50 px-3.5 py-1.5 rounded-xl border border-orange-100">{{ $transfer->quantity }}</span>
                                    </td>
                                    <td class="px-8 py-5 text-slate-500">{{ $transfer->remarks ?? '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-8 py-12 text-center text-slate-400">
                                        <div class="flex flex-col items-center justify-center">
                                            <svg class="w-12 h-12 mb-3 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                                            <span class="font-medium text-slate-500">No stock transfers recorded yet.</span>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Future Feature Notice -->
            <div class="bg-slate-50 rounded-3xl border-2 border-dashed border-slate-300 p-8 flex items-start space-x-5">
                <div class="p-3 bg-white shadow-sm border border-slate-200 rounded-2xl text-slate-500 flex-shrink-0">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path></svg>
                </div>
                <div>
                    <h2 class="font-extrabold text-blue-950 text-base mb-2">Customers & Vehicles API Setup Pending</h2>
                    <p class="text-sm text-slate-600 leading-relaxed font-medium">
                        Customer & Vehicle management is not available yet. There is currently no link between Dealers and Customers/Vehicles in the system architecture. Customer data lives on the ShaloTrack API's database, which has no Dealer field on any Customer or Vehicle record. This requires an API-side schema change before features can be unlocked.
                    </p>
                </div>
            </div>

        </div>
    </div>

@endif

@endsection