@extends('layouts.admin')
@section('title', 'Credit note')
@section('content')
@php $m = fn ($c) => \App\Support\Money::format((int) $c); @endphp
<div class="space-y-5 max-w-4xl">
    @include('billing.admin._nav', ['heading' => 'Credit note for ' . $invoice->number, 'sub' => 'Use this when an invoice was wrong or devices were taken back. The invoice itself never changes.'])
    <form method="POST" action="{{ route('admin.billing.invoices.credit.store', $invoice) }}" x-data="{ busy: false }" @submit="if (busy) { $event.preventDefault(); } busy = true" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <table class="w-full text-sm"><thead class="text-[11px] uppercase text-slate-500 border-b"><tr><th class="py-2 text-left">Item</th><th class="text-right">Price</th><th class="text-right">Can still credit</th><th class="text-right w-32">Credit qty</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @foreach($invoice->lines as $l)
                <tr><td class="py-2">{{ $l->description }} <span class="text-slate-400">x {{ $l->quantity }}</span></td><td class="text-right">{{ $m(\App\Support\Money::dbToCents($l->unit_price)) }}</td><td class="text-right">{{ $creditable[$l->id] }}</td>
                    <td class="text-right"><input type="number" min="0" max="{{ $creditable[$l->id] }}" name="qty[{{ $l->id }}]" value="{{ old('qty.' . $l->id, 0) }}" @disabled($creditable[$l->id] <= 0) class="w-24 text-right border border-slate-300 rounded-lg px-2 py-1"></td></tr>
            @endforeach
            </tbody></table>
        <div><label class="text-[11px] font-black uppercase tracking-wide text-slate-500">Reason (required)</label>
            <input name="reason" required maxlength="255" value="{{ old('reason') }}" class="mt-1 w-full text-sm border border-slate-300 rounded-xl px-3 py-2"></div>
        <div class="flex gap-3"><button class="px-5 py-2.5 rounded-xl bg-amber-500 text-white font-black text-sm disabled:opacity-50" :disabled="busy">Issue credit note</button>
            <a href="{{ route('admin.billing.invoices.show', $invoice) }}" class="px-5 py-2.5 rounded-xl bg-slate-100 text-slate-700 font-bold text-sm">Cancel</a></div>
    </form>
</div>
@endsection
