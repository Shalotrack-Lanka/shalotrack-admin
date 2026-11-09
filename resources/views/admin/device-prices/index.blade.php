@extends('layouts.admin')

@section('title', 'Device prices')

@section('content')
@php
    use App\Support\Money;
    use App\Services\DevicePriceChain;
@endphp
<div class="space-y-5" x-data="{ editing: null }">
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <h1 class="text-2xl font-black text-blue-950">Device prices</h1>
        <p class="text-xs text-slate-500 mt-0.5">
            One price chain per device type: landing cost + company margin = distributor cost; + distributor margin = retailer price; + retailer margin = customer price.
            You enter the landing cost and the three margins; every price and percentage is calculated. Amounts are in Rs. and exclude tax.
            @if($taxRate)
                {{ $taxLabel }} at {{ $taxRate }}% (from billing settings) is shown on each sale.
            @else
                Tax is switched off in billing settings, so no tax is shown.
            @endif
            Margin % is shown two ways: on cost (markup) and on selling price (share). Every change is audited. A device type with no price is not priced anywhere.
        </p>
    </div>

    @if(session('success'))
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded-xl p-4">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-800 text-sm rounded-xl p-4">
            @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
        </div>
    @endif

    @forelse($rows as $row)
        @php
            $type = $row['type'];
            $price = $row['price'];
            $chain = $row['chain'];
            $name = trim($type->device_category . ' ' . $type->model);
        @endphp
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-2 p-4 border-b border-slate-100">
                <div>
                    <div class="font-black text-blue-950">{{ $name }}</div>
                    @if($price)
                        <div class="text-[11px] text-slate-500">Effective {{ $price->effective_from?->format('Y-m-d') }}</div>
                    @endif
                </div>
                @if($chain)
                    <div class="text-right">
                        <div class="text-[11px] text-slate-500 uppercase tracking-wide">Customer price</div>
                        <div class="text-xl font-black text-blue-950">Rs. {{ Money::format($chain['customer_total']) }}</div>
                    </div>
                @else
                    <span class="text-sm font-semibold text-amber-700">No approved price</span>
                @endif
                <button type="button" @click="editing = editing === {{ $type->id }} ? null : {{ $type->id }}" class="text-xs font-bold text-blue-700 hover:underline">{{ $price ? 'Edit' : 'Set price' }}</button>
            </div>

            @if($chain)
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead class="bg-slate-50 text-[11px] text-slate-600 uppercase tracking-wide">
                            <tr>
                                <th class="p-3">Sale</th>
                                <th class="p-3 text-right">Seller's cost</th>
                                <th class="p-3 text-right">Margin</th>
                                <th class="p-3 text-right">On cost</th>
                                <th class="p-3 text-right">On price</th>
                                <th class="p-3 text-right">Price</th>
                                @if($chain['tax_rate'])
                                    <th class="p-3 text-right">{{ $taxLabel }}</th>
                                    <th class="p-3 text-right">Price incl. tax</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-sm text-slate-700">
                            @foreach($chain['steps'] as $step)
                                <tr>
                                    <td class="p-3 font-semibold">{{ $step['label'] }}</td>
                                    <td class="p-3 text-right">{{ Money::format($step['cost']) }}</td>
                                    <td class="p-3 text-right">{{ Money::format($step['margin']) }}</td>
                                    <td class="p-3 text-right">{{ DevicePriceChain::percent($step['markup_bp']) }}</td>
                                    <td class="p-3 text-right">{{ DevicePriceChain::percent($step['share_bp']) }}</td>
                                    <td class="p-3 text-right font-semibold">{{ Money::format($step['price']) }}</td>
                                    @if($chain['tax_rate'])
                                        <td class="p-3 text-right">{{ Money::format($step['tax']) }}</td>
                                        <td class="p-3 text-right font-semibold">{{ Money::format($step['price_incl']) }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="px-4 py-2 text-[11px] text-slate-500 border-t border-slate-100">
                    Total margin across the chain: Rs. {{ Money::format($chain['total_margin']) }}.
                </div>
            @endif

            <div x-show="editing === {{ $type->id }}" x-cloak class="bg-slate-50 p-4 border-t border-slate-100">
                <form method="POST" action="{{ route('admin.device-prices.update', $type) }}" class="flex flex-wrap items-end gap-3">
                    @csrf @method('PATCH')
                    @foreach(['landing_cost' => 'Landing cost', 'company_margin' => 'Company margin', 'distributor_margin' => 'Distributor margin', 'retailer_margin' => 'Retailer margin'] as $field => $label)
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">{{ $label }} (Rs.)</label>
                            <input type="number" step="0.01" min="0" name="{{ $field }}" required value="{{ $price ? number_format((float) $price->$field, 2, '.', '') : '' }}" class="rounded-lg border-slate-300 text-sm w-36">
                        </div>
                    @endforeach
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Effective from</label>
                        <input type="date" name="effective_from" required value="{{ $price?->effective_from?->format('Y-m-d') ?: now()->toDateString() }}" class="rounded-lg border-slate-300 text-sm">
                    </div>
                    <button type="submit" class="bg-blue-950 text-white text-xs font-bold px-4 py-2.5 rounded-lg">Save</button>
                </form>
                <p class="text-[11px] text-slate-500 mt-2">Margins are editable at any time and are saved with an effective date; the prices above are recalculated from them.</p>
            </div>
        </div>
    @empty
        <div class="bg-white rounded-2xl border border-slate-200 p-6 text-sm text-slate-500">No device types yet. Add one under Add Device Types first.</div>
    @endforelse
</div>
@endsection
