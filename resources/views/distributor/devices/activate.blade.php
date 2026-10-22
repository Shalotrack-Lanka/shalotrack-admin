@extends('layouts.distributor')

@section('title', 'Activate device')

@section('content')
<div class="max-w-3xl mx-auto space-y-6" x-data="{ submitting: false }">
    @include('distributor.partials.alerts')

    <div>
        <a href="{{ route('distributor.devices') }}" class="text-sm font-semibold text-blue-700 hover:underline">&larr; Back</a>
        <h1 class="text-2xl font-black text-slate-900 mt-1">Activate device <span class="font-mono text-lg">{{ $device->imei_number }}</span></h1>
        <p class="text-sm text-slate-500">{{ $soldTo ? "Sold to {$soldTo}." : 'From your own stock.' }} Choose the vehicle and the plan the customer paid for.</p>
    </div>

    @if ($vehicles->isEmpty())
        <div class="rounded-3xl border border-amber-200 bg-amber-50 p-6 text-sm text-amber-900">
            No vehicle is available for this customer yet. The customer must sign in to the Shalotrack app and add their vehicle first, using the same phone number or email that was registered here.
        </div>
    @else
    <form method="POST" action="{{ route('distributor.devices.activate.store', $device->shdevice_id) }}" @submit="submitting = true" class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6 space-y-5">
        @csrf
        <div>
            <label for="vehicle_id" class="block text-xs font-bold text-slate-600 mb-1">Vehicle</label>
            <select id="vehicle_id" name="vehicle_id" required class="w-full rounded-xl border-slate-300 text-sm">
                <option value="">Choose a vehicle</option>
                @foreach ($vehicles as $v)
                    <option value="{{ $v->vehicle_id }}" @selected(old('vehicle_id') === $v->vehicle_id)>{{ $v->vehicle_number }} &middot; {{ $v->customer_name }}@if($v->make) &middot; {{ $v->make }} {{ $v->model }}@endif</option>
                @endforeach
            </select>
        </div>

        <div>
            <p class="block text-xs font-bold text-slate-600 mb-2">Plan</p>
            <div class="grid sm:grid-cols-2 gap-3">
                @foreach ($plans as $p)
                    <label class="cursor-pointer rounded-2xl border border-slate-200 p-4 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50 flex items-start gap-3">
                        <input type="radio" name="plan" value="{{ $p->model }}" required class="mt-1 text-blue-600" @checked(old('plan') === $p->model)>
                        <span>
                            <span class="block font-semibold text-slate-900">{{ $p->label }}</span>
                            <span class="block text-xs text-slate-500">{{ $p->positioning }}</span>
                            <span class="block mt-1 text-sm font-black text-blue-950">LKR {{ number_format((float) $p->customer_price, 2) }}</span>
                            @if ($p->warranty_months > 0)<span class="block text-[11px] text-emerald-700">+ {{ $p->warranty_months }} months warranty</span>@endif
                        </span>
                    </label>
                @endforeach
            </div>
            <p class="mt-2 text-xs text-slate-500">The subscription starts today and runs for the plan's term.</p>
        </div>

        <div class="grid sm:grid-cols-3 gap-4">
            <div class="sm:col-span-1">
                <label for="bank_invoice" class="block text-xs font-bold text-slate-600 mb-1">Payment reference</label>
                <input id="bank_invoice" name="bank_invoice" value="{{ old('bank_invoice') }}" required maxlength="60" class="w-full rounded-xl border-slate-300 text-sm" placeholder="Bank / receipt no.">
            </div>
            <div>
                <label for="installed_on" class="block text-xs font-bold text-slate-600 mb-1">Installed on</label>
                <input id="installed_on" type="date" name="installed_on" value="{{ old('installed_on') }}" max="{{ now()->local()->toDateString() }}" class="w-full rounded-xl border-slate-300 text-sm">
            </div>
            <div>
                <label for="installed_by" class="block text-xs font-bold text-slate-600 mb-1">Installed by</label>
                <input id="installed_by" name="installed_by" value="{{ old('installed_by') }}" maxlength="100" class="w-full rounded-xl border-slate-300 text-sm">
            </div>
        </div>

        <button type="submit" :disabled="submitting" class="rounded-2xl bg-blue-600 px-6 py-3 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-60">Activate and record payment</button>
        <p class="text-xs text-slate-500">A payment cannot be edited afterwards. If something is wrong, ask the company to correct it.</p>
    </form>
    @endif
</div>
@endsection