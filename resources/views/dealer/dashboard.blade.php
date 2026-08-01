@extends('layouts.dealer')

@section('title', 'Dashboard')

@section('content')

<!-- Welcome Banner -->
<div class="bg-gradient-to-r from-cyan-600 to-blue-700 rounded-2xl p-6 md:p-8 mb-6 shadow-sm text-white flex items-center justify-between">
    <div>
        <h1 class="text-2xl md:text-3xl font-bold mb-1 tracking-tight">
            Welcome back, {{ auth()->user()->full_name ?? 'Dealer' }} 👋
        </h1>
        <p class="text-cyan-100 text-sm">Here is what's happening with your stock and devices today.</p>
    </div>
    <div class="hidden md:block opacity-20">
        <svg class="w-20 h-20" fill="currentColor" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/></svg>
    </div>
</div>

@if(!$dealer)
    <!-- No Dealer Linked Alert -->
    <div class="bg-amber-50 border border-amber-200 rounded-2xl p-6 flex items-start space-x-4 shadow-sm">
        <div class="bg-amber-100 p-2 rounded-full flex-shrink-0">
            <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
        </div>
        <div>
            <h3 class="text-amber-800 font-bold text-lg">Account Not Linked</h3>
            <p class="text-amber-700 text-sm mt-1">Your account isn't linked to a dealer record yet. Please contact an administrator to link your login to your dealer profile before this dashboard can show your data.</p>
        </div>
    </div>
@else

    <!-- Summary Cards -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
        
        <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm hover:shadow-md transition-shadow relative overflow-hidden group">
            <div class="absolute -right-4 -bottom-4 opacity-5 group-hover:scale-110 transition-transform">
                <svg class="w-24 h-24" fill="currentColor" viewBox="0 0 20 20"><path d="M7 3a1 1 0 000 2h6a1 1 0 100-2H7zM4 7a1 1 0 011-1h10a1 1 0 110 2H5a1 1 0 01-1-1zM2 11a2 2 0 012-2h12a2 2 0 012 2v4a2 2 0 01-2 2H4a2 2 0 01-2-2v-4z"></path></svg>
            </div>
            <div class="text-xs text-gray-500 uppercase font-bold mb-1">Available Devices</div>
            <div class="text-3xl font-extrabold text-cyan-600">{{ $availableDevices->count() }}</div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm hover:shadow-md transition-shadow relative overflow-hidden group">
            <div class="absolute -right-4 -bottom-4 opacity-5 group-hover:scale-110 transition-transform">
                <svg class="w-24 h-24" fill="currentColor" viewBox="0 0 20 20"><path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z"></path><path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm3 4a1 1 0 000 2h.01a1 1 0 100-2H7zm3 0a1 1 0 000 2h3a1 1 0 100-2h-3zm-3 4a1 1 0 100 2h.01a1 1 0 100-2H7zm3 0a1 1 0 100 2h3a1 1 0 100-2h-3z" clip-rule="evenodd"></path></svg>
            </div>
            <div class="text-xs text-gray-500 uppercase font-bold mb-1">Assigned Devices</div>
            <div class="text-3xl font-extrabold text-gray-800">{{ $allDevices->count() }}</div>
        </div>

        <div class="bg-gray-50 p-5 rounded-2xl border border-gray-200 opacity-60">
            <div class="text-xs text-gray-400 uppercase font-bold mb-1">Total Customers</div>
            <div class="text-sm text-gray-400 font-medium mt-2 flex items-center"><svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg> Not linked yet</div>
        </div>

        <div class="bg-gray-50 p-5 rounded-2xl border border-gray-200 opacity-60">
            <div class="text-xs text-gray-400 uppercase font-bold mb-1">Active Vehicles</div>
            <div class="text-sm text-gray-400 font-medium mt-2 flex items-center"><svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg> Not linked yet</div>
        </div>

        <div class="bg-gray-50 p-5 rounded-2xl border border-gray-200 opacity-60">
            <div class="text-xs text-gray-400 uppercase font-bold mb-1">Pending Stock Requests</div>
            <div class="text-sm text-gray-400 font-medium mt-2 flex items-center"><svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg> Feature pending</div>
        </div>
    </div>

    <!-- Main Grid Content -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Left Column: Stock Summary + Activity -->
        <div class="lg:col-span-1 space-y-6">

            <!-- Stock Summary -->
            <div id="stock-summary" class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-5 uppercase tracking-wide flex items-center">
                    <svg class="w-4 h-4 mr-2 text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                    Stock Summary
                </h2>
                
                <div class="space-y-4">
                    <div class="flex justify-between items-center bg-gray-50 p-3 rounded-xl border border-gray-100">
                        <span class="text-sm font-medium text-gray-600">Available Stock</span>
                        <span class="font-bold text-lg text-gray-900 bg-white px-3 py-1 rounded-lg border border-gray-200">{{ $availableDevices->count() }}</span>
                    </div>
                    
                    <div class="flex justify-between items-center bg-gray-50 p-3 rounded-xl border border-gray-100">
                        <span class="text-sm font-medium text-gray-600">Recently Received</span>
                        <span class="font-bold text-lg text-cyan-600 bg-white px-3 py-1 rounded-lg border border-cyan-100">{{ $transfers->take(1)->sum('quantity') }}</span>
                    </div>

                    @if($lowStock)
                        <div class="mt-4 bg-red-50 border-l-4 border-red-500 text-red-700 text-xs p-3 rounded-r-lg font-semibold flex items-start">
                            <svg class="w-4 h-4 mr-1.5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                            Low stock alert! Under {{ $lowStockThreshold }} devices available. Time to restock.
                        </div>
                    @endif
                </div>
            </div>

            <!-- Recent Activities -->
            <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-5 uppercase tracking-wide flex items-center">
                    <svg class="w-4 h-4 mr-2 text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    Recent Activities
                </h2>
                
                <div class="space-y-0">
                    @forelse($recentActivity as $item)
                        <div class="relative pl-4 pb-4 border-l-2 border-cyan-100 last:border-transparent last:pb-0">
                            <div class="absolute w-2.5 h-2.5 bg-cyan-400 rounded-full -left-[5.5px] top-1.5 ring-4 ring-white"></div>
                            <div class="text-[11px] font-semibold text-gray-400 mb-0.5">{{ $item['date']->format('d M Y, h:i A') }}</div>
                            <div class="text-xs text-gray-700">{{ $item['text'] }}</div>
                        </div>
                    @empty
                        <div class="text-sm text-gray-400 text-center py-4 bg-gray-50 rounded-xl border border-dashed border-gray-200">No recent activity found.</div>
                    @endforelse
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-4 uppercase tracking-wide flex items-center">
                    <svg class="w-4 h-4 mr-2 text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    Quick Actions
                </h2>
                <div class="space-y-2.5">
                    <span class="flex items-center justify-between px-4 py-2.5 rounded-xl text-xs font-semibold bg-gray-50 text-gray-400 border border-gray-100 cursor-not-allowed" title="Not built yet">
                        Add Customer <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    </span>
                    <span class="flex items-center justify-between px-4 py-2.5 rounded-xl text-xs font-semibold bg-gray-50 text-gray-400 border border-gray-100 cursor-not-allowed" title="Not built yet">
                        View Customers <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    </span>
                    <span class="flex items-center justify-between px-4 py-2.5 rounded-xl text-xs font-semibold bg-gray-50 text-gray-400 border border-gray-100 cursor-not-allowed" title="Not built yet">
                        Assign Device <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    </span>
                    <span class="flex items-center justify-between px-4 py-2.5 rounded-xl text-xs font-semibold bg-gray-50 text-gray-400 border border-gray-100 cursor-not-allowed" title="Not built yet">
                        View Vehicles <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    </span>
                </div>
            </div>
        </div>

        <!-- Right Column: Devices + Transfer History -->
        <div class="lg:col-span-2 space-y-6">

            <!-- Devices Table -->
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden flex flex-col">
                <div class="p-5 md:p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                    <h2 class="font-bold text-gray-800 text-sm uppercase tracking-wide flex items-center">
                        <svg class="w-4 h-4 mr-2 text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path></svg>
                        My Assigned Devices
                    </h2>
                    <span class="bg-cyan-100 text-cyan-700 py-1 px-3 rounded-full text-xs font-bold">{{ $allDevices->count() }} Total</span>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="bg-gray-50 text-gray-500 text-xs uppercase font-semibold">
                            <tr>
                                <th class="px-6 py-4">IMEI Number</th>
                                <th class="px-6 py-4">Model Category</th>
                                <th class="px-6 py-4">Current Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-gray-700">
                            @forelse($allDevices as $device)
                                <tr class="hover:bg-gray-50/50 transition-colors">
                                    <td class="px-6 py-3.5 font-medium text-gray-900">{{ $device->imei_number }}</td>
                                    <td class="px-6 py-3.5">{{ $device->device_category }}</td>
                                    <td class="px-6 py-3.5">
                                        <!-- Optional: You can style the status based on its value if you want, but a generic pill works well too -->
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-md text-[11px] font-bold uppercase tracking-wider bg-gray-100 text-gray-600 border border-gray-200">
                                            {{ $device->status }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-6 py-8 text-center text-gray-400 bg-gray-50/30">
                                        <div class="flex flex-col items-center justify-center">
                                            <svg class="w-8 h-8 mb-2 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                                            No devices have been assigned to you yet.
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Transfer History Table -->
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden flex flex-col">
                <div class="p-5 md:p-6 border-b border-gray-100 bg-gray-50/50">
                    <h2 class="font-bold text-gray-800 text-sm uppercase tracking-wide flex items-center">
                        <svg class="w-4 h-4 mr-2 text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>
                        Recent Stock Transfers
                    </h2>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="bg-gray-50 text-gray-500 text-xs uppercase font-semibold">
                            <tr>
                                <th class="px-6 py-4">Transfer Date</th>
                                <th class="px-6 py-4">Device Type</th>
                                <th class="px-6 py-4 text-center">Quantity</th>
                                <th class="px-6 py-4">Remarks</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-gray-700">
                            @forelse($transfers as $transfer)
                                <tr class="hover:bg-gray-50/50 transition-colors">
                                    <td class="px-6 py-3.5 font-medium text-gray-600">{{ $transfer->created_at->format('d M Y') }}</td>
                                    <td class="px-6 py-3.5">{{ $transfer->stock->deviceType->model ?? '-' }}</td>
                                    <td class="px-6 py-3.5 text-center">
                                        <span class="font-bold text-cyan-700 bg-cyan-50 px-2 py-0.5 rounded">{{ $transfer->quantity }}</span>
                                    </td>
                                    <td class="px-6 py-3.5 text-gray-500 text-xs">{{ $transfer->remarks ?? '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-8 text-center text-gray-400 bg-gray-50/30">
                                        No stock transfers recorded yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Future Feature Notice -->
            <div class="bg-gray-50 rounded-2xl border border-dashed border-gray-300 p-6 flex items-start space-x-4">
                <div class="p-2 bg-gray-200 rounded-lg text-gray-500 flex-shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path></svg>
                </div>
                <div>
                    <h2 class="font-bold text-gray-700 text-sm mb-1">Customers & Vehicles API Setup Pending</h2>
                    <p class="text-xs text-gray-500 leading-relaxed">
                        Customer & Vehicle management is not available yet. There is currently no link between Dealers and Customers/Vehicles in the system architecture. Customer data lives on the ShaloTrack API's database, which has no Dealer field on any Customer or Vehicle record. This requires an API-side schema change before features can be unlocked.
                    </p>
                </div>
            </div>

        </div>
    </div>

@endif

@endsection