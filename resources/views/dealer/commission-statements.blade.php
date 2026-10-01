@extends('layouts.dealer')

@section('title', 'Commission statements')

@section('content')
@php use App\Services\CommissionStatement; @endphp
<div class="max-w-4xl mx-auto space-y-5">
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <h1 class="text-2xl font-black text-blue-950">Commission statements</h1>
        <p class="text-xs text-slate-500 mt-0.5">Statements ShaloTrack has paid to you. Each month appears here once it has been paid.</p>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <table class="w-full text-left border-collapse">
            <thead class="bg-slate-50 border-b border-slate-200 text-[11px] text-slate-600 uppercase tracking-wide">
                <tr>
                    <th class="p-3">Month</th>
                    <th class="p-3">Paid on</th>
                    <th class="p-3">Reference</th>
                    <th class="p-3 text-right">Amount (LKR)</th>
                    <th class="p-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-sm text-slate-700">
                @forelse($payouts as $p)
                    <tr>
                        <td class="p-3 font-semibold">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $p->period)->format('F Y') }}</td>
                        <td class="p-3">{{ $p->paid_on->format('Y-m-d') }}</td>
                        <td class="p-3 font-mono text-xs">{{ $p->reference }}</td>
                        <td class="p-3 text-right font-bold">{{ CommissionStatement::money((int) round($p->net_amount * 100)) }}</td>
                        <td class="p-3 text-right"><a href="{{ route('dealer.commission-statement', $p->period) }}" class="text-blue-700 font-bold text-xs hover:underline">View</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-8 text-center text-slate-400 text-sm">No paid statements yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection