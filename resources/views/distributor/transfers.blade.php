@extends('layouts.distributor')

@section('title', 'Transfer History')

@section('content')
<div class="max-w-7xl mx-auto space-y-6">

    @include('distributor.partials.alerts')

    <div>
        <h1 class="text-2xl font-black text-slate-900">Transfer history</h1>
        <p class="text-sm text-slate-500">Everything you received from the company and everything you sent on to your dealers. Open a row to see the exact devices.</p>
    </div>

    <div class="grid grid-cols-2 gap-4 max-w-md">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Transfers (filtered)</p>
            <p class="mt-1 text-2xl font-black text-blue-950">{{ number_format((int) $totals->records) }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Devices (filtered)</p>
            <p class="mt-1 text-2xl font-black text-blue-950">{{ number_format((int) $totals->devices) }}</p>
        </div>
    </div>

    <form method="GET" action="{{ route('distributor.transfers') }}" class="bg-white rounded-3xl border border-slate-200 shadow-sm p-4 grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
        <div>
            <label for="direction" class="block text-xs font-bold text-slate-600 mb-1">Show</label>
            <select id="direction" name="direction" class="w-full rounded-xl border-slate-300 text-sm">
                <option value="all" @selected($filters['direction'] === 'all')>Sent and received</option>
                <option value="sent" @selected($filters['direction'] === 'sent')>Sent to dealers</option>
                <option value="received" @selected($filters['direction'] === 'received')>Received from company</option>
            </select>
        </div>
        <div>
            <label for="dealer_id" class="block text-xs font-bold text-slate-600 mb-1">Dealer</label>
            <select id="dealer_id" name="dealer_id" class="w-full rounded-xl border-slate-300 text-sm">
                <option value="">All dealers</option>
                @foreach ($dealers as $d)
                    <option value="{{ $d->id }}" @selected((string) $filters['dealer_id'] === (string) $d->id)>{{ $d->full_name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="from" class="block text-xs font-bold text-slate-600 mb-1">From date</label>
            <input id="from" type="date" name="from" value="{{ $filters['from'] }}" class="w-full rounded-xl border-slate-300 text-sm">
        </div>
        <div>
            <label for="to" class="block text-xs font-bold text-slate-600 mb-1">To date</label>
            <input id="to" type="date" name="to" value="{{ $filters['to'] }}" class="w-full rounded-xl border-slate-300 text-sm">
        </div>
        <div class="flex gap-2">
            <button type="submit" class="flex-1 rounded-xl bg-[#0B1B3F] px-4 py-2 text-sm font-bold text-white hover:opacity-90">Filter</button>
            <a href="{{ route('distributor.transfers') }}" class="rounded-xl bg-slate-100 px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-200">Reset</a>
        </div>
    </form>

    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-600">
                    <tr><th class="p-4">Date &amp; time</th><th class="p-4">Type</th><th class="p-4">From</th><th class="p-4">To</th><th class="p-4">Device type</th><th class="p-4 text-center">Devices</th><th class="p-4"></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($transfers as $t)
                        @php $sentByMe = (int) $t->from_dealer_id === (int) $distributor->id; @endphp
                        <tr class="hover:bg-slate-50">
                            <td class="p-4 text-xs text-slate-500">{{ $t->created_at->local()->format('Y-m-d h:i A') }}</td>
                            <td class="p-4"><span class="rounded-full px-2.5 py-1 text-[10px] font-black uppercase {{ $sentByMe ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' }}">{{ $sentByMe ? 'Sent' : 'Received' }}</span></td>
                            <td class="p-4">{{ $sentByMe ? 'You' : 'Company' }}</td>
                            <td class="p-4 font-semibold">{{ $sentByMe ? ($t->dealer->full_name ?? '-') : 'You' }}</td>
                            <td class="p-4">{{ $t->device_category }}</td>
                            <td class="p-4 text-center font-bold text-blue-700">{{ $t->quantity }}</td>
                            <td class="p-4 text-right"><a href="{{ route('distributor.transfers.show', $t) }}" class="text-xs font-bold text-blue-700 hover:underline">View devices</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="p-10 text-center text-slate-400">No transfers match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-slate-100">{{ $transfers->links() }}</div>
    </div>
</div>
@endsection
