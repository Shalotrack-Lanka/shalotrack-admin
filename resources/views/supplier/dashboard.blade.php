@extends('layouts.supplier')

@section('title', 'Supplier Dashboard')

@section('content')

<!-- Welcome Banner -->
<div class="bg-gradient-to-r from-blue-950 to-blue-900 border-b-4 border-orange-500 rounded-3xl p-8 md:p-10 mb-8 shadow-md text-white flex items-center justify-between overflow-hidden relative">
    <div class="relative z-10">
        <h1 class="text-3xl md:text-4xl font-extrabold mb-2 tracking-tight">
            Welcome back, {{ $supplier->name ?? auth()->user()->full_name ?? 'Supplier' }} 👋
        </h1>
        <p class="text-blue-200 text-sm md:text-base opacity-90">Manage your product supply and profile.</p>
    </div>
    <div class="hidden md:block opacity-10 absolute right-10 -bottom-4 transform rotate-12 scale-125 text-orange-400">
        <svg class="w-40 h-40" fill="currentColor" viewBox="0 0 24 24"><path d="M20 7h-4V5c0-1.1-.9-2-2-2h-4c-1.1 0-2 .9-2 2v2H4c-1.1 0-2 .9-2 2v11c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V9c0-1.1-.9-2-2-2zM10 5h4v2h-4V5zm10 13H4V9h16v9z"/></svg>
    </div>
</div>

@if(!$supplier)
    <!-- No Supplier Linked Alert -->
    <div class="bg-amber-50 border border-amber-200 rounded-3xl p-6 flex items-start space-x-4 shadow-sm">
        <div class="bg-amber-100 p-2 rounded-full flex-shrink-0">
            <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
        </div>
        <div>
            <h3 class="text-amber-800 font-bold text-lg">Account Not Linked</h3>
            <p class="text-amber-700 text-sm mt-1">Your account isn't linked to a supplier record yet. Please contact an administrator to link your login to your supplier profile before this dashboard can show your data.</p>
        </div>
    </div>
@else

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">

        <!-- Total Products — REAL -->
        <div class="bg-white p-6 rounded-3xl border-y border-r border-l-4 border-l-blue-950 border-slate-200 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all duration-300 relative overflow-hidden group">
            <div class="absolute -right-4 -bottom-4 opacity-5 group-hover:scale-110 group-hover:text-blue-950 transition-all duration-500">
                <svg class="w-28 h-28" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5 4a3 3 0 00-3 3v6a3 3 0 003 3h10a3 3 0 003-3V7a3 3 0 00-3-3H5zm8 7a1 1 0 11-2 0 1 1 0 012 0z" clip-rule="evenodd"></path></svg>
            </div>
            <div class="flex items-center gap-2 mb-2">
                <div class="w-2 h-6 bg-blue-950 rounded-full"></div>
                <div class="text-xs text-slate-500 uppercase font-bold tracking-wider">My Products</div>
            </div>
            <div class="text-4xl font-black text-blue-950 pl-4 mt-2">{{ $totalProducts }}</div>
        </div>

        <!-- Total Supplied — NOT BUILT: no Order/shipment table exists -->
        <div class="bg-slate-50 p-6 rounded-3xl border-y border-r border-l-4 border-l-slate-300 border-slate-200 opacity-60">
            <div class="flex items-center gap-2 mb-2">
                <div class="w-2 h-6 bg-slate-300 rounded-full"></div>
                <div class="text-xs text-slate-400 uppercase font-bold tracking-wider">Total Supplied</div>
            </div>
            <div class="text-sm text-slate-400 font-semibold pl-4 mt-3">Not tracked yet</div>
        </div>

        <!-- Pending Orders — NOT BUILT -->
        <div class="bg-slate-50 p-6 rounded-3xl border-y border-r border-l-4 border-l-slate-300 border-slate-200 opacity-60">
            <div class="flex items-center gap-2 mb-2">
                <div class="w-2 h-6 bg-slate-300 rounded-full"></div>
                <div class="text-xs text-slate-400 uppercase font-bold tracking-wider">Pending Orders</div>
            </div>
            <div class="text-sm text-slate-400 font-semibold pl-4 mt-3">Feature not built</div>
        </div>

        <!-- Pending Payments — NOT BUILT -->
        <div class="bg-slate-50 p-6 rounded-3xl border-y border-r border-l-4 border-l-slate-300 border-slate-200 opacity-60">
            <div class="flex items-center gap-2 mb-2">
                <div class="w-2 h-6 bg-slate-300 rounded-full"></div>
                <div class="text-xs text-slate-400 uppercase font-bold tracking-wider">Pending Payments</div>
            </div>
            <div class="text-sm text-slate-400 font-semibold pl-4 mt-3">Feature not built</div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">

        <!-- Left Column: Profile -->
        <div class="lg:col-span-4 space-y-8">

            <!-- Supplier Profile Summary — REAL, using actual Supplier fields -->
            <div class="bg-white p-7 rounded-3xl border border-slate-200 shadow-sm relative overflow-hidden">
                <div class="absolute top-0 left-0 w-full h-2 bg-gradient-to-r from-blue-950 to-orange-500"></div>
                <h2 class="font-extrabold text-blue-950 text-base mb-6 uppercase tracking-wider flex items-center mt-2">
                    <span class="bg-blue-100 text-blue-800 p-2 rounded-xl mr-3 border border-blue-200">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                    </span>
                    Company Profile
                </h2>

                <div class="space-y-4 text-sm">
                    <div class="flex justify-between border-b border-slate-100 pb-3">
                        <span class="text-slate-500 font-semibold">Company Name</span>
                        <span class="text-blue-950 font-bold">{{ $supplier->name ?? 'N/A' }}</span>
                    </div>
                    <div class="flex justify-between border-b border-slate-100 pb-3">
                        <span class="text-slate-500 font-semibold">Email</span>
                        <span class="text-blue-950 font-bold">{{ $supplier->email ?? 'N/A' }}</span>
                    </div>
                    <div class="flex justify-between border-b border-slate-100 pb-3">
                        <span class="text-slate-500 font-semibold">Phone</span>
                        <span class="text-blue-950 font-bold">{{ $supplier->phone_number ?? 'N/A' }}</span>
                    </div>
                    <div class="flex justify-between border-b border-slate-100 pb-3">
                        <span class="text-slate-500 font-semibold">Website</span>
                        <span class="text-blue-950 font-bold">{{ $supplier->website ?? 'N/A' }}</span>
                    </div>
                    <div class="flex justify-between border-b border-slate-100 pb-3">
                        <span class="text-slate-500 font-semibold">Address</span>
                        <span class="text-blue-950 font-bold text-right">{{ $supplier->address ?? 'N/A' }}</span>
                    </div>
                    <div class="flex justify-between items-center pt-1">
                        <span class="text-slate-500 font-semibold">Status</span>
                        <span class="bg-green-100 text-green-700 px-3 py-1 rounded-lg text-xs font-black uppercase tracking-wide border border-green-200">{{ $supplier->status ?? 'N/A' }}</span>
                    </div>
                </div>
            </div>

        </div>

        <!-- Right Column: Products -->
        <div class="lg:col-span-8 space-y-8">

            <!-- My Products Table — REAL, via Supplier::products() pivot -->
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">
                <div class="p-6 md:p-8 flex justify-between items-center bg-white border-b border-slate-200">
                    <h2 class="font-extrabold text-blue-950 text-lg uppercase tracking-wider flex items-center">
                        <span class="bg-orange-100 text-orange-600 p-2.5 rounded-xl mr-3 border border-orange-200">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"></path></svg>
                        </span>
                        My Products Supply
                    </h2>
                </div>

                <div class="overflow-x-auto w-full">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="bg-slate-50 text-slate-500 text-xs uppercase font-extrabold tracking-widest border-b border-slate-200">
                            <tr>
                                <th class="px-8 py-5">Product</th>
                                <th class="px-8 py-5">Description</th>
                                <th class="px-8 py-5">Your Price</th>
                                <th class="px-8 py-5">Discount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 text-slate-700 bg-white">
                            @forelse($products as $product)
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="px-8 py-5 font-bold text-blue-950">{{ $product->product_name }}</td>
                                    <td class="px-8 py-5 text-slate-600">{{ $product->description ?? '-' }}</td>
                                    <td class="px-8 py-5 font-bold text-blue-950">Rs. {{ number_format($product->pivot->price, 2) }}</td>
                                    <td class="px-8 py-5 text-slate-600">{{ $product->pivot->discount }}%</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-8 py-10 text-center text-slate-400">
                                        <span class="font-medium text-slate-500">No products added to your catalog yet.</span>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Orders & Invoices — NOT BUILT, honest placeholder instead of fake empty tables -->
            <div class="bg-slate-50 rounded-3xl border border-dashed border-slate-300 p-8 flex items-start space-x-4">
                <div class="p-2 bg-slate-200 rounded-xl text-slate-500 flex-shrink-0">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path></svg>
                </div>
                <div>
                    <h2 class="font-bold text-slate-700 text-sm mb-1">Supply Orders & Invoices — Not Built Yet</h2>
                    <p class="text-xs text-slate-500 leading-relaxed">
                        There's currently no Orders or Invoices system in the database — this needs new tables and
                        a real purchase-order workflow (Admin creates an order → Supplier fulfills → invoice
                        generated) before this section can show real data.
                    </p>
                </div>
            </div>

        </div>
    </div>

@endif

@endsection