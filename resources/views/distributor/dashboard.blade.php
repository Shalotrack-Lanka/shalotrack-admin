@extends('layouts.distributor')

@section('title', 'Distributor dashboard')

@section('content')
<div class="space-y-6">
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
        <h1 class="text-xl font-bold text-slate-900">{{ $distributor->full_name }}</h1>
        <p class="text-sm text-slate-500">Distributor &middot; {{ ucfirst(str_replace('_', ' ', (string) $distributor->region)) }}</p>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach ([
            ['My stock (ready to pass on)', $ownStock, route('distributor.stock')],
            ['Sold from my own stock', $ownSold, null],
            ['Dealers under me', $dealerCount, route('distributor.dealers')],
            ['Devices in my dealers\' stock', $dealerStock, route('distributor.dealers')],
            ['Devices my dealers sold', $dealerSold, route('distributor.dealers')],
            ['Customers of my dealers', $dealerCustomers, route('distributor.dealers')],
        ] as [$label, $value, $link])
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</p>
                <p class="mt-2 text-3xl font-black text-blue-950">{{ number_format($value) }}</p>
                @if ($link)<a href="{{ $link }}" class="mt-2 inline-block text-xs text-cyan-700 hover:underline">View</a>@endif
            </div>
        @endforeach
    </div>

    <p class="text-xs text-slate-500">This portal is read-only for now. Stock transfers, billing and the device command center are coming in later steps.</p>
</div>
@endsection