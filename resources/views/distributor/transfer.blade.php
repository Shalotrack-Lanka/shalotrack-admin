@extends('layouts.distributor')

@section('title', 'Transfer to a Dealer')

@section('content')
@php
    $catById = [];
    foreach ($stock as $cat => $group) {
        foreach ($group as $d) { $catById[(string) $d->shdevice_id] = $cat; }
    }
    $dealerNames = $dealers->pluck('full_name', 'id');
@endphp

<div class="max-w-7xl mx-auto space-y-6"
     x-data="{
        dealer: @js($preselected ? (string) $preselected : (string) old('dealer_id', '')),
        dealerNames: @js($dealerNames),
        catById: @js($catById),
        selected: @js(array_map('strval', (array) old('device_ids', []))),
        confirming: false,
        submitting: false,
        get dealerName() { return this.dealerNames[this.dealer] || ''; },
        get summary() {
            const m = {};
            this.selected.forEach(id => { const c = this.catById[id] || 'Other'; m[c] = (m[c] || 0) + 1; });
            return Object.entries(m);
        },
        toggleCat(el, on) {
            el.querySelectorAll('[data-row]').forEach(r => {
                if (r.offsetParent === null) return;
                const id = r.dataset.id, i = this.selected.indexOf(id);
                if (on && i < 0) this.selected.push(id);
                if (!on && i >= 0) this.selected.splice(i, 1);
            });
        },
        catSelected(cat) { return this.selected.filter(id => this.catById[id] === cat).length; }
     }">

    @include('distributor.partials.alerts')

    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
        <div>
            <h1 class="text-2xl font-black text-slate-900">Transfer devices to a dealer</h1>
            <p class="text-sm text-slate-500">Pass devices from your own stock to one of your dealers. Once sent, a transfer cannot be edited; the company handles corrections.</p>
        </div>
        <a href="{{ route('distributor.transfers') }}" class="text-sm font-semibold text-blue-700 hover:underline">View transfer history &rarr;</a>
    </div>

    @if ($stockTotal === 0)
        <div class="rounded-3xl border border-slate-200 bg-white p-10 text-center shadow-sm">
            <p class="text-lg font-bold text-slate-800">You have no stock to transfer</p>
            <p class="text-sm text-slate-500 mt-1">Devices appear here once the company transfers them to you. Only activated, unsold devices can be passed on.</p>
        </div>
    @elseif ($dealers->isEmpty())
        <div class="rounded-3xl border border-slate-200 bg-white p-10 text-center shadow-sm">
            <p class="text-lg font-bold text-slate-800">You have no active dealers yet</p>
            <p class="text-sm text-slate-500 mt-1">Ask the company to attach your dealers to your account, then come back here.</p>
        </div>
    @else
        <form method="POST" action="{{ route('distributor.transfer.store') }}" @submit="submitting = true" class="space-y-6 pb-28">
            @csrf

            {{-- STEP 1 --}}
            <section class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6">
                <h2 class="font-bold text-slate-900"><span class="inline-flex w-6 h-6 items-center justify-center rounded-full bg-[#0B1B3F] text-white text-xs mr-2">1</span>Choose the dealer</h2>
                <p class="text-xs text-slate-500 mb-4 ml-8">The dealer's current stock is shown so you can see who needs devices most.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach ($dealers as $d)
                        @php $n = (int) ($dealerStock[$d->id] ?? 0); @endphp
                        <label class="cursor-pointer rounded-2xl border p-4 transition flex items-start gap-3"
                               :class="dealer === '{{ $d->id }}' ? 'border-blue-600 bg-blue-50 ring-2 ring-blue-200' : 'border-slate-200 hover:bg-slate-50'">
                            <input type="radio" name="dealer_id" value="{{ $d->id }}" x-model="dealer" required class="mt-1 text-blue-600">
                            <div class="min-w-0">
                                <p class="font-semibold text-slate-900 truncate">{{ $d->full_name }}</p>
                                <p class="text-xs text-slate-500">{{ ucfirst(str_replace('_', ' ', (string) $d->region)) }}</p>
                                <p class="mt-1 text-xs"><span class="rounded-full px-2 py-0.5 font-bold {{ $n === 0 ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-700' }}">{{ $n }} in stock</span></p>
                            </div>
                        </label>
                    @endforeach
                </div>
            </section>

            {{-- STEP 2 --}}
            <section class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6 space-y-4">
                <div>
                    <h2 class="font-bold text-slate-900"><span class="inline-flex w-6 h-6 items-center justify-center rounded-full bg-[#0B1B3F] text-white text-xs mr-2">2</span>Pick the devices <span class="text-slate-400 font-normal text-sm">({{ number_format($stockTotal) }} available)</span></h2>
                    <p class="text-xs text-slate-500 ml-8">Search by IMEI or SIM inside a device type, then tick the devices or use "Select shown".</p>
                </div>

                @foreach ($stock as $category => $devices)
                    <fieldset class="border border-slate-200 rounded-2xl" x-data="{ s: '' }">
                        <legend class="px-2 ml-4 text-sm font-bold text-slate-700">
                            {{ $category }}
                            <span class="font-normal text-slate-400">&middot; {{ $devices->count() }} available</span>
                            <span x-show="catSelected(@js($category)) > 0" x-cloak class="ml-1 rounded-full bg-blue-600 px-2 py-0.5 text-[10px] font-bold text-white" x-text="catSelected(@js($category)) + ' selected'"></span>
                        </legend>
                        <div class="px-4 pt-1 pb-3 flex flex-col sm:flex-row gap-2 sm:items-center">
                            <input type="search" x-model="s" placeholder="Search IMEI or SIM in {{ $category }}…" class="w-full sm:max-w-xs rounded-xl border-slate-300 text-xs focus:border-blue-500 focus:ring focus:ring-blue-200">
                            <div class="flex gap-2 text-xs font-semibold">
                                <button type="button" @click="toggleCat($el.closest('fieldset'), true)" class="rounded-lg bg-slate-100 px-3 py-1.5 hover:bg-slate-200">Select shown</button>
                                <button type="button" @click="toggleCat($el.closest('fieldset'), false)" class="rounded-lg bg-slate-100 px-3 py-1.5 hover:bg-slate-200">Clear shown</button>
                            </div>
                        </div>
                        <div class="px-4 pb-4 grid sm:grid-cols-2 lg:grid-cols-3 gap-2 max-h-72 overflow-y-auto">
                            @foreach ($devices as $dev)
                                <label data-row data-id="{{ $dev->shdevice_id }}" data-t="{{ strtolower($dev->imei_number . ' ' . $dev->sim_number) }}"
                                       x-show="$el.dataset.t.includes(s.toLowerCase())"
                                       class="flex items-center gap-2 text-xs font-mono rounded-lg px-2.5 py-2 cursor-pointer border"
                                       :class="selected.includes('{{ $dev->shdevice_id }}') ? 'bg-blue-50 border-blue-300' : 'bg-slate-50 border-transparent hover:border-slate-200'">
                                    <input type="checkbox" name="device_ids[]" value="{{ $dev->shdevice_id }}" x-model="selected" class="rounded border-slate-300 text-blue-600">
                                    <span>{{ $dev->imei_number }}</span>
                                    <span class="text-slate-400">{{ $dev->sim_number }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach
            </section>

            {{-- STICKY SUMMARY BAR --}}
            <div class="fixed bottom-0 left-0 right-0 lg:left-72 z-30 border-t border-slate-200 bg-white/95 backdrop-blur px-4 md:px-6 py-3 flex flex-col sm:flex-row items-center justify-between gap-3 shadow-[0_-4px_12px_rgba(0,0,0,0.06)]">
                <p class="text-sm text-slate-700">
                    <strong x-text="selected.length"></strong> device(s) selected
                    <span x-show="dealerName" x-cloak> for <strong x-text="dealerName"></strong></span>
                    <span x-show="!dealerName" class="text-slate-400">&middot; choose a dealer in step 1</span>
                </p>
                <button type="button" @click="confirming = true" :disabled="!dealerName || selected.length === 0"
                        class="rounded-2xl bg-blue-600 px-6 py-2.5 text-sm font-bold text-white shadow hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed">
                    Review &amp; transfer
                </button>
            </div>

            {{-- CONFIRMATION --}}
            <div x-show="confirming" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 backdrop-blur-md p-4" @keydown.escape.window="confirming = false">
                <div @click.away="confirming = false" class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-hidden">
                    <div class="bg-[#0B1B3F] px-6 py-4 text-white">
                        <h3 class="font-bold">Confirm transfer</h3>
                        <p class="text-[11px] text-blue-200">Please check before sending. This cannot be edited afterwards.</p>
                    </div>
                    <div class="p-6 space-y-4 text-sm">
                        <p>You are sending <strong x-text="selected.length"></strong> device(s) to <strong x-text="dealerName"></strong>:</p>
                        <ul class="divide-y divide-slate-100 rounded-2xl border border-slate-200">
                            <template x-for="[cat, n] in summary" :key="cat">
                                <li class="flex justify-between px-4 py-2"><span x-text="cat"></span><strong x-text="n"></strong></li>
                            </template>
                        </ul>
                        <div class="flex justify-end gap-2 pt-2">
                            <button type="button" @click="confirming = false" class="rounded-xl bg-slate-100 px-4 py-2 font-bold text-slate-600 hover:bg-slate-200">Go back</button>
                            <button type="submit" :disabled="submitting" class="rounded-xl bg-blue-600 px-5 py-2 font-bold text-white hover:bg-blue-700 disabled:opacity-50">
                                <span x-show="!submitting">Confirm &amp; send</span><span x-show="submitting" x-cloak>Sending…</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    @endif
</div>
@endsection
