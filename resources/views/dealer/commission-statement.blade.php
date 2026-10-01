@extends('layouts.dealer')

@section('title', 'Commission statement')

@section('content')
@php
    use App\Services\CommissionStatement;
    $payout = $s['payout'];
@endphp
<div class="max-w-5xl mx-auto space-y-5">
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <a href="{{ route('dealer.commission-statements') }}" class="text-xs text-blue-700 hover:underline">← All statements</a>
        <h1 class="text-2xl font-black text-blue-950 mt-1">{{ $s['period']['label'] }}</h1>
        <p class="text-sm text-slate-600 mt-1">
            Paid LKR <b>{{ CommissionStatement::money($s['net_cents']) }}</b> on {{ $payout->paid_on->format('Y-m-d') }}
            · reference <span class="font-mono">{{ $payout->reference }}</span>
        </p>
        <p class="text-xs text-slate-400 mt-1">
            Sales {{ CommissionStatement::money($s['gross_cents']) }}
            @if($s['clawback_cents'] < 0) · Adjustments {{ CommissionStatement::money($s['clawback_cents']) }} @endif
        </p>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        @include('commission-payouts._lines', ['lines' => $s['lines'], 'showReason' => false])
    </div>
</div>
@endsection