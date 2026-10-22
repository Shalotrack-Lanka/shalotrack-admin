@extends('layouts.distributor')

@section('title', 'Activate & Renew Devices')

@section('content')
<div class="max-w-7xl mx-auto space-y-6">
    @include('distributor.partials.alerts')

    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
        <div>
            <h1 class="text-2xl font-black text-slate-900">Activate &amp; renew devices</h1>
            <p class="text-sm text-slate-500">Bind a device to the customer's vehicle, choose the plan and record the payment. Prices come from the company price list.</p>
        </div>
        <a href="{{ route('distributor.customers') }}" class="text-sm font-semibold text-blue-700 hover:underline">My customers &rarr;</a>
    </div>

    <section class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="p-5 border-b border-slate-100">
            <h2 class="font-bold text-slate-900">Ready to activate <span class="text-slate-400 font-normal text-sm">({{ $ready->count() }})</span></h2>
            <p class="text-xs text-slate-500">Your own unsold stock, and devices your dealers have already sold to a customer.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-600"><tr><th class="p-4">IMEI</th><th class="p-4">Type</th><th class="p-4">Held by</th><th class="p-4">Sold to</th><th class="p-4"></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($ready as $d)
                    <tr class="hover:bg-slate-50">
                        <td class="p-4 font-mono">{{ $d->imei_number }}</td>
                        <td class="p-4">{{ $d->device_category ?: '—' }}</td>
                        <td class="p-4">{{ $holders[$d->dealer_id] ?? '—' }}</td>
                        <td class="p-4">{{ $d->assigned_customer_id ? ($leads[$d->assigned_customer_id] ?? '—') : 'Not sold yet (my stock)' }}</td>
                        <td class="p-4 text-right"><a href="{{ route('distributor.devices.activate', $d->shdevice_id) }}" class="rounded-xl bg-blue-600 px-4 py-2 text-xs font-bold text-white hover:bg-blue-700">Activate</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-10 text-center text-slate-400">Nothing to activate. Devices appear here once they are in your stock or sold by one of your dealers.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="p-5 border-b border-slate-100">
            <h2 class="font-bold text-slate-900">Active devices &amp; subscriptions</h2>
            <p class="text-xs text-slate-500">Devices bound to vehicles under your network. Renewing adds the new period after the current expiry.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-600"><tr><th class="p-4">Vehicle</th><th class="p-4">Customer</th><th class="p-4">IMEI</th><th class="p-4">Sold by</th><th class="p-4">Plan</th><th class="p-4">Expires</th><th class="p-4"></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($active as $a)
                    @php $paid = $a->payment_status === 'Paid' && $a->subscription_end_date?->isFuture(); @endphp
                    <tr class="hover:bg-slate-50">
                        <td class="p-4 font-semibold">{{ $a->vehicle_number }}</td>
                        <td class="p-4">{{ $a->customer_name }}</td>
                        <td class="p-4 font-mono text-xs">{{ $a->imei_number }}</td>
                        <td class="p-4">{{ $holderNames[$holderByImei[$a->imei_number] ?? 0] ?? '—' }}</td>
                        <td class="p-4">{{ $a->subscription_model ?: '—' }}</td>
                        <td class="p-4">
                            @if ($paid)
                                <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-bold text-emerald-800">{{ $a->subscription_end_date->format('Y-m-d') }}</span>
                            @else
                                <span class="rounded-full bg-red-100 px-2.5 py-1 text-[11px] font-bold text-red-700">Renewal due</span>
                            @endif
                        </td>
                        <td class="p-4 text-right"><a href="{{ route('distributor.devices.renew', $a->activated_device_id) }}" class="rounded-xl bg-[#0B1B3F] px-4 py-2 text-xs font-bold text-white hover:opacity-90">Renew</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="p-10 text-center text-slate-400">No active devices yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-slate-100">{{ $active->links() }}</div>
    </section>
</div>
@endsection