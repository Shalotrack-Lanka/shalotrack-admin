@extends('layouts.admin')

@section('title', 'Commission payouts')

@section('content')
@php
    use App\Services\CommissionStatement;
    $statusStyle = [
        'paid'     => ['Paid', 'bg-emerald-100 text-emerald-700'],
        'ready'    => ['Ready to pay', 'bg-blue-100 text-blue-700'],
        'open'     => ['Month still open', 'bg-slate-100 text-slate-600'],
        'negative' => ['Carried forward', 'bg-amber-100 text-amber-800'],
        'nothing'  => ['Nothing payable', 'bg-slate-100 text-slate-500'],
    ];
@endphp

<div class="space-y-5">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <div>
            <h1 class="text-2xl font-black text-blue-950">Commission payouts</h1>
            <p class="text-xs text-slate-500 mt-0.5">
                Monthly statements. Package-margin commission (retailer and distributor) is payable once its month has ended. Old fixed-rate sales keep the {{ CommissionStatement::PAYABLE_AFTER_DAYS }}-day rule.
            </p>
        </div>
        <form method="GET" action="{{ route('admin.commission-payouts') }}">
            <select name="month" onchange="this.form.submit()" class="text-xs font-bold border border-slate-300 rounded-xl px-3 py-2 bg-slate-50">
                @foreach($months as $value => $label)
                    <option value="{{ $value }}" {{ $value === $month ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </form>
    </div>

    @unless($ratesConfigured)
        <div class="bg-red-50 border border-red-200 text-red-800 text-sm rounded-xl p-4">
            <b>No commission rates are configured.</b> Old fixed-rate sales still waiting to be paid use the built-in fallback of LKR 1,000 per device, which nobody has approved. Check them before paying anyone. New package-margin commission is not affected.
        </div>
    @endunless

    @if($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-800 text-sm rounded-xl p-3">{{ $errors->first() }}</div>
    @endif

    <div class="bg-amber-50 border border-amber-200 text-amber-900 text-xs rounded-xl p-3 leading-relaxed">
        Only sales recorded since the commission ledger went live are included; earlier sales were never backfilled and are <b>not</b> on these statements.
    </div>

    @unless($period['ended'])
        <div class="bg-slate-50 border border-slate-200 text-slate-700 text-xs rounded-xl p-3">
            {{ $period['label'] }} is still in progress. These figures are a preview; a statement can only be paid after the month ends.
        </div>
    @endunless

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead class="bg-slate-50 border-b border-slate-200 text-[11px] text-slate-600 uppercase tracking-wide">
                    <tr>
                        <th class="p-3">Dealer</th>
                        <th class="p-3 text-right">Payable sales</th>
                        <th class="p-3 text-right">Clawbacks</th>
                        <th class="p-3 text-right">Net</th>
                        <th class="p-3 text-right">Not payable yet</th>
                        <th class="p-3">Status</th>
                        <th class="p-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-sm text-slate-700">
                    @forelse($rows as $r)
                        @php [$label, $tone] = $statusStyle[$r['status']]; @endphp
                        <tr>
                            <td class="p-3 font-semibold">{{ $r['dealer']->full_name ?: 'Dealer #' . $r['dealer']->id }}</td>
                            <td class="p-3 text-right">{{ CommissionStatement::money($r['gross_cents']) }}</td>
                            <td class="p-3 text-right {{ $r['clawback_cents'] < 0 ? 'text-red-700' : '' }}">{{ CommissionStatement::money($r['clawback_cents']) }}</td>
                            <td class="p-3 text-right font-bold">{{ CommissionStatement::money($r['net_cents']) }}</td>
                            <td class="p-3 text-right text-slate-500">{{ $r['pending_cents'] ? CommissionStatement::money($r['pending_cents']) : '—' }}</td>
                            <td class="p-3">
                                <span class="inline-block px-2.5 py-1 rounded-lg text-[11px] font-bold {{ $tone }}">{{ $label }}</span>
                                @if($r['payout'])<div class="text-[11px] text-slate-400 mt-1">ref {{ $r['payout']->reference }}</div>@endif
                            </td>
                            <td class="p-3 text-right">
                                <a href="{{ route('admin.commission-payouts.show', [$r['dealer'], 'month' => $month]) }}" class="text-blue-700 font-bold text-xs hover:underline">Open statement</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="p-8 text-center text-slate-400 text-sm">No commission activity for {{ $period['label'] }}.</td></tr>
                    @endforelse
                </tbody>
                @if($readyCents > 0)
                    <tfoot class="border-t border-slate-200 bg-slate-50 text-sm">
                        <tr>
                            <td class="p-3 font-bold" colspan="3">Ready to pay this month</td>
                            <td class="p-3 text-right font-black">{{ CommissionStatement::money($readyCents) }}</td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>
@endsection