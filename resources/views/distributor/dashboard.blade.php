@extends('layouts.distributor')

@section('title', 'Distributor Dashboard')

@section('content')
@php
    $region = ucfirst(str_replace('_', ' ', (string) $distributor->region));
    $cards = [
        ['label' => 'My stock (ready to pass on)', 'value' => $ownStock, 'hint' => 'Activated devices in your hands that no one has received yet', 'link' => route('distributor.stock', ['availability' => 'available']), 'tone' => 'blue',
            'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
        ['label' => 'Sold from my own stock', 'value' => $ownSold, 'hint' => 'Devices you sold straight to a customer', 'link' => null, 'tone' => 'emerald',
            'icon' => 'M5 13l4 4L19 7'],
        ['label' => 'Dealers under me', 'value' => $dealerCount, 'hint' => 'Dealers that report to you', 'link' => route('distributor.dealers'), 'tone' => 'indigo',
            'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
        ['label' => "Devices in my dealers' stock", 'value' => $dealerStock, 'hint' => 'Waiting to be sold by your dealers', 'link' => route('distributor.dealers'), 'tone' => 'amber',
            'icon' => 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
        ['label' => 'Devices my dealers sold', 'value' => $dealerSold, 'hint' => 'Sold to customers across your network', 'link' => route('distributor.dealers'), 'tone' => 'emerald',
            'icon' => 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6'],
        ['label' => 'Customers of my dealers', 'value' => $dealerCustomers, 'hint' => 'View only. Dealers manage their own customers', 'link' => route('distributor.dealers'), 'tone' => 'slate',
            'icon' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
    ];
    $tones = [
        'blue' => 'bg-blue-50 text-blue-600', 'emerald' => 'bg-emerald-50 text-emerald-600', 'indigo' => 'bg-indigo-50 text-indigo-600',
        'amber' => 'bg-amber-50 text-amber-600', 'slate' => 'bg-slate-100 text-slate-600',
    ];
@endphp

<div class="max-w-7xl mx-auto space-y-8">

    @include('distributor.partials.alerts')

    {{-- WELCOME --}}
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6 bg-gradient-to-r from-[#0B1B3F] via-slate-900 to-[#102A63] p-8 rounded-3xl text-white shadow-xl relative overflow-hidden">
        <div class="absolute -right-10 -bottom-10 w-48 h-48 bg-blue-500/10 rounded-full blur-2xl pointer-events-none"></div>
        <div class="relative z-10 max-w-xl">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-500/20 text-blue-300 text-xs font-semibold mb-3 border border-blue-400/20">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                Distributor Portal Active &middot; {{ $region }}
            </div>
            <h1 class="text-2xl sm:text-3xl font-black tracking-tight">Welcome back, {{ $distributor->full_name }} 👋</h1>
            <p class="text-slate-300 text-sm mt-1 leading-relaxed">
                See your stock and your dealers at a glance, and pass devices on to your dealers in a few clicks.
            </p>
        </div>
        <div class="relative z-10 shrink-0 w-full sm:w-auto flex flex-col sm:flex-row gap-3">
            <a href="{{ route('distributor.transfer') }}"
               class="bg-blue-600 hover:bg-blue-500 text-white font-bold py-3.5 px-6 rounded-2xl shadow-lg hover:shadow-blue-500/30 transition flex items-center justify-center gap-2.5 border border-blue-400/30">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M8 7h12m0 0l-4-4m4 4l-4 4M16 17H4m0 0l4-4m-4 4l4 4"></path></svg>
                <span class="text-sm font-extrabold tracking-wide">Transfer devices</span>
            </a>
            <a href="{{ route('distributor.dealers') }}"
               class="bg-white/10 hover:bg-white/20 text-white font-bold py-3.5 px-6 rounded-2xl transition flex items-center justify-center border border-white/20 text-sm">
                My dealers
            </a>
        </div>
    </div>

    {{-- WHAT NEEDS ATTENTION --}}
    @if ($ownStock === 0 || $dealerCount === 0 || $dealersWithoutStock > 0)
        <div class="rounded-3xl border border-amber-200 bg-amber-50 p-5 space-y-2">
            <p class="text-xs font-black uppercase tracking-wider text-amber-800">Needs your attention</p>
            <ul class="text-sm text-amber-900 space-y-1.5 list-disc ml-5">
                @if ($ownStock === 0)
                    <li>You have <strong>no stock</strong> to pass on. Devices appear here once the company transfers them to you.</li>
                @endif
                @if ($dealerCount === 0)
                    <li>No dealers report to you yet. Ask the company to attach your dealers to your account.</li>
                @endif
                @if ($dealersWithoutStock > 0)
                    <li><strong>{{ $dealersWithoutStock }}</strong> of your active dealers {{ $dealersWithoutStock === 1 ? 'has' : 'have' }} no devices left to sell.
                        <a href="{{ route('distributor.dealers') }}" class="font-semibold underline">See who</a>.</li>
                @endif
            </ul>
        </div>
    @endif

    {{-- METRICS --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
        @foreach ($cards as $c)
            <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm hover:shadow-md transition relative overflow-hidden group">
                <div class="flex justify-between items-start">
                    <div>
                        <div class="text-xs text-slate-500 uppercase font-bold tracking-wider">{{ $c['label'] }}</div>
                        <div class="text-4xl font-black text-blue-950 mt-3">{{ number_format($c['value']) }}</div>
                    </div>
                    <div class="p-3 {{ $tones[$c['tone']] }} rounded-2xl group-hover:scale-110 transition">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $c['icon'] }}"></path></svg>
                    </div>
                </div>
                <p class="text-xs text-slate-500 mt-3">{{ $c['hint'] }}</p>
                @if ($c['link'])
                    <a href="{{ $c['link'] }}" class="mt-2 inline-block text-xs font-semibold text-blue-700 hover:underline">View &rarr;</a>
                @endif
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">

        {{-- STOCK BY CATEGORY --}}
        <div class="lg:col-span-2 bg-white rounded-3xl border border-slate-200 shadow-sm p-6">
            <h3 class="font-bold text-slate-900">My stock by device type</h3>
            <p class="text-xs text-slate-500 mb-4">Devices you can pass on right now.</p>
            @forelse ($stockByCategory as $category => $count)
                <div class="mb-3">
                    <div class="flex justify-between text-sm"><span class="font-medium text-slate-700">{{ $category }}</span><span class="font-bold text-blue-950">{{ number_format($count) }}</span></div>
                    <div class="h-2 rounded-full bg-slate-100 mt-1"><div class="h-2 rounded-full bg-blue-600" style="width: {{ max(4, round($count / max($ownStock, 1) * 100)) }}%"></div></div>
                </div>
            @empty
                <p class="text-sm text-slate-400 py-6 text-center">No devices in your stock.</p>
            @endforelse
        </div>

        {{-- RECENT TRANSFERS --}}
        <div class="lg:col-span-3 bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center">
                <div>
                    <h3 class="font-bold text-slate-900">Recent transfers</h3>
                    <p class="text-xs text-slate-500">What you received and what you sent on.</p>
                </div>
                <a href="{{ route('distributor.transfers') }}" class="text-xs font-semibold text-blue-700 hover:underline">All history &rarr;</a>
            </div>
            <div class="divide-y divide-slate-100">
                @forelse ($recent as $t)
                    @php $sentByMe = (int) $t->from_dealer_id === (int) $distributor->id; @endphp
                    <a href="{{ route('distributor.transfers.show', $t) }}" class="flex items-center justify-between gap-3 px-6 py-3 hover:bg-slate-50 transition">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="shrink-0 rounded-full px-2.5 py-1 text-[10px] font-black uppercase {{ $sentByMe ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' }}">{{ $sentByMe ? 'Sent' : 'Received' }}</span>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-slate-800 truncate">
                                    {{ $t->quantity }} &times; {{ $t->device_category }}
                                    {{ $sentByMe ? 'to ' . ($t->dealer->full_name ?? '-') : 'from the company' }}
                                </p>
                                <p class="text-xs text-slate-500">{{ $t->created_at->local()->format('Y-m-d h:i A') }}</p>
                            </div>
                        </div>
                        <span class="text-slate-400">&rsaquo;</span>
                    </a>
                @empty
                    <p class="text-sm text-slate-400 py-10 text-center">No transfers yet.</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- DEALERS OVERVIEW --}}
    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center">
            <div>
                <h3 class="font-bold text-slate-900">My dealers at a glance</h3>
                <p class="text-xs text-slate-500">Dealers with the least stock come first, so you can see who needs devices.</p>
            </div>
            <a href="{{ route('distributor.dealers') }}" class="text-xs font-semibold text-blue-700 hover:underline">All dealers &rarr;</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-600">
                    <tr><th class="p-4">Dealer</th><th class="p-4 text-center">In stock</th><th class="p-4 text-center">Sold</th><th class="p-4 text-center">Customers</th><th class="p-4"></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($dealers->sortBy('in_stock')->take(5) as $d)
                        <tr class="hover:bg-slate-50">
                            <td class="p-4 font-semibold text-slate-900">{{ $d->full_name }}</td>
                            <td class="p-4 text-center">
                                <span class="inline-block min-w-[2rem] rounded-full px-2.5 py-0.5 text-xs font-bold {{ $d->in_stock === 0 ? 'bg-red-100 text-red-700' : 'bg-blue-50 text-blue-700' }}">{{ $d->in_stock }}</span>
                            </td>
                            <td class="p-4 text-center">{{ $d->sold }}</td>
                            <td class="p-4 text-center">{{ $d->customers }}</td>
                            <td class="p-4 text-right">
                                @if ($d->status === 'active')
                                    <a href="{{ route('distributor.transfer', ['dealer' => $d->id]) }}" class="text-xs font-bold text-blue-700 hover:underline">Send devices</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-8 text-center text-slate-400">No dealers report to you yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-xs text-slate-500">Billing, device activation and renewals, and the device command center for your network are being added in the next steps. They are listed as "Coming soon" in the menu.</p>
</div>
@endsection
