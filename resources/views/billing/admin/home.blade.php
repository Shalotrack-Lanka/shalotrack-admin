@extends('layouts.admin')
@section('title', 'Billing')
@section('content')
@php $m = fn ($c) => \App\Support\Money::format((int) $c); @endphp
<div class="space-y-5">
    @include('billing.admin._nav')

    @unless($ready)
        <div class="bg-amber-50 border border-amber-200 text-amber-900 text-sm rounded-2xl p-4">
            <b>Set up the company details first.</b> Invoices need the company's legal name and address.
            <a href="{{ route('admin.billing.settings') }}" class="underline font-bold">Open settings</a>
        </div>
    @endunless

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        @foreach([
            ['Total owed to the company', $m($totals['balance']), 'text-blue-950', 'Invoiced minus credit notes minus payments'],
            ['Overdue', $m($totals['overdue']), $totals['overdue'] > 0 ? 'text-red-600' : 'text-emerald-600', 'Past the due date and still unpaid'],
            ['Waiting for approval', $m($totals['pending']), $totals['pending'] > 0 ? 'text-amber-600' : 'text-slate-400', $approval ? 'Payments another administrator must approve' : 'Approval is switched off'],
            ['Transfers not invoiced yet', number_format($unbilled), $unbilled > 0 ? 'text-blue-600' : 'text-slate-400', 'Devices sent but not on an invoice'],
        ] as [$label, $value, $tone, $hint])
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
                <p class="text-[10px] font-black uppercase tracking-wide text-slate-500">{{ $label }}</p>
                <p class="text-2xl font-black mt-1 {{ $tone }}">{{ $value }}</p>
                <p class="text-[11px] text-slate-400 mt-1">{{ $hint }}</p>
            </div>
        @endforeach
    </div>

    @if($unbilled > 0 || $pendingReceipts > 0)
        <div class="bg-blue-50 border border-blue-100 rounded-2xl p-4 text-sm text-blue-900 space-y-1">
            <p class="font-black text-xs uppercase tracking-wide">Needs your attention</p>
            @if($unbilled > 0)<p>&bull; {{ $unbilled }} {{ \Illuminate\Support\Str::plural('transfer', $unbilled) }} not invoiced yet. <a class="underline font-bold" href="{{ route('admin.billing.invoices.new') }}">Create an invoice</a></p>@endif
            @if($pendingReceipts > 0)<p>&bull; {{ $pendingReceipts }} {{ \Illuminate\Support\Str::plural('payment', $pendingReceipts) }} waiting for approval. <a class="underline font-bold" href="{{ route('admin.billing.receipts.index', ['status' => 'pending']) }}">Review</a></p>@endif
        </div>
    @endif

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-slate-100"><h2 class="font-black text-blue-950">Accounts</h2><p class="text-xs text-slate-500">Everyone who has been invoiced. Largest balance first.</p></div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-[11px] text-slate-600 uppercase tracking-wide"><tr>
                    <th class="p-3">Buyer</th><th class="p-3 text-right">Invoiced</th><th class="p-3 text-right">Paid</th><th class="p-3 text-right">Balance</th><th class="p-3 text-right">Overdue</th><th class="p-3"></th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                @forelse($rows as $r)
                    <tr>
                        <td class="p-3 font-semibold">{{ $r['dealer']->full_name }} <span class="text-[10px] font-bold uppercase text-slate-400">{{ $r['dealer']->dealer_status === 'distributor' ? 'Distributor' : 'Dealer' }}</span></td>
                        <td class="p-3 text-right">{{ $m($r['invoiced']) }}</td>
                        <td class="p-3 text-right text-emerald-700">{{ $m($r['paid']) }}</td>
                        <td class="p-3 text-right font-black {{ $r['balance'] > 0 ? 'text-blue-950' : ($r['balance'] < 0 ? 'text-emerald-700' : 'text-slate-400') }}">{{ $m($r['balance']) }}{{ $r['balance'] < 0 ? ' (credit)' : '' }}</td>
                        <td class="p-3 text-right {{ $r['overdue'] > 0 ? 'text-red-600 font-bold' : 'text-slate-400' }}">{{ $r['overdue'] > 0 ? $m($r['overdue']) : '—' }}</td>
                        <td class="p-3 text-right"><a href="{{ route('admin.billing.statement', $r['dealer']) }}" class="text-blue-600 font-bold text-xs hover:underline">Statement</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-8 text-center text-slate-400 text-sm">Nothing has been invoiced yet. Create the first invoice from a transfer.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid lg:grid-cols-2 gap-5">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm">
            <div class="p-4 border-b border-slate-100 flex justify-between"><h2 class="font-black text-blue-950">Recent invoices</h2><a href="{{ route('admin.billing.invoices.index') }}" class="text-xs font-bold text-blue-600">All &rarr;</a></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @forelse($recentInvoices as $i)
                    <li class="p-3 flex justify-between gap-3"><a class="font-bold text-blue-700 hover:underline" href="{{ route('admin.billing.invoices.show', $i) }}">{{ $i->number }}</a><span class="text-slate-500 truncate">{{ $i->buyer->full_name ?? '' }}</span><span class="font-bold">{{ $m(\App\Support\Money::dbToCents($i->total)) }}</span></li>
                @empty <li class="p-6 text-center text-slate-400 text-sm">No invoices yet.</li> @endforelse
            </ul>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm">
            <div class="p-4 border-b border-slate-100 flex justify-between"><h2 class="font-black text-blue-950">Recent payments</h2><a href="{{ route('admin.billing.receipts.index') }}" class="text-xs font-bold text-blue-600">All &rarr;</a></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @forelse($recentReceipts as $r)
                    <li class="p-3 flex justify-between gap-3"><a class="font-bold text-blue-700 hover:underline" href="{{ route('admin.billing.receipts.show', $r) }}">{{ $r->number }}</a><span class="text-slate-500 truncate">{{ $r->buyer->full_name ?? '' }}</span><span class="font-bold">{{ $m(\App\Support\Money::dbToCents($r->amount)) }}</span></li>
                @empty <li class="p-6 text-center text-slate-400 text-sm">No payments yet.</li> @endforelse
            </ul>
        </div>
    </div>
</div>
@endsection
