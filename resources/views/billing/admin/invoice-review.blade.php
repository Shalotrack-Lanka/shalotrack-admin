@extends('layouts.admin')
@section('title', 'Review invoice')
@section('content')
@php $m = fn ($c) => \App\Support\Money::format((int) $c); @endphp
<div class="space-y-5 max-w-4xl">
    @include('billing.admin._nav', ['heading' => 'Review invoice', 'sub' => 'Check everything. Once issued an invoice can never be edited, only credited.'])

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-4">
        <div class="grid md:grid-cols-2 gap-4 text-sm">
            <div><p class="text-[10px] font-black uppercase text-slate-500">Bill to</p><p class="font-black text-blue-950">{{ $party->legal_name }}</p>
                <p class="text-slate-600 whitespace-pre-line">{{ $party->address ?: 'No address on file' }}</p>
                <p class="text-slate-500 text-xs">Tax no.: {{ $party->tax_number ?: '—' }}</p>
                @if(! $party->address)<p class="text-amber-700 text-xs mt-1">No address yet. You can add it on the buyer's statement page; it is frozen onto the invoice when issued.</p>@endif</div>
            <div class="md:text-right"><p class="text-[10px] font-black uppercase text-slate-500">Terms</p><p>Due {{ $terms }} days after the issue date</p></div>
        </div>
        <table class="w-full text-sm">
            <thead class="text-[11px] uppercase text-slate-500 border-b"><tr><th class="py-2 text-left">Transfer</th><th class="text-left">Device type</th><th class="text-right">Qty</th><th class="text-right">Price</th><th class="text-right">Total</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @foreach($quote['lines'] as $l)
                <tr><td class="py-2 font-bold">{{ $l['reference'] }} <span class="font-normal text-slate-400">{{ $l['transferred'] }}</span></td><td>{{ $l['category'] }}</td><td class="text-right">{{ $l['quantity'] }}</td><td class="text-right">{{ $m($l['unit_price']) }}</td><td class="text-right font-bold">{{ $m($l['line_total']) }}</td></tr>
            @endforeach
            </tbody>
            <tfoot class="text-sm">
                <tr><td colspan="4" class="pt-3 text-right text-slate-500">Subtotal</td><td class="pt-3 text-right">{{ $m($quote['subtotal']) }}</td></tr>
                @if($quote['tax_rate'] !== null)<tr><td colspan="4" class="text-right text-slate-500">{{ $quote['tax_label'] }} {{ $quote['tax_rate'] }}%</td><td class="text-right">{{ $m($quote['tax']) }}</td></tr>@endif
                <tr><td colspan="4" class="text-right font-black">Total (LKR)</td><td class="text-right font-black text-lg text-blue-950">{{ $m($quote['total']) }}</td></tr>
            </tfoot>
        </table>
        @if($quote['total'] === 0)<div class="bg-amber-50 border border-amber-200 text-amber-900 text-sm rounded-xl p-3">Zero-value invoice. Reason: <b>{{ $zero_reason ?: 'MISSING — go back and enter a reason' }}</b></div>@endif
        @if($notes)<p class="text-sm text-slate-600"><b>Note:</b> {{ $notes }}</p>@endif
    </div>

    <form method="POST" action="{{ route('admin.billing.invoices.store') }}" x-data="{ busy: false }" @submit="if (busy) { $event.preventDefault(); } busy = true" class="flex gap-3">
        @csrf
        <input type="hidden" name="dealer_id" value="{{ $buyer->id }}">
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="notes" value="{{ $notes }}">
        <input type="hidden" name="zero_reason" value="{{ $zero_reason }}">
        @foreach($prices as $id => $p)<input type="hidden" name="prices[{{ $id }}]" value="{{ $p }}">@endforeach
        <button class="px-5 py-2.5 rounded-xl bg-blue-600 text-white font-black text-sm hover:bg-blue-700 disabled:opacity-50" :disabled="busy">Issue invoice</button>
        <a href="{{ route('admin.billing.invoices.new', ['dealer' => $buyer->id]) }}" class="px-5 py-2.5 rounded-xl bg-slate-100 text-slate-700 font-bold text-sm">Go back</a>
    </form>
</div>
@endsection
