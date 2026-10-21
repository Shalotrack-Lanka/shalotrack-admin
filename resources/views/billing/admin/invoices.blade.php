@extends('layouts.admin')
@section('title', 'Invoices')
@section('content')
@php $m = fn ($c) => \App\Support\Money::format((int) $c); @endphp
<div class="space-y-5">
    @include('billing.admin._nav', ['heading' => 'Invoices'])
    <form method="GET" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 flex flex-wrap items-end gap-3 text-xs">
        <div><label class="font-black uppercase text-slate-500">Number</label><input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="SL-INV-…" class="mt-1 block border border-slate-300 rounded-xl px-3 py-2 bg-slate-50"></div>
        <div><label class="font-black uppercase text-slate-500">Buyer</label><select name="dealer" class="mt-1 block border border-slate-300 rounded-xl px-3 py-2 bg-slate-50"><option value="">Everyone</option>@foreach($buyers as $b)<option value="{{ $b->id }}" @selected(($filters['dealer'] ?? null) == $b->id)>{{ $b->full_name }}</option>@endforeach</select></div>
        <div><label class="font-black uppercase text-slate-500">Status</label><select name="status" class="mt-1 block border border-slate-300 rounded-xl px-3 py-2 bg-slate-50">@foreach(['all' => 'All', 'open' => 'Unpaid', 'overdue' => 'Overdue', 'paid' => 'Paid'] as $k => $v)<option value="{{ $k }}" @selected($filters['status'] === $k)>{{ $v }}</option>@endforeach</select></div>
        <div><label class="font-black uppercase text-slate-500">From</label><input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="mt-1 block border border-slate-300 rounded-xl px-3 py-2 bg-slate-50"></div>
        <div><label class="font-black uppercase text-slate-500">To</label><input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="mt-1 block border border-slate-300 rounded-xl px-3 py-2 bg-slate-50"></div>
        <button class="px-4 py-2 rounded-xl bg-blue-950 text-white font-bold">Filter</button>
        <a href="{{ route('admin.billing.invoices.index') }}" class="px-4 py-2 rounded-xl bg-slate-100 text-slate-700 font-bold">Reset</a>
    </form>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden"><div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-[11px] text-slate-600 uppercase tracking-wide"><tr><th class="p-3">Invoice</th><th class="p-3">Buyer</th><th class="p-3">Issued</th><th class="p-3">Due</th><th class="p-3 text-right">Total</th><th class="p-3 text-right">Still owed</th><th class="p-3">Status</th></tr></thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
            @forelse($invoices as $i)
                @php $late = $i->outstanding_cents > 0 && $i->due_date->toDateString() < $today; @endphp
                <tr>
                    <td class="p-3"><a class="font-bold text-blue-700 hover:underline" href="{{ route('admin.billing.invoices.show', $i) }}">{{ $i->number }}</a></td>
                    <td class="p-3">{{ $i->buyer->full_name ?? '—' }}</td>
                    <td class="p-3 text-slate-500">{{ $i->issue_date->toDateString() }}</td>
                    <td class="p-3 {{ $late ? 'text-red-600 font-bold' : 'text-slate-500' }}">{{ $i->due_date->toDateString() }}</td>
                    <td class="p-3 text-right font-bold">{{ $m(\App\Support\Money::dbToCents($i->total)) }}</td>
                    <td class="p-3 text-right">{{ $m($i->outstanding_cents) }}</td>
                    <td class="p-3"><span class="px-2.5 py-1 rounded-lg text-[11px] font-bold {{ $i->outstanding_cents <= 0 ? 'bg-emerald-100 text-emerald-700' : ($late ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700') }}">{{ $i->outstanding_cents <= 0 ? 'Paid' : ($late ? 'Overdue' : 'Open') }}</span></td>
                </tr>
            @empty
                <tr><td colspan="7" class="p-8 text-center text-slate-400">No invoices match.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        <div class="p-3">{{ $invoices->links() }}</div>
    </div>
</div>
@endsection
