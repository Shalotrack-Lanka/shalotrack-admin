@extends('layouts.distributor')

@section('title', 'My Dealers')

@section('content')
@php $region = fn ($r) => ucfirst(str_replace('_', ' ', (string) $r)); @endphp
<div class="max-w-7xl mx-auto space-y-6" x-data="{ q: '', onlyEmpty: false }">

    @include('distributor.partials.alerts')

    <div>
        <h1 class="text-2xl font-black text-slate-900">My dealers</h1>
        <p class="text-sm text-slate-500">Dealers that report to {{ $distributor->full_name }}. Only the company can add a dealer or move one in or out of your network.</p>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach ([
            ['Dealers', $dealers->count()],
            ['Devices in their stock', $dealers->sum('in_stock')],
            ['Devices sold', $dealers->sum('sold')],
            ['Customers', $dealers->sum('customers')],
        ] as [$l, $v])
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">{{ $l }}</p>
                <p class="mt-1 text-2xl font-black text-blue-950">{{ number_format($v) }}</p>
            </div>
        @endforeach
    </div>

    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="p-4 border-b border-slate-100 flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between">
            <input type="search" x-model="q" placeholder="Search by dealer name, region or email…"
                   class="w-full sm:max-w-sm rounded-xl border-slate-300 text-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
            <label class="inline-flex items-center gap-2 text-sm text-slate-600 cursor-pointer">
                <input type="checkbox" x-model="onlyEmpty" class="rounded border-slate-300 text-blue-600">
                Only dealers with no stock left
            </label>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-600">
                    <tr>
                        <th class="p-4">Dealer</th><th class="p-4">Region</th><th class="p-4 text-center">In stock</th>
                        <th class="p-4 text-center">Sold</th><th class="p-4 text-center">Customers</th>
                        <th class="p-4">Last transfer to them</th><th class="p-4">Status</th><th class="p-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($dealers as $d)
                        <tr data-search="{{ strtolower($d->full_name . ' ' . $region($d->region) . ' ' . $d->contact_email) }}"
                            data-empty="{{ $d->in_stock === 0 ? '1' : '0' }}"
                            x-show="$el.dataset.search.includes(q.toLowerCase()) && (!onlyEmpty || $el.dataset.empty === '1')"
                            class="hover:bg-slate-50">
                            <td class="p-4">
                                <p class="font-semibold text-slate-900">{{ $d->full_name }}</p>
                                <p class="text-xs text-slate-500 break-all">{{ $d->contact_email ?: '—' }}</p>
                            </td>
                            <td class="p-4">{{ $region($d->region) ?: '—' }}</td>
                            <td class="p-4 text-center">
                                <span class="inline-block min-w-[2rem] rounded-full px-2.5 py-0.5 text-xs font-bold {{ $d->in_stock === 0 ? 'bg-red-100 text-red-700' : 'bg-blue-50 text-blue-700' }}">{{ $d->in_stock }}</span>
                            </td>
                            <td class="p-4 text-center">{{ $d->sold }}</td>
                            <td class="p-4 text-center">{{ $d->customers }}</td>
                            <td class="p-4 text-xs text-slate-500">{{ $d->last_transfer_at ? $d->last_transfer_at->local()->format('Y-m-d') : 'Never' }}</td>
                            <td class="p-4">
                                <span class="rounded-full px-2.5 py-1 text-[11px] font-bold {{ $d->status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' }}">{{ ucfirst($d->status) }}</span>
                            </td>
                            <td class="p-4 text-right">
                                @if ($d->status === 'active')
                                    <a href="{{ route('distributor.transfer', ['dealer' => $d->id]) }}" class="inline-block rounded-xl bg-blue-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-blue-700">Send devices</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="p-10 text-center text-slate-400">No dealers report to you yet. Ask the company to attach your dealers to your account.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
