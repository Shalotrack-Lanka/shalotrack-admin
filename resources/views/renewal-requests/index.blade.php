@extends('layouts.admin')

@section('title', 'Renewal requests')

@section('content')
@php
    $labels = ['PendingReview' => 'Waiting for review', 'AwaitingSlip' => 'Awaiting slip', 'Approved' => 'Approved', 'Rejected' => 'Rejected', 'Cancelled' => 'Cancelled', 'all' => 'All'];
    $badge = ['PendingReview' => 'bg-blue-100 text-blue-700', 'AwaitingSlip' => 'bg-slate-100 text-slate-600', 'Approved' => 'bg-emerald-100 text-emerald-700', 'Rejected' => 'bg-red-100 text-red-700', 'Cancelled' => 'bg-slate-100 text-slate-500'];
@endphp

<div class="space-y-5">
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <h1 class="text-2xl font-black text-blue-950">Renewal requests</h1>
        <p class="text-xs text-slate-500 mt-0.5">
            Customers who paid by bank transfer and sent the slip from the app. Check the slip, update the device's subscription in
            <a href="{{ route('admin.customer-device-management') }}" class="text-blue-700 underline">Device management</a>, then approve here.
            Approving never changes a subscription by itself.
        </p>
        <div class="flex flex-wrap gap-2 mt-3">
            @foreach($statuses as $s)
                <a href="{{ route('admin.renewal-requests', ['status' => $s]) }}"
                   class="px-4 py-2 rounded-xl text-xs font-bold border {{ $status === $s ? 'bg-blue-950 text-white border-blue-950' : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50' }}">
                    {{ $labels[$s] }}
                </a>
            @endforeach
        </div>
    </div>

    @if(session('success'))
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded-xl p-4">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-800 text-sm rounded-xl p-4">
            @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
        </div>
    @endif

    @unless($available)
        <div class="bg-amber-50 border border-amber-300 text-amber-900 text-sm rounded-xl p-4">
            <b>The mobile API could not be reached,</b> so this list is unavailable. It is not empty: try again in a minute.
        </div>
    @else
        @forelse($items as $item)
            @php
                $r = $item['row'];
                $d = $item['device'];
                $st = $r['status'] ?? 'PendingReview';
                $sent = !empty($r['slipUploadedAt']) ? \Illuminate\Support\Carbon::parse($r['slipUploadedAt'])->local()->format('Y-m-d H:i') : null;
            @endphp
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <div class="text-lg font-black text-blue-950">{{ $r['vehicleNumber'] ?? '' }}
                            <span class="ml-2 text-[11px] font-bold px-2 py-0.5 rounded-full {{ $badge[$st] ?? 'bg-slate-100 text-slate-600' }}">{{ $labels[$st] ?? $st }}</span>
                        </div>
                        <div class="text-xs text-slate-600">{{ $r['customerName'] ?? '' }} · {{ $r['customerPhone'] ?? '' }} · IMEI {{ $r['imeiNumber'] ?? '' }}</div>
                    </div>
                    <div class="text-sm font-bold text-slate-800">Wants: {{ $r['durationModel'] ?? '' }}
                        @if(isset($r['amountLkr']) && $r['amountLkr'] !== null)
                            <div class="text-xs font-semibold text-emerald-700">Price shown to customer: LKR {{ number_format((float) $r['amountLkr'], 2) }}</div>
                        @endif
                    </div>
                </div>

                <div class="grid md:grid-cols-2 gap-3 text-xs text-slate-700">
                    <div>
                        <div><b>Slip sent:</b> {{ $sent ?? 'not yet' }}</div>
                        @if(!empty($r['paymentReference']))<div><b>Reference:</b> {{ $r['paymentReference'] }}</div>@endif
                        @if(!empty($r['customerNote']))<div><b>Note:</b> {{ $r['customerNote'] }}</div>@endif
                        @if(!empty($r['decisionReason']))<div><b>Reason:</b> {{ $r['decisionReason'] }}</div>@endif
                        @if(!empty($r['decidedBy']))<div><b>Decided by:</b> {{ $r['decidedBy'] }}</div>@endif
                        @if(!empty($r['hasSlip']))
                            <a href="{{ route('admin.renewal-requests.slip', $r['renewalRequestId']) }}" target="_blank" rel="noopener"
                               class="inline-block mt-2 text-blue-700 underline font-bold">View slip</a>
                        @endif
                    </div>
                    <div>
                        <div><b>Device now:</b>
                            @if($d)
                                {{ $d->payment_status }} · {{ $d->subscription_model ?: 'no plan' }}
                                @if($d->subscription_end_date) · ends {{ $d->subscription_end_date->copy()->local()->format('Y-m-d') }} @endif
                            @else
                                not bound in the admin portal
                            @endif
                        </div>
                        @if($st === 'PendingReview')
                            <div class="mt-1 {{ $item['ready'] ? 'text-emerald-700' : 'text-amber-700' }} font-semibold">
                                {{ $item['ready'] ? 'Device is updated for this renewal. Ready to approve.' : 'Not ready: '.$item['why'] }}
                            </div>
                        @endif
                    </div>
                </div>

                @if($st === 'PendingReview')
                    <div class="flex flex-wrap gap-3 items-start">
                        <form method="POST" action="{{ route('admin.renewal-requests.decide', $r['renewalRequestId']) }}"
                              onsubmit="return confirm('Approve this renewal and notify the customer?')">
                            @csrf
                            <input type="hidden" name="decision" value="approve">
                            <button type="submit" {{ $item['ready'] ? '' : 'disabled' }}
                                    class="px-4 py-2 rounded-xl text-xs font-bold text-white {{ $item['ready'] ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-slate-300 cursor-not-allowed' }}">
                                Approve
                            </button>
                        </form>
                        <form method="POST" action="{{ route('admin.renewal-requests.decide', $r['renewalRequestId']) }}" class="flex flex-wrap gap-2 items-center">
                            @csrf
                            <input type="hidden" name="decision" value="reject">
                            <input type="text" name="reason" maxlength="300" required placeholder="Reason (the customer sees this)"
                                   class="text-xs border border-slate-300 rounded-xl px-3 py-2 w-72">
                            <button type="submit" class="px-4 py-2 rounded-xl text-xs font-bold text-white bg-red-600 hover:bg-red-700">Reject</button>
                        </form>
                    </div>
                @endif
            </div>
        @empty
            <div class="bg-white p-8 rounded-2xl border border-slate-200 text-center text-sm text-slate-500">Nothing here.</div>
        @endforelse
    @endunless
</div>
@endsection