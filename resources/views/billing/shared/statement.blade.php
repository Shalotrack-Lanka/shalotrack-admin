@extends($layout)
@section('title', 'Statement')
@section('content')
@php
    $m = fn ($c) => \App\Support\Money::format((int) $c);
    $isAdmin = $routePrefix === 'admin.billing';
    $self = $isAdmin ? route('admin.billing.statement', $dealer) : (($routePrefix === 'distributor.billing' && ! empty($scope) && $scope->ownId !== $dealer->id) ? route('distributor.billing.statement', $dealer) : route($routePrefix . '.statement'));
    $tone = ['Invoice' => 'text-blue-700', 'Credit note' => 'text-amber-700', 'Payment' => 'text-emerald-700', 'Payment reversed' => 'text-red-600'];
@endphp
<div class="space-y-5 {{ $isAdmin ? '' : 'max-w-5xl mx-auto' }}">
    @include('distributor.partials.alerts')
    @if($errors->has('pdf'))<div class="p-3 bg-red-50 text-red-800 text-sm rounded-xl">{{ $errors->first('pdf') }}</div>@endif
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm flex flex-wrap items-center justify-between gap-3 print:hidden">
        <div>
            <a href="{{ $isAdmin ? route('admin.billing.home') : route($routePrefix . '.index') }}" class="text-xs font-bold text-blue-600">&larr; Back</a>
            <h1 class="text-2xl font-black text-blue-950 mt-1">Statement: {{ $dealer->full_name }}</h1>
            <p class="text-xs text-slate-500">Everything invoiced, credited and paid between the company and this account.</p>
        </div>
        <div class="flex gap-2">
            @if($isAdmin)
                @can('billing.statement.export')
                    <a href="{{ route('admin.billing.statement.csv', ['dealer' => $dealer, 'from' => $from, 'to' => $to]) }}" class="px-4 py-2 rounded-xl bg-slate-100 text-slate-700 text-xs font-bold">CSV</a>
                    <a href="{{ route('admin.billing.statement.pdf', ['dealer' => $dealer, 'from' => $from, 'to' => $to]) }}" class="px-4 py-2 rounded-xl bg-blue-950 text-white text-xs font-bold">PDF</a>
                @endcan
            @else
                <button onclick="window.print()" class="px-4 py-2 rounded-xl bg-blue-950 text-white text-xs font-bold">Print</button>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        @foreach([['Total invoiced', $account['invoiced'], 'text-blue-950'], ['Credit notes', $account['credited'], 'text-amber-700'], ['Paid', $account['paid'], 'text-emerald-700'], ['Balance owed', $account['balance'], $account['balance'] > 0 ? 'text-blue-950' : 'text-emerald-700']] as [$l, $v, $c])
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4"><p class="text-[10px] font-black uppercase tracking-wide text-slate-500">{{ $l }}</p><p class="text-2xl font-black mt-1 {{ $c }}">{{ $m($v) }}</p></div>
        @endforeach
    </div>
    @if($account['overdue'] > 0)<div class="bg-red-50 border border-red-200 text-red-800 text-sm rounded-2xl p-3"><b>{{ $m($account['overdue']) }}</b> is overdue.</div>@endif

    <form method="GET" action="{{ $self }}" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 flex flex-wrap items-end gap-3 text-xs print:hidden">
        <div><label class="font-black uppercase text-slate-500">From</label><input type="date" name="from" value="{{ $from }}" class="mt-1 block border border-slate-300 rounded-xl px-3 py-2 bg-slate-50"></div>
        <div><label class="font-black uppercase text-slate-500">To</label><input type="date" name="to" value="{{ $to }}" class="mt-1 block border border-slate-300 rounded-xl px-3 py-2 bg-slate-50"></div>
        <button class="px-4 py-2 rounded-xl bg-blue-950 text-white font-bold">Show</button>
    </form>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden"><div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-[11px] text-slate-600 uppercase tracking-wide"><tr><th class="p-3">Date</th><th class="p-3">What</th><th class="p-3">Number</th><th class="p-3">Detail</th><th class="p-3 text-right">Amount</th><th class="p-3 text-right">Balance</th></tr></thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
                <tr class="bg-slate-50/60"><td class="p-3" colspan="5"><b>Opening balance</b> on {{ $from }}</td><td class="p-3 text-right font-bold">{{ $m($stmt['opening']) }}</td></tr>
                @forelse($stmt['rows'] as $r)
                    <tr><td class="p-3 text-slate-500">{{ $r['date'] }}</td><td class="p-3 font-bold {{ $tone[$r['type']] ?? '' }}">{{ $r['type'] }}</td><td class="p-3">{{ $r['number'] }}</td><td class="p-3 text-slate-500">{{ $r['note'] }}</td>
                        <td class="p-3 text-right {{ $r['amount'] < 0 ? 'text-emerald-700' : '' }}">{{ $m($r['amount']) }}</td><td class="p-3 text-right font-bold">{{ $m($r['balance']) }}</td></tr>
                @empty <tr><td colspan="6" class="p-6 text-center text-slate-400">No activity in this period.</td></tr> @endforelse
                <tr class="bg-blue-50"><td class="p-3" colspan="5"><b>Closing balance</b> on {{ $to }}</td><td class="p-3 text-right font-black text-blue-950">{{ $m($stmt['closing']) }}</td></tr>
            </tbody>
        </table></div></div>

    <div class="grid lg:grid-cols-2 gap-5 print:hidden">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm"><div class="p-4 border-b border-slate-100 font-black text-blue-950">Invoices</div>
            <ul class="divide-y divide-slate-100 text-sm">@forelse($invoices as $i)<li class="p-3 flex justify-between gap-2"><a class="font-bold text-blue-700 hover:underline" href="{{ route($routePrefix . '.invoices.show', $i) }}">{{ $i->number }}</a><span class="text-slate-500">due {{ $i->due_date->toDateString() }}</span><span class="font-bold {{ $i->outstanding_cents > 0 ? '' : 'text-emerald-600' }}">{{ $i->outstanding_cents > 0 ? 'owes ' . $m($i->outstanding_cents) : 'paid' }}</span></li>@empty<li class="p-5 text-center text-slate-400 text-sm">No invoices.</li>@endforelse</ul></div>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm"><div class="p-4 border-b border-slate-100 font-black text-blue-950">Payments</div>
            <ul class="divide-y divide-slate-100 text-sm">@forelse($receipts as $r)<li class="p-3 flex justify-between gap-2"><span class="font-bold">@if($isAdmin)<a class="text-blue-700 hover:underline" href="{{ route('admin.billing.receipts.show', $r) }}">{{ $r->number }}</a>@else{{ $r->number }}@endif</span><span class="text-slate-500">{{ $r->received_date->toDateString() }}</span><span class="font-bold {{ $r->status === 'reversed' ? 'line-through text-slate-400' : '' }}">{{ $m(\App\Support\Money::dbToCents($r->amount)) }}</span></li>@empty<li class="p-5 text-center text-slate-400 text-sm">No payments.</li>@endforelse</ul></div>
    </div>

    @if(! empty($canManage))
    <form method="POST" action="{{ route('admin.billing.party.update', $dealer) }}" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 grid md:grid-cols-2 gap-3 print:hidden">
        @csrf @method('PUT')
        <div class="md:col-span-2"><h2 class="font-black text-blue-950">Billing details printed on this buyer's invoices</h2><p class="text-xs text-slate-500">Changing these never alters invoices already issued.</p></div>
        <div><label class="text-[11px] font-black uppercase text-slate-500">Legal name</label><input name="legal_name" required maxlength="200" value="{{ old('legal_name', $party->legal_name) }}" class="mt-1 w-full text-sm border border-slate-300 rounded-xl px-3 py-2"></div>
        <div><label class="text-[11px] font-black uppercase text-slate-500">Tax / business registration no.</label><input name="tax_number" maxlength="60" value="{{ old('tax_number', $party->tax_number) }}" class="mt-1 w-full text-sm border border-slate-300 rounded-xl px-3 py-2"></div>
        <div class="md:col-span-2"><label class="text-[11px] font-black uppercase text-slate-500">Address</label><textarea name="address" rows="2" maxlength="500" class="mt-1 w-full text-sm border border-slate-300 rounded-xl px-3 py-2">{{ old('address', $party->address) }}</textarea></div>
        <div><label class="text-[11px] font-black uppercase text-slate-500">Phone</label><input name="phone" maxlength="40" value="{{ old('phone', $party->phone) }}" class="mt-1 w-full text-sm border border-slate-300 rounded-xl px-3 py-2"></div>
        <div><label class="text-[11px] font-black uppercase text-slate-500">Email</label><input name="email" type="email" maxlength="150" value="{{ old('email', $party->email) }}" class="mt-1 w-full text-sm border border-slate-300 rounded-xl px-3 py-2"></div>
        <div class="md:col-span-2"><button class="px-5 py-2.5 rounded-xl bg-blue-950 text-white font-black text-sm">Save billing details</button></div>
    </form>
    @endif
</div>
@endsection
