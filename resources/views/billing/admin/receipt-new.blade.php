@extends('layouts.admin')
@section('title', 'Record payment')
@section('content')
@php $m = fn ($c) => \App\Support\Money::format((int) $c); @endphp
<div class="space-y-5 max-w-4xl">
    @include('billing.admin._nav', ['heading' => 'Record a payment', 'sub' => 'Record money you have actually received. It cannot be edited afterwards, only reversed with a reason.'])
    <form method="GET" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
        <label class="text-[11px] font-black uppercase tracking-wide text-slate-500">Who paid?</label>
        <select name="dealer" onchange="this.form.submit()" class="mt-1 w-full max-w-md text-sm border border-slate-300 rounded-xl px-3 py-2 bg-slate-50"><option value="">Choose…</option>
            @foreach($buyers as $b)<option value="{{ $b->id }}" @selected($buyer && $buyer->id === $b->id)>{{ $b->full_name }}</option>@endforeach</select>
    </form>
    @if($buyer)
    <div class="grid grid-cols-3 gap-3 text-center">
        <div class="bg-white rounded-2xl border border-slate-200 p-3"><p class="text-[10px] font-black uppercase text-slate-500">Owes now</p><p class="text-xl font-black text-blue-950">{{ $m(max(0, $account['balance'])) }}</p></div>
        <div class="bg-white rounded-2xl border border-slate-200 p-3"><p class="text-[10px] font-black uppercase text-slate-500">Overdue</p><p class="text-xl font-black {{ $account['overdue'] > 0 ? 'text-red-600' : 'text-slate-400' }}">{{ $m($account['overdue']) }}</p></div>
        <div class="bg-white rounded-2xl border border-slate-200 p-3"><p class="text-[10px] font-black uppercase text-slate-500">Awaiting approval</p><p class="text-xl font-black text-amber-600">{{ $m($account['pending']) }}</p></div>
    </div>
    @if($account['balance'] <= 0)
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded-2xl p-4">{{ $buyer->full_name }} owes nothing, so there is nothing to record a payment against.</div>
    @else
    <form method="POST" action="{{ route('admin.billing.receipts.store') }}" x-data="{ busy: false }" @submit="if (busy) { $event.preventDefault(); } busy = true" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 grid md:grid-cols-2 gap-4">
        @csrf
        <input type="hidden" name="dealer_id" value="{{ $buyer->id }}"><input type="hidden" name="token" value="{{ $token }}">
        <div><label class="text-[11px] font-black uppercase text-slate-500">Amount received (LKR)</label><input name="amount" required inputmode="decimal" value="{{ old('amount') }}" placeholder="0.00" class="mt-1 w-full text-sm border border-slate-300 rounded-xl px-3 py-2"></div>
        <div><label class="text-[11px] font-black uppercase text-slate-500">Date received</label><input type="date" name="received_date" required max="{{ $today }}" value="{{ old('received_date', $today) }}" class="mt-1 w-full text-sm border border-slate-300 rounded-xl px-3 py-2"></div>
        <div><label class="text-[11px] font-black uppercase text-slate-500">Method</label><select name="method" class="mt-1 w-full text-sm border border-slate-300 rounded-xl px-3 py-2">@foreach(\App\Models\PaymentReceipt::METHODS as $k => $v)<option value="{{ $k }}" @selected(old('method', 'bank_transfer') === $k)>{{ $v }}</option>@endforeach</select></div>
        <div><label class="text-[11px] font-black uppercase text-slate-500">Bank / cheque / transaction reference</label><input name="reference" maxlength="120" value="{{ old('reference') }}" class="mt-1 w-full text-sm border border-slate-300 rounded-xl px-3 py-2"><p class="text-[11px] text-slate-400 mt-1">Required unless it is cash.</p></div>
        <div class="md:col-span-2"><label class="text-[11px] font-black uppercase text-slate-500">Apply to</label>
            <select name="invoice_id" class="mt-1 w-full text-sm border border-slate-300 rounded-xl px-3 py-2"><option value="">Oldest unpaid invoices first (recommended)</option>
                @foreach($open as $i)<option value="{{ $i->id }}" @selected(old('invoice_id') == $i->id)>{{ $i->number }} · owes {{ $m($i->outstanding_cents) }} · due {{ $i->due_date->toDateString() }}</option>@endforeach</select></div>
        <div class="md:col-span-2"><label class="text-[11px] font-black uppercase text-slate-500">Note (optional)</label><input name="notes" maxlength="500" value="{{ old('notes') }}" class="mt-1 w-full text-sm border border-slate-300 rounded-xl px-3 py-2"></div>
        @if($approval)<p class="md:col-span-2 text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-xl p-3">Approval is on: this payment will count only after another administrator approves it.</p>@endif
        <div class="md:col-span-2 flex gap-3"><button class="px-5 py-2.5 rounded-xl bg-emerald-600 text-white font-black text-sm disabled:opacity-50" :disabled="busy">Record payment</button><a href="{{ route('admin.billing.receipts.index') }}" class="px-5 py-2.5 rounded-xl bg-slate-100 text-slate-700 font-bold text-sm">Cancel</a></div>
    </form>
    @endif
    @endif
</div>
@endsection
