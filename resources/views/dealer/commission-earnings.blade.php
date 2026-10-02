@extends('layouts.dealer')

@section('title', 'My commission')

@section('content')
@php
    use App\Services\CommissionStatement;
    $money = fn ($c) => CommissionStatement::money((int) $c);
    $ranges = [1 => 'This month', 3 => 'Last 3 months', 6 => 'Last 6 months', 12 => 'Last 12 months'];
@endphp
<div class="max-w-5xl mx-auto space-y-5">
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm space-y-3">
        <div>
            <h1 class="text-2xl font-black text-blue-950">My commission</h1>
            <p class="text-xs text-slate-500 mt-0.5">
                What you earn each time a customer's package is paid: your margin on that package. Months are Sri Lanka calendar months.
                A month is paid to you after it ends, and then appears under
                <a href="{{ route('dealer.commission-statements') }}" class="text-blue-700 underline">Commission statements</a>
                with the payment reference. Figures for a month still running can change if a payment is corrected.
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            @foreach($ranges as $r => $label)
                <a href="{{ route('dealer.commission-earnings', ['range' => $r]) }}"
                   class="px-4 py-2 rounded-xl text-xs font-bold border {{ $range === $r ? 'bg-blue-950 text-white border-blue-950' : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50' }}">{{ $label }}</a>
            @endforeach
        </div>
    </div>

    @unless($hasDealer)
        <div class="bg-amber-50 border border-amber-300 text-amber-900 text-sm rounded-xl p-4">Your login is not linked to a dealer record yet, so there is nothing to show. Ask ShaloTrack to link it.</div>
    @endunless

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="bg-white rounded-2xl border border-slate-200 p-4"><div class="text-[11px] uppercase text-slate-500 font-bold">Earned</div><div class="text-xl font-black text-blue-950">{{ $money($total['earned']) }}</div></div>
        <div class="bg-white rounded-2xl border border-slate-200 p-4"><div class="text-[11px] uppercase text-slate-500 font-bold">Reversed</div><div class="text-xl font-black text-red-700">{{ $money($total['clawback']) }}</div></div>
        <div class="bg-white rounded-2xl border border-slate-200 p-4"><div class="text-[11px] uppercase text-slate-500 font-bold">Already paid to you</div><div class="text-xl font-black text-emerald-700">{{ $money($total['paid']) }}</div></div>
        <div class="bg-white rounded-2xl border border-slate-200 p-4"><div class="text-[11px] uppercase text-slate-500 font-bold">Not paid yet</div><div class="text-xl font-black text-amber-700">{{ $money($total['outstanding']) }}</div></div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <table class="w-full text-left border-collapse">
            <thead class="bg-slate-50 border-b border-slate-200 text-[11px] text-slate-600 uppercase tracking-wide">
                <tr>
                    <th class="p-3">Month</th>
                    <th class="p-3 text-right">Earned</th>
                    <th class="p-3 text-right">Reversed</th>
                    <th class="p-3 text-right">Net</th>
                    <th class="p-3 text-right">Paid</th>
                    <th class="p-3 text-right">Not paid yet</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-sm text-slate-700">
                @foreach($perMonth as $m)
                    <tr>
                        <td class="p-3 font-semibold">{{ $m['label'] }}</td>
                        <td class="p-3 text-right">{{ $money($m['earned']) }}</td>
                        <td class="p-3 text-right text-red-700">{{ $money($m['clawback']) }}</td>
                        <td class="p-3 text-right font-bold">{{ $money($m['earned'] + $m['clawback']) }}</td>
                        <td class="p-3 text-right text-emerald-700">{{ $money($m['paid']) }}</td>
                        <td class="p-3 text-right text-amber-700">{{ $money($m['outstanding']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 font-black text-slate-900">Every line</div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead class="bg-slate-50 border-b border-slate-200 text-[11px] text-slate-600 uppercase tracking-wide">
                    <tr>
                        <th class="p-3">Date</th>
                        <th class="p-3">Vehicle / customer</th>
                        <th class="p-3">Package</th>
                        <th class="p-3">You earned as</th>
                        <th class="p-3">Paid?</th>
                        <th class="p-3 text-right">Amount (LKR)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-sm text-slate-700">
                    @forelse($lines as $l)
                        <tr>
                            <td class="p-3 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($l->occurred_at, 'UTC')->setTimezone(CommissionStatement::TZ)->format('Y-m-d') }}</td>
                            <td class="p-3">{{ $l->vehicle_number ?: '—' }}<div class="text-[11px] text-slate-400">{{ $l->customer_name }}</div></td>
                            <td class="p-3">{{ $l->package_label ?: $l->package_model }}</td>
                            <td class="p-3">
                                {{ ucfirst($l->role) }}
                                @if($l->entry_type === 'clawback')
                                    <div class="text-[11px] text-red-700 font-bold">Reversed{{ $l->reason ? ': ' . $l->reason : '' }}</div>
                                @endif
                            </td>
                            <td class="p-3 text-xs">
                                @if($l->payout_id)
                                    <span class="text-emerald-700 font-bold">Paid</span>
                                    <div class="text-slate-500">{{ $l->payout_paid_on }} &middot; {{ $l->payout_reference }}</div>
                                @else
                                    <span class="text-amber-700 font-bold">Not yet</span>
                                @endif
                            </td>
                            <td class="p-3 text-right font-semibold {{ $l->amount_cents < 0 ? 'text-red-700' : '' }}">{{ $money($l->amount_cents) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-8 text-center text-slate-400 text-sm">Nothing earned in this period yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection