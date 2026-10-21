@extends('layouts.distributor')

@section('title', 'My Stock')

@section('content')
<div class="max-w-7xl mx-auto space-y-6">

    @include('distributor.partials.alerts')

    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
        <div>
            <h1 class="text-2xl font-black text-slate-900">My stock</h1>
            <p class="text-sm text-slate-500">Every device held by {{ $distributor->full_name }}. Only activated, unsold devices can be passed on.</p>
        </div>
        <a href="{{ route('distributor.transfer') }}" class="inline-flex items-center justify-center rounded-2xl bg-blue-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-blue-700 shadow">Transfer devices</a>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Devices I hold</p>
            <p class="mt-1 text-2xl font-black text-blue-950">{{ number_format($totalHeld) }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Ready to pass on</p>
            <p class="mt-1 text-2xl font-black text-emerald-700">{{ number_format($totalAvailable) }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 col-span-2 lg:col-span-1">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Held but not transferable</p>
            <p class="mt-1 text-2xl font-black text-slate-700">{{ number_format($totalHeld - $totalAvailable) }}</p>
            <p class="text-[11px] text-slate-500">Not activated yet, sold to a customer or already bound</p>
        </div>
    </div>

    <form method="GET" action="{{ route('distributor.stock') }}" class="bg-white rounded-3xl border border-slate-200 shadow-sm p-4 grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
        <div class="md:col-span-2">
            <label for="q" class="block text-xs font-bold text-slate-600 mb-1">Search IMEI or SIM</label>
            <input id="q" type="search" name="q" value="{{ $filters['q'] }}" placeholder="e.g. 8681599…" class="w-full rounded-xl border-slate-300 text-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
        </div>
        <div>
            <label for="category" class="block text-xs font-bold text-slate-600 mb-1">Device type</label>
            <select id="category" name="category" class="w-full rounded-xl border-slate-300 text-sm">
                <option value="">All types</option>
                @foreach ($categories as $cat => $n)
                    <option value="{{ $cat }}" @selected($filters['category'] === $cat)>{{ $cat }} ({{ $n }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="availability" class="block text-xs font-bold text-slate-600 mb-1">Show</label>
            <select id="availability" name="availability" class="w-full rounded-xl border-slate-300 text-sm">
                <option value="all" @selected($filters['availability'] === 'all')>Everything</option>
                <option value="available" @selected($filters['availability'] === 'available')>Ready to pass on</option>
                <option value="other" @selected($filters['availability'] === 'other')>Not transferable</option>
            </select>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="flex-1 rounded-xl bg-[#0B1B3F] px-4 py-2 text-sm font-bold text-white hover:opacity-90">Filter</button>
            <a href="{{ route('distributor.stock') }}" class="rounded-xl bg-slate-100 px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-200">Reset</a>
        </div>
    </form>

    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-600">
                    <tr><th class="p-4">IMEI</th><th class="p-4">SIM</th><th class="p-4">Device type</th><th class="p-4">Status</th><th class="p-4">Transferable</th><th class="p-4">Received</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($devices as $d)
                        <tr class="hover:bg-slate-50">
                            <td class="p-4 font-mono">{{ $d->imei_number }}</td>
                            <td class="p-4 font-mono text-slate-600">{{ $d->sim_number ?: '—' }}</td>
                            <td class="p-4">{{ $d->device_category ?: '—' }}</td>
                            <td class="p-4"><span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold text-slate-700">{{ $d->status ?: '—' }}</span></td>
                            <td class="p-4">
                                @if ($transferable->has($d->shdevice_id))
                                    <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-bold text-emerald-800">Yes</span>
                                @else
                                    <span class="rounded-full bg-slate-200 px-2.5 py-1 text-[11px] font-bold text-slate-600">No</span>
                                @endif
                            </td>
                            <td class="p-4 text-xs text-slate-500">{{ $d->allocated_at ? $d->allocated_at->local()->format('Y-m-d h:i A') : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-10 text-center text-slate-400">No devices match. @if ($totalHeld === 0) Devices appear here once the company transfers them to you. @endif</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-slate-100">{{ $devices->links() }}</div>
    </div>
</div>
@endsection
