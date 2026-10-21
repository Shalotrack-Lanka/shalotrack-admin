@extends($layout)
@section('title', 'Billing')
@section('content')
@php $m = fn ($c) => \App\Support\Money::format((int) $c);
     $tone = ['valid' => 'bg-emerald-100 text-emerald-700', 'reversed' => 'bg-slate-200 text-slate-600']; @endphp
<div class="space-y-5 max-w-5xl mx-auto">
    @include('distributor.partials.alerts')
    <div class="bg-gradient-to-r from-blue-950 to-blue-700 text-white rounded-3xl p-6">
        <p class="text-xs font-bold uppercase tracking-widest text-blue-200">Billing</p>
        <h1 class="text-2xl font-black mt-1">Your account with ShaloTrack</h1>
        <p class="text-sm text-blue-100 mt-1">What the company invoiced you for devices, and the payments it received. This page is read-only.</p>
        <a href="{{ route($routePrefix . '.statement') }}" class="inline-block mt-3 px-4 py-2 rounded-xl bg-white text-blue-950 text-xs font-black">View statement</a>
    </div>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        @foreach([['Invoiced', $account['invoiced'], 'text-blue-950'], ['Paid', $account['paid'], 'text-emerald-700'], ['You owe', max(0, $account['balance']), $account['balance'] > 0 ? 'text-blue-950' : 'text-slate-400'], ['Overdue', $account['overdue'], $account['overdue'] > 0 ? 'text-red-600' : 'text-slate-400']] as [$l, $v, $c])
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4"><p class="text-[10px] font-black uppercase tracking-wide text-slate-500">{{ $l }}</p><p class="text-2xl font-black mt-1 {{ $c }}">{{ $m($v) }}</p></div>
        @endforeach
    </div>
    @if($account['balance'] < 0)<div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded-2xl p-3">You have a credit of {{ $m(-$account['balance']) }} with the company.</div>@endif

    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-slate-100"><h2 class="font-black text-blue-950">Invoices</h2></div>
        <div class="overflow-x-auto"><table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-[11px] text-slate-600 uppercase tracking-wide"><tr><th class="p-3">Invoice</th><th class="p-3">Issued</th><th class="p-3">Due</th><th class="p-3 text-right">Total</th><th class="p-3 text-right">Still owed</th><th class="p-3">Status</th></tr></thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
            @forelse($invoices as $i)
                @php $late = $i->outstanding_cents > 0 && $i->due_date->toDateString() < $today; @endphp
                <tr><td class="p-3"><a class="font-bold text-blue-700 hover:underline" href="{{ route($routePrefix . '.invoices.show', $i) }}">{{ $i->number }}</a></td><td class="p-3 text-slate-500">{{ $i->issue_date->toDateString() }}</td><td class="p-3 {{ $late ? 'text-red-600 font-bold' : 'text-slate-500' }}">{{ $i->due_date->toDateString() }}</td>
                    <td class="p-3 text-right font-bold">{{ $m(\App\Support\Money::dbToCents($i->total)) }}</td><td class="p-3 text-right">{{ $m($i->outstanding_cents) }}</td>
                    <td class="p-3"><span class="px-2.5 py-1 rounded-lg text-[11px] font-bold {{ $i->outstanding_cents <= 0 ? 'bg-emerald-100 text-emerald-700' : ($late ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700') }}">{{ $i->outstanding_cents <= 0 ? 'Paid' : ($late ? 'Overdue' : 'Open') }}</span></td></tr>
            @empty <tr><td colspan="6" class="p-8 text-center text-slate-400">No invoices yet. When the company invoices you for devices they will appear here.</td></tr> @endforelse
            </tbody></table></div>
    </div>

    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-slate-100"><h2 class="font-black text-blue-950">Payments received by the company</h2></div>
        <ul class="divide-y divide-slate-100 text-sm">
            @forelse($receipts as $r)<li class="p-3 flex flex-wrap justify-between gap-2"><span class="font-bold">{{ $r->number }}</span><span class="text-slate-500">{{ $r->received_date->toDateString() }} &middot; {{ \App\Models\PaymentReceipt::METHODS[$r->method] ?? $r->method }}</span><span class="font-bold {{ $r->status === 'reversed' ? 'line-through text-slate-400' : '' }}">{{ $m(\App\Support\Money::dbToCents($r->amount)) }}</span><span class="px-2 py-0.5 rounded-lg text-[11px] font-bold {{ $tone[$r->status] ?? '' }}">{{ ucfirst($r->status) }}</span></li>
            @empty <li class="p-6 text-center text-slate-400">No payments recorded yet.</li> @endforelse
        </ul>
    </div>

    @if($network->isNotEmpty())
    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-slate-100"><h2 class="font-black text-blue-950">My dealers' accounts</h2><p class="text-xs text-slate-500">Read-only. Only the company records invoices and payments.</p></div>
        <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-[11px] text-slate-600 uppercase tracking-wide"><tr><th class="p-3">Dealer</th><th class="p-3 text-right">Invoiced</th><th class="p-3 text-right">Paid</th><th class="p-3 text-right">Owes</th><th class="p-3 text-right">Overdue</th><th class="p-3"></th></tr></thead>
            <tbody class="divide-y divide-slate-100">@foreach($network as $r)<tr><td class="p-3 font-semibold">{{ $r['dealer']->full_name }}</td><td class="p-3 text-right">{{ $m($r['invoiced']) }}</td><td class="p-3 text-right text-emerald-700">{{ $m($r['paid']) }}</td><td class="p-3 text-right font-bold">{{ $m(max(0, $r['balance'])) }}</td><td class="p-3 text-right {{ $r['overdue'] > 0 ? 'text-red-600 font-bold' : 'text-slate-400' }}">{{ $r['overdue'] > 0 ? $m($r['overdue']) : '—' }}</td><td class="p-3 text-right"><a class="text-blue-600 font-bold text-xs hover:underline" href="{{ route('distributor.billing.statement', $r['dealer']) }}">Statement</a></td></tr>@endforeach</tbody></table></div>
    </div>
    @endif
</div>
@endsection
