@extends('layouts.admin')

@section('title', 'Commission statement')

@section('content')
@php
    use App\Services\CommissionStatement;
    $dealer = $s['dealer'];
    $payout = $s['payout'];
    $period = $s['period'];
@endphp

<div class="space-y-5">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <div>
            <a href="{{ route('admin.commission-payouts', ['month' => $month]) }}" class="text-xs text-blue-700 hover:underline">← All dealers</a>
            <h1 class="text-2xl font-black text-blue-950 mt-1">{{ $dealer->full_name ?: 'Dealer #' . $dealer->id }}</h1>
            <p class="text-sm text-slate-500">Commission statement · {{ $period['label'] }}</p>
        </div>
        <a href="{{ route('admin.commission-payouts.show', [$dealer, 'month' => $month, 'export' => 'csv']) }}"
           class="px-4 py-2 rounded-xl text-xs font-bold border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 self-start">
            Export CSV
        </a>
    </div>

    @if(session('success'))
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded-xl p-3">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-800 text-sm rounded-xl p-3">{{ $errors->first() }}</div>
    @endif
    @unless($ratesConfigured)
        <div class="bg-red-50 border border-red-200 text-red-800 text-sm rounded-xl p-4">
            <b>No commission rates are configured.</b> These amounts use the unapproved built-in fallback of LKR 1,000 per device.
        </div>
    @endunless

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="bg-white rounded-2xl border border-slate-200 p-4">
            <div class="text-[11px] uppercase font-bold text-slate-500 tracking-wide">Payable sales</div>
            <div class="text-xl font-black text-blue-950 mt-1">{{ CommissionStatement::money($s['gross_cents']) }}</div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-4">
            <div class="text-[11px] uppercase font-bold text-slate-500 tracking-wide">Clawbacks</div>
            <div class="text-xl font-black mt-1 {{ $s['clawback_cents'] < 0 ? 'text-red-700' : 'text-blue-950' }}">{{ CommissionStatement::money($s['clawback_cents']) }}</div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-4">
            <div class="text-[11px] uppercase font-bold text-slate-500 tracking-wide">{{ $payout ? 'Paid' : 'Net payable' }}</div>
            <div class="text-xl font-black text-emerald-700 mt-1">{{ CommissionStatement::money($s['net_cents']) }}</div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-4">
            <div class="text-[11px] uppercase font-bold text-slate-500 tracking-wide">Not payable yet</div>
            <div class="text-xl font-black text-slate-500 mt-1">{{ $payout ? '—' : CommissionStatement::money($s['pending_cents']) }}</div>
        </div>
    </div>

    @if($payout)
        <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-4 text-sm text-emerald-900">
            <div class="font-black">Paid on {{ $payout->paid_on->format('Y-m-d') }}</div>
            <div>Reference: <span class="font-mono">{{ $payout->reference }}</span> · recorded by {{ $payout->paid_by_name }}</div>
            @if($payout->notes)<div class="mt-1 text-xs">{{ $payout->notes }}</div>@endif
            <div class="mt-1 text-xs text-emerald-700">This statement is final. It cannot be edited or paid again.</div>
        </div>
    @elseif($s['status'] === 'negative')
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 text-sm text-amber-900">
            The net balance is not positive, so there is nothing to pay for {{ $period['label'] }}. These lines stay unpaid and roll into the next statement.
        </div>
    @elseif($s['status'] === 'open')
        <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4 text-sm text-slate-700">
            {{ $period['label'] }} is still in progress, so this is a preview. It can be paid once the month has ended.
        </div>
    @endif

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 font-black text-slate-900">{{ $payout ? 'Lines paid' : 'Payable lines' }}</div>
        @include('commission-payouts._lines', ['lines' => $s['lines'], 'showReason' => false])
    </div>

    @if($s['status'] === 'ready')
        <form method="POST" action="{{ route('admin.commission-payouts.pay', $dealer) }}"
              class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-4"
              onsubmit="return confirm('Record LKR {{ CommissionStatement::money($s['net_cents']) }} as PAID to this dealer for {{ $period['label'] }}? This cannot be undone.');">
            @csrf
            <input type="hidden" name="month" value="{{ $month }}">
            <div class="font-black text-slate-900">Mark as paid</div>
            <p class="text-xs text-slate-500">Do this only after the money has actually been sent. It locks these lines so they can never be paid again.</p>

            <div class="grid md:grid-cols-2 gap-4">
                <label class="block text-xs font-bold text-slate-600">Bank transfer / cheque reference
                    <input type="text" name="reference" value="{{ old('reference') }}" maxlength="100" required
                           class="mt-1 w-full border border-slate-300 rounded-xl px-3 py-2 text-sm font-normal">
                </label>
                <label class="block text-xs font-bold text-slate-600">Date paid
                    <input type="date" name="paid_on" value="{{ old('paid_on', $today) }}" max="{{ $today }}" required
                           class="mt-1 w-full border border-slate-300 rounded-xl px-3 py-2 text-sm font-normal">
                </label>
            </div>
            <label class="block text-xs font-bold text-slate-600">Notes (optional)
                <textarea name="notes" maxlength="500" rows="2" class="mt-1 w-full border border-slate-300 rounded-xl px-3 py-2 text-sm font-normal">{{ old('notes') }}</textarea>
            </label>

            <button type="submit" class="px-5 py-2.5 rounded-xl text-sm font-bold bg-blue-950 text-white hover:bg-blue-900">
                Record payment of LKR {{ CommissionStatement::money($s['net_cents']) }}
            </button>
        </form>
    @endif

    @if(! $payout && $s['pending'])
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100">
                <div class="font-black text-slate-900">Not payable yet</div>
                <div class="text-xs text-slate-500">Recorded as sold but not eligible at the end of {{ $period['label'] }}. These are never paid until they qualify.</div>
            </div>
            @include('commission-payouts._lines', ['lines' => $s['pending'], 'showReason' => true])
        </div>
    @endif
</div>
@endsection