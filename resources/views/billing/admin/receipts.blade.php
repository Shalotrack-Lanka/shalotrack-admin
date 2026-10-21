@extends('layouts.admin')
@section('title', 'Payments')
@section('content')
@php $m = fn ($c) => \App\Support\Money::format((int) $c);
     $tone = ['valid' => 'bg-emerald-100 text-emerald-700', 'pending' => 'bg-amber-100 text-amber-800', 'reversed' => 'bg-slate-200 text-slate-600', 'rejected' => 'bg-red-100 text-red-700']; @endphp
<div class="space-y-5">
    @include('billing.admin._nav', ['heading' => 'Payments received'])
    @if($approval && $pending > 0)<div class="bg-amber-50 border border-amber-200 text-amber-900 text-sm rounded-2xl p-3">{{ $pending }} {{ \Illuminate\Support\Str::plural('payment', $pending) }} waiting for approval. <a class="underline font-bold" href="{{ route('admin.billing.receipts.index', ['status' => 'pending']) }}">Show them</a></div>@endif
    <form method="GET" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 flex flex-wrap items-end gap-3 text-xs">
        <div><label class="font-black uppercase text-slate-500">Buyer</label><select name="dealer" class="mt-1 block border border-slate-300 rounded-xl px-3 py-2 bg-slate-50"><option value="">Everyone</option>@foreach($buyers as $b)<option value="{{ $b->id }}" @selected(($filters['dealer'] ?? null) == $b->id)>{{ $b->full_name }}</option>@endforeach</select></div>
        <div><label class="font-black uppercase text-slate-500">Status</label><select name="status" class="mt-1 block border border-slate-300 rounded-xl px-3 py-2 bg-slate-50">@foreach(['all' => 'All', 'pending' => 'Waiting approval', 'valid' => 'Valid', 'reversed' => 'Reversed', 'rejected' => 'Rejected'] as $k => $v)<option value="{{ $k }}" @selected($filters['status'] === $k)>{{ $v }}</option>@endforeach</select></div>
        <button class="px-4 py-2 rounded-xl bg-blue-950 text-white font-bold">Filter</button><a href="{{ route('admin.billing.receipts.index') }}" class="px-4 py-2 rounded-xl bg-slate-100 text-slate-700 font-bold">Reset</a>
    </form>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden"><div class="overflow-x-auto">
        <table class="w-full text-left text-sm"><thead class="bg-slate-50 text-[11px] text-slate-600 uppercase tracking-wide"><tr><th class="p-3">Receipt</th><th class="p-3">Buyer</th><th class="p-3">Received</th><th class="p-3">Method</th><th class="p-3">Reference</th><th class="p-3 text-right">Amount</th><th class="p-3">Status</th><th class="p-3">Recorded by</th></tr></thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
            @forelse($receipts as $r)
                <tr><td class="p-3"><a class="font-bold text-blue-700 hover:underline" href="{{ route('admin.billing.receipts.show', $r) }}">{{ $r->number }}</a></td><td class="p-3">{{ $r->buyer->full_name ?? '—' }}</td>
                    <td class="p-3 text-slate-500">{{ $r->received_date->toDateString() }}</td><td class="p-3">{{ \App\Models\PaymentReceipt::METHODS[$r->method] ?? $r->method }}</td><td class="p-3 text-slate-500">{{ $r->reference ?: '—' }}</td>
                    <td class="p-3 text-right font-bold">{{ $m(\App\Support\Money::dbToCents($r->amount)) }}</td>
                    <td class="p-3"><span class="px-2.5 py-1 rounded-lg text-[11px] font-bold {{ $tone[$r->status] ?? '' }}">{{ ucfirst($r->status) }}</span></td><td class="p-3 text-slate-500">{{ $r->recorded_by_name }}</td></tr>
            @empty <tr><td colspan="8" class="p-8 text-center text-slate-400">No payments match.</td></tr> @endforelse
            </tbody></table></div><div class="p-3">{{ $receipts->links() }}</div></div>
</div>
@endsection
