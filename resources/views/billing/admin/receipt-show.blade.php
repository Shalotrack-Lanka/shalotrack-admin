@extends('layouts.admin')
@section('title', $receipt->number)
@section('content')
@php $m = fn ($c) => \App\Support\Money::format((int) $c);
     $tone = ['valid' => 'bg-emerald-100 text-emerald-700', 'pending' => 'bg-amber-100 text-amber-800', 'reversed' => 'bg-slate-200 text-slate-600', 'rejected' => 'bg-red-100 text-red-700'];
     $mine = (string) $receipt->recorded_by === $me; @endphp
<div class="space-y-5 max-w-4xl">
    @include('billing.admin._nav', ['heading' => 'Payment ' . $receipt->number])
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-4">
        <div class="flex flex-wrap justify-between gap-3">
            <div><p class="text-[10px] font-black uppercase text-slate-500">Received from</p><p class="font-black text-blue-950 text-lg">{{ $receipt->buyer->full_name ?? '—' }}</p>
                <p class="text-xs text-slate-500">{{ \App\Models\PaymentReceipt::METHODS[$receipt->method] ?? $receipt->method }}{{ $receipt->reference ? ' · ref ' . $receipt->reference : '' }} · received {{ $receipt->received_date->toDateString() }}</p></div>
            <div class="text-right"><p class="text-3xl font-black text-blue-950">LKR {{ $m(\App\Support\Money::dbToCents($receipt->amount)) }}</p><span class="px-3 py-1 rounded-lg text-xs font-black {{ $tone[$receipt->status] ?? '' }}">{{ ucfirst($receipt->status) }}</span></div>
        </div>
        <p class="text-xs text-slate-500">Recorded by {{ $receipt->recorded_by_name }} on {{ $receipt->created_at?->local()->format('Y-m-d H:i') }}
            @if($receipt->approved_by_name) · {{ $receipt->status === 'rejected' ? 'decided' : 'approved' }} by {{ $receipt->approved_by_name }} {{ $receipt->approved_at?->local()->format('Y-m-d H:i') }}@endif</p>
        @if($receipt->notes)<p class="text-sm"><b>Note:</b> {{ $receipt->notes }}</p>@endif
        @if($receipt->rejection_reason)<p class="text-sm text-red-700"><b>Rejected:</b> {{ $receipt->rejection_reason }}</p>@endif
        @if($receipt->reversal_reason)<p class="text-sm text-slate-700"><b>Reversed {{ $receipt->reversed_at?->local()->format('Y-m-d H:i') }}:</b> {{ $receipt->reversal_reason }}</p>@endif
        @if($receipt->allocations->isNotEmpty())
            <div><p class="text-[10px] font-black uppercase text-slate-500 mb-1">Applied to</p>
                @foreach($receipt->allocations as $a)<div class="flex justify-between text-sm"><a class="text-blue-700 font-bold hover:underline" href="{{ route('admin.billing.invoices.show', $a->invoice_id) }}">{{ $a->invoice->number }}</a><b>{{ $m(\App\Support\Money::dbToCents($a->amount)) }}</b></div>@endforeach</div>
        @endif
        @if($errors->has('action'))<p class="text-sm text-red-700 bg-red-50 rounded-xl p-3">{{ $errors->first('action') }}</p>@endif
    </div>

    <div class="flex flex-wrap gap-3 items-start">
        @if(in_array($receipt->status, ['valid', 'reversed']))@can('billing.statement.export')<a href="{{ route('admin.billing.receipts.pdf', $receipt) }}" class="px-4 py-2 rounded-xl bg-blue-950 text-white text-xs font-bold">Download receipt PDF</a>@endcan @endif
        @if($receipt->status === 'pending')
            @can('billing.receipt.approve')
                @if($mine)<p class="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-xl p-3">You recorded this payment, so another administrator has to approve or reject it.</p>
                @else
                    <form method="POST" action="{{ route('admin.billing.receipts.approve', $receipt) }}" onsubmit="return confirm('Approve this payment? It will count against the buyer\'s balance.')">@csrf<button class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold">Approve</button></form>
                    <form method="POST" action="{{ route('admin.billing.receipts.reject', $receipt) }}" class="flex gap-2">@csrf<input name="reason" required maxlength="255" placeholder="Reason for rejecting" class="text-xs border border-slate-300 rounded-xl px-3 py-2"><button class="px-4 py-2 rounded-xl bg-red-600 text-white text-xs font-bold">Reject</button></form>
                @endif
            @endcan
        @endif
        @if($receipt->status === 'valid')
            @can('billing.receipt.reverse')
                <form method="POST" action="{{ route('admin.billing.receipts.reverse', $receipt) }}" class="flex gap-2" onsubmit="return confirm('Reverse this payment? The buyer will owe this amount again.')">@csrf<input name="reason" required maxlength="255" placeholder="Reason (e.g. cheque bounced)" class="text-xs border border-slate-300 rounded-xl px-3 py-2 w-64"><button class="px-4 py-2 rounded-xl bg-slate-700 text-white text-xs font-bold">Reverse payment</button></form>
            @endcan
        @endif
    </div>
</div>
@endsection
