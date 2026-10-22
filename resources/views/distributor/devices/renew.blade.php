@extends('layouts.distributor')

@section('title', 'Renew subscription')

@section('content')
<div class="max-w-3xl mx-auto space-y-6" x-data="{ submitting: false }">
    @include('distributor.partials.alerts')

    <div>
        <a href="{{ route('distributor.devices') }}" class="text-sm font-semibold text-blue-700 hover:underline">&larr; Back</a>
        <h1 class="text-2xl font-black text-slate-900 mt-1">Renew {{ $row->vehicle_number }}</h1>
        <p class="text-sm text-slate-500">{{ $row->customer_name }} &middot; device <span class="font-mono">{{ $row->imei_number }}</span></p>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4 text-sm">
        @if ($runningUntil)
            Current subscription runs until <strong>{{ $runningUntil->format('Y-m-d') }}</strong>. The new period starts on that day, so no paid days are lost.
        @else
            The subscription has lapsed. The new period starts today.
        @endif
    </div>

    <form method="POST" action="{{ route('distributor.devices.renew.store', $row->activated_device_id) }}" @submit="submitting = true" class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6 space-y-5">
        @csrf
        <div class="grid sm:grid-cols-2 gap-3">
            @foreach ($plans as $p)
                <label class="cursor-pointer rounded-2xl border border-slate-200 p-4 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50 flex items-start gap-3">
                    <input type="radio" name="plan" value="{{ $p->model }}" required class="mt-1 text-blue-600" @checked(old('plan') === $p->model)>
                    <span>
                        <span class="block font-semibold text-slate-900">{{ $p->label }}</span>
                        <span class="block text-xs text-slate-500">{{ $p->positioning }}</span>
                        <span class="block mt-1 text-sm font-black text-blue-950">LKR {{ number_format((float) $p->customer_price, 2) }}</span>
                    </span>
                </label>
            @endforeach
        </div>
        <div class="max-w-xs">
            <label for="bank_invoice" class="block text-xs font-bold text-slate-600 mb-1">Payment reference</label>
            <input id="bank_invoice" name="bank_invoice" value="{{ old('bank_invoice') }}" required maxlength="60" class="w-full rounded-xl border-slate-300 text-sm" placeholder="Bank / receipt no.">
        </div>
        <button type="submit" :disabled="submitting" class="rounded-2xl bg-blue-600 px-6 py-3 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-60">Renew and record payment</button>
        <p class="text-xs text-slate-500">The warranty keeps counting from the original activation date. A recorded payment cannot be edited.</p>
    </form>
</div>
@endsection