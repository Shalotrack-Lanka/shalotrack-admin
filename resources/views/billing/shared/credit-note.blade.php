@extends($layout)
@section('title', $note->number)
@section('content')
@php $m = fn ($c) => \App\Support\Money::format((int) $c); @endphp
<div class="space-y-5 max-w-4xl">
    @include('distributor.partials.alerts')
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm flex flex-wrap items-center justify-between gap-3">
        <div><a href="{{ $invoiceRoute }}" class="text-xs font-bold text-blue-600">&larr; Invoice {{ $note->invoice->number }}</a>
            <h1 class="text-2xl font-black text-blue-950 mt-1">Credit note {{ $note->number }}</h1>
            <p class="text-xs text-slate-500">Issued {{ $note->issue_date->toDateString() }} &middot; by {{ $note->issued_by_name ?? '—' }}</p></div>
        <a href="{{ $pdfRoute }}" class="px-4 py-2 rounded-xl bg-blue-950 text-white text-xs font-bold">Download PDF</a>
    </div>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-3">
        <p class="text-sm"><b>Reason:</b> {{ $snap['reason'] }}</p>
        <table class="w-full text-sm"><thead class="text-[11px] uppercase text-slate-500 border-b"><tr><th class="py-2 text-left">Item</th><th class="text-right">Qty</th><th class="text-right">Price</th><th class="text-right">Total</th></tr></thead>
            <tbody class="divide-y divide-slate-100">@foreach($snap['lines'] as $l)<tr><td class="py-2">{{ $l['description'] }}</td><td class="text-right">{{ $l['quantity'] }}</td><td class="text-right">{{ $m($l['unit_price']) }}</td><td class="text-right font-bold">{{ $m($l['line_total']) }}</td></tr>@endforeach</tbody>
            <tfoot><tr><td colspan="3" class="pt-3 text-right text-slate-500">Subtotal</td><td class="pt-3 text-right">{{ $m($snap['subtotal']) }}</td></tr>
                @if($snap['tax'] > 0)<tr><td colspan="3" class="text-right text-slate-500">Tax</td><td class="text-right">{{ $m($snap['tax']) }}</td></tr>@endif
                <tr><td colspan="3" class="text-right font-black">Credited (LKR)</td><td class="text-right font-black text-lg text-blue-950">{{ $m($snap['total']) }}</td></tr></tfoot></table>
        <p class="text-xs text-slate-400">This credit reduces what the buyer owes. It does not move any devices; stock corrections are handled separately.</p>
    </div>
</div>
@endsection
