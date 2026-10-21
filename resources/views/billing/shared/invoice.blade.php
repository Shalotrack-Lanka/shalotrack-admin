@extends($layout)
@section('title', $invoice->number)
@section('content')
@php
    $m = fn ($c) => \App\Support\Money::format((int) $c);
    $isAdmin = $routePrefix === 'admin.billing';
    $late = $outstanding > 0 && $invoice->due_date->toDateString() < $today;
@endphp
<div class="space-y-5 max-w-5xl {{ $isAdmin ? '' : 'mx-auto' }}">
    @include('distributor.partials.alerts')
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div>
            <a href="{{ $isAdmin ? route('admin.billing.invoices.index') : route($routePrefix . '.index') }}" class="text-xs font-bold text-blue-600">&larr; Back</a>
            <h1 class="text-2xl font-black text-blue-950 mt-1">Invoice {{ $invoice->number }}</h1>
            <p class="text-xs text-slate-500">Issued {{ $invoice->issue_date->toDateString() }} &middot; due {{ $invoice->due_date->toDateString() }}
                &middot; by {{ $invoice->issued_by_name ?? '—' }}</p>
        </div>
        <div class="flex flex-wrap gap-2 items-center">
            <span class="px-3 py-1.5 rounded-xl text-xs font-black {{ $outstanding <= 0 ? 'bg-emerald-100 text-emerald-700' : ($late ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700') }}">{{ $outstanding <= 0 ? 'Paid in full' : ($late ? 'Overdue' : 'Open') }}</span>
            @can('billing.statement.export')<a href="{{ route($routePrefix . '.invoices.pdf', $invoice) }}" class="px-4 py-2 rounded-xl bg-blue-950 text-white text-xs font-bold">Download PDF</a>@endcan
            @if($isAdmin)
                @can('billing.receipt.record')@if($outstanding > 0)<a href="{{ route('admin.billing.receipts.new', ['dealer' => $invoice->buyer_dealer_id]) }}" class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold">Record payment</a>@endif @endcan
                @can('billing.invoice.credit')@if($creditable > 0)<a href="{{ route('admin.billing.invoices.credit', $invoice) }}" class="px-4 py-2 rounded-xl bg-amber-100 text-amber-900 text-xs font-bold">Issue credit note</a>@endif @endcan
            @endif
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-5">
        <div class="grid md:grid-cols-2 gap-5 text-sm">
            <div><p class="text-[10px] font-black uppercase text-slate-500">From</p><p class="font-black text-blue-950">{{ $snap['issuer']['legal_name'] ?? '' }}</p>
                <p class="text-slate-600 whitespace-pre-line">{{ $snap['issuer']['address'] ?? '' }}</p><p class="text-xs text-slate-500">Tax no.: {{ $snap['issuer']['tax_number'] ?? '—' }}</p></div>
            <div><p class="text-[10px] font-black uppercase text-slate-500">Bill to</p><p class="font-black text-blue-950">{{ $snap['buyer']['legal_name'] ?? '' }}</p>
                <p class="text-slate-600 whitespace-pre-line">{{ $snap['buyer']['address'] ?? '' }}</p><p class="text-xs text-slate-500">Tax no.: {{ $snap['buyer']['tax_number'] ?? '—' }}</p></div>
        </div>
        <table class="w-full text-sm">
            <thead class="text-[11px] uppercase text-slate-500 border-b"><tr><th class="py-2 text-left">Transfer</th><th class="text-left">Device type</th><th class="text-right">Qty</th><th class="text-right">Price</th><th class="text-right">Total</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @foreach($snap['lines'] as $l)
                <tr><td class="py-2 align-top"><b>{{ $l['reference'] }}</b><div class="text-[11px] text-slate-400">{{ $l['transferred'] }}</div>
                    <details class="text-[11px] text-slate-500 mt-1"><summary class="cursor-pointer text-blue-600 font-bold">{{ count($l['devices']) }} devices</summary>
                        <div class="mt-1 max-h-40 overflow-auto font-mono">@foreach($l['devices'] as $d){{ $d['imei'] }}@if(! empty($d['sim'])) &middot; {{ $d['sim'] }}@endif<br>@endforeach</div></details></td>
                    <td class="align-top">{{ $l['category'] }}</td><td class="text-right align-top">{{ $l['quantity'] }}</td><td class="text-right align-top">{{ $m($l['unit_price']) }}</td><td class="text-right align-top font-bold">{{ $m($l['line_total']) }}</td></tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr><td colspan="4" class="pt-3 text-right text-slate-500">Subtotal</td><td class="pt-3 text-right">{{ $m($snap['subtotal']) }}</td></tr>
                @if(($snap['tax_rate'] ?? null) !== null)<tr><td colspan="4" class="text-right text-slate-500">{{ $snap['tax_label'] }} {{ $snap['tax_rate'] }}%</td><td class="text-right">{{ $m($snap['tax']) }}</td></tr>@endif
                <tr><td colspan="4" class="text-right font-black">Total (LKR)</td><td class="text-right font-black text-lg text-blue-950">{{ $m($snap['total']) }}</td></tr>
            </tfoot>
        </table>
        @if(! empty($snap['notes']))<p class="text-sm text-slate-600"><b>Note:</b> {{ $snap['notes'] }}</p>@endif
        @if(! empty($snap['zero_reason']))<p class="text-sm text-amber-800"><b>Zero-value reason:</b> {{ $snap['zero_reason'] }}</p>@endif
        @if(! empty($snap['issuer']['bank_details']))<div class="text-xs text-slate-600 bg-slate-50 rounded-xl p-3"><p class="font-black uppercase text-[10px] text-slate-500">Pay to</p><p class="whitespace-pre-line">{{ $snap['issuer']['bank_details'] }}</p></div>@endif
        @if(! empty($snap['footer']))<p class="text-[11px] text-slate-400">{{ $snap['footer'] }}</p>@endif
    </div>

    <div class="grid md:grid-cols-2 gap-5">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
            <h2 class="font-black text-blue-950 text-sm mb-2">Payments applied</h2>
            @forelse($payments as $p)
                <div class="flex justify-between text-sm py-1"><span>{{ $p->number }} <span class="text-slate-400 text-xs">{{ \Illuminate\Support\Carbon::parse($p->received_date)->toDateString() }} &middot; {{ \App\Models\PaymentReceipt::METHODS[$p->method] ?? $p->method }}</span></span><b>{{ $m(\App\Support\Money::dbToCents($p->amount)) }}</b></div>
            @empty <p class="text-sm text-slate-400">No payments yet.</p> @endforelse
            @if($invoice->creditNotes->isNotEmpty())
                <h2 class="font-black text-blue-950 text-sm mt-4 mb-2">Credit notes</h2>
                @foreach($invoice->creditNotes as $c)
                    <div class="flex justify-between text-sm py-1"><span>@if($isAdmin)<a class="text-blue-700 font-bold hover:underline" href="{{ route('admin.billing.credit-notes.show', $c) }}">{{ $c->number }}</a>@else{{ $c->number }}@endif <span class="text-slate-400 text-xs">{{ $c->reason }}</span></span><b>-{{ $m(\App\Support\Money::dbToCents($c->total)) }}</b></div>
                @endforeach
            @endif
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 flex flex-col justify-center text-center">
            <p class="text-[11px] font-black uppercase text-slate-500">Still owed on this invoice</p>
            <p class="text-3xl font-black {{ $outstanding > 0 ? 'text-blue-950' : 'text-emerald-600' }}">LKR {{ $m(max(0, $outstanding)) }}</p>
            @if($late)<p class="text-xs text-red-600 font-bold mt-1">Overdue since {{ $invoice->due_date->toDateString() }}</p>@endif
        </div>
    </div>
</div>
@endsection
