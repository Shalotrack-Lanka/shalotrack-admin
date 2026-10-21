@extends('layouts.admin')
@section('title', 'New invoice')
@section('content')
<div class="space-y-5">
    @include('billing.admin._nav', ['heading' => 'New invoice', 'sub' => 'Pick who received the devices, tick the transfers to bill and set the price per device.'])

    @unless($ready)
        <div class="bg-amber-50 border border-amber-200 text-amber-900 text-sm rounded-2xl p-4">
            <b>The company details are not set up.</b> Fill them in under <a class="underline font-bold" href="{{ route('admin.billing.settings') }}">Billing settings</a> before issuing an invoice.
        </div>
    @endunless

    <form method="GET" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 flex flex-wrap items-end gap-3">
        <div class="min-w-[16rem]">
            <label class="text-[11px] font-black uppercase tracking-wide text-slate-500">Buyer (who received the devices)</label>
            <select name="dealer" onchange="this.form.submit()" class="mt-1 w-full text-sm border border-slate-300 rounded-xl px-3 py-2 bg-slate-50">
                <option value="">Choose…</option>
                @foreach($buyers as $b)
                    <option value="{{ $b->id }}" @selected($buyer && $buyer->id === $b->id)>{{ $b->full_name }} ({{ $b->dealer_status === 'distributor' ? 'Distributor' : 'Dealer' }})</option>
                @endforeach
            </select>
        </div>
        @if($buyers->isEmpty())<p class="text-sm text-slate-500">Every transfer is already invoiced. Nothing to bill.</p>@endif
    </form>

    @if($buyer)
    <form method="POST" action="{{ route('admin.billing.invoices.review') }}" x-data="invoiceForm()" class="space-y-4">
        @csrf
        <input type="hidden" name="dealer_id" value="{{ $buyer->id }}">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-slate-100"><h2 class="font-black text-blue-950">Transfers to {{ $buyer->full_name }} not yet invoiced</h2>
                <p class="text-xs text-slate-500">An invoice covers whole transfers. The price is per device (LKR). Terms: payment due {{ $terms }} days after issue.</p></div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-[11px] text-slate-600 uppercase tracking-wide"><tr>
                        <th class="p-3 w-10"></th><th class="p-3">Transfer</th><th class="p-3">Date</th><th class="p-3">From</th><th class="p-3">Device type</th><th class="p-3 text-right">Devices</th><th class="p-3 text-right">Price per device (LKR)</th><th class="p-3 text-right">Line total</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                    @forelse($transfers as $t)
                        <tr>
                            <td class="p-3"><input type="checkbox" name="include[]" value="{{ $t->id }}" x-model="picked" class="rounded"></td>
                            <td class="p-3 font-bold">TR-{{ str_pad((string) $t->id, 6, '0', STR_PAD_LEFT) }}</td>
                            <td class="p-3 text-slate-500">{{ $t->created_at?->local()->format('Y-m-d') }}</td>
                            <td class="p-3">{{ $t->senderName() }}</td>
                            <td class="p-3">{{ $t->device_category }}</td>
                            <td class="p-3 text-right">{{ $t->quantity }}</td>
                            <td class="p-3 text-right"><input type="text" inputmode="decimal" name="prices[{{ $t->id }}]" x-model="price[{{ $t->id }}]" value="{{ old('prices.' . $t->id) }}" placeholder="0.00" class="w-32 text-right text-sm border border-slate-300 rounded-lg px-2 py-1"></td>
                            <td class="p-3 text-right font-bold" x-text="line({{ $t->id }}, {{ $t->quantity }})">0.00</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="p-8 text-center text-slate-400">This buyer has no transfers waiting to be invoiced.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if($transfers->isNotEmpty())
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 grid md:grid-cols-2 gap-4">
            <div><label class="text-[11px] font-black uppercase tracking-wide text-slate-500">Note on the invoice (optional)</label>
                <textarea name="notes" rows="2" maxlength="500" class="mt-1 w-full text-sm border border-slate-300 rounded-xl px-3 py-2">{{ old('notes') }}</textarea></div>
            <div><label class="text-[11px] font-black uppercase tracking-wide text-slate-500">Reason (only for a zero-value invoice)</label>
                <input name="zero_reason" maxlength="255" value="{{ old('zero_reason') }}" placeholder="e.g. replacement units" class="mt-1 w-full text-sm border border-slate-300 rounded-xl px-3 py-2"></div>
        </div>
        <div class="flex items-center justify-between bg-blue-950 text-white rounded-2xl p-4">
            <div><p class="text-[11px] uppercase font-bold text-blue-200">Subtotal of ticked transfers</p>
                <p class="text-2xl font-black">LKR <span x-text="total()">0.00</span></p>
                @if($tax)<p class="text-[11px] text-blue-200">{{ $tax['label'] }} {{ $tax['rate'] }}% is added on the next screen.</p>@endif</div>
            <button class="px-5 py-2.5 rounded-xl bg-white text-blue-950 font-black text-sm disabled:opacity-40" :disabled="picked.length === 0">Review invoice &rarr;</button>
        </div>
        @endif
    </form>
    <script>
        function invoiceForm() {
            const qty = @js($transfers->pluck('quantity', 'id'));
            const cents = v => { v = String(v || '').replace(/[, ]/g, ''); return /^\d+(\.\d{1,2})?$/.test(v) ? Math.round(parseFloat(v) * 100) : 0; };
            const fmt = c => (c / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            return {
                picked: [], price: {},
                line(id, q) { return fmt(cents(this.price[id]) * q); },
                total() { return fmt(this.picked.reduce((s, id) => s + cents(this.price[id]) * (qty[id] || 0), 0)); },
            };
        }
    </script>
    @endif
</div>
@endsection
