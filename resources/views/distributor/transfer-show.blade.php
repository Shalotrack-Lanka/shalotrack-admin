@extends('layouts.distributor')

@section('title', 'Transfer Details')

@section('content')
<div class="max-w-5xl mx-auto space-y-6">

    <a href="{{ route('distributor.transfers') }}" class="text-sm font-semibold text-blue-700 hover:underline">&larr; Back to transfer history</a>

    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6">
        <div class="flex flex-wrap items-center gap-3">
            <span class="rounded-full px-3 py-1 text-[11px] font-black uppercase {{ $sentByMe ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' }}">{{ $sentByMe ? 'Sent to a dealer' : 'Received from the company' }}</span>
            <h1 class="text-xl font-black text-slate-900">{{ $ledger->quantity }} &times; {{ $ledger->device_category }}</h1>
        </div>
        <dl class="mt-4 grid grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
            <div><dt class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Date &amp; time</dt><dd class="font-semibold text-slate-800">{{ $ledger->created_at->local()->format('Y-m-d h:i A') }}</dd></div>
            <div><dt class="text-[11px] font-bold uppercase tracking-wider text-slate-500">From</dt><dd class="font-semibold text-slate-800">{{ $sentByMe ? 'You' : 'Company' }}</dd></div>
            <div><dt class="text-[11px] font-bold uppercase tracking-wider text-slate-500">To</dt><dd class="font-semibold text-slate-800">{{ $sentByMe ? ($ledger->dealer->full_name ?? '-') : 'You' }}</dd></div>
            <div><dt class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Reference</dt><dd class="font-mono font-semibold text-slate-800">TR-{{ str_pad((string) $ledger->id, 6, '0', STR_PAD_LEFT) }}</dd></div>
        </dl>
        <p class="mt-4 text-xs text-slate-500">This record cannot be edited or removed. If something was sent by mistake, contact the company.</p>
    </div>

    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100"><h2 class="font-bold text-slate-900">Devices in this transfer ({{ $items->count() }})</h2></div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-600"><tr><th class="p-4">#</th><th class="p-4">IMEI</th><th class="p-4">SIM</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($items as $i => $item)
                        <tr class="hover:bg-slate-50"><td class="p-4 text-slate-400">{{ $i + 1 }}</td><td class="p-4 font-mono">{{ $item->imei_number }}</td><td class="p-4 font-mono text-slate-600">{{ $item->sim_number ?: '—' }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
