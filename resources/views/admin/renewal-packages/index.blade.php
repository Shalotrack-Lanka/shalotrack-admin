@extends('layouts.admin')

@section('title', 'Renewal packages')

@section('content')
<div class="space-y-5" x-data="{ editing: null }">
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <h1 class="text-2xl font-black text-blue-950">Renewal packages</h1>
        <p class="text-xs text-slate-500 mt-0.5">
            The pricing master from the Renewal Plans guideline (v1.0, 30 Sep 2026). Customer price = company + distributor + retailer margin.
            Warranty is counted from the device's original activation date, never from a renewal. Every change is audited; set the date the new values take effect.
            A package with no price is not offered to customers.
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

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead class="bg-slate-50 border-b border-slate-200 text-[11px] text-slate-600 uppercase tracking-wide">
                    <tr>
                        <th class="p-3">Package</th>
                        <th class="p-3 text-right">Customer price</th>
                        <th class="p-3 text-right">Company</th>
                        <th class="p-3 text-right">Distributor</th>
                        <th class="p-3 text-right">Retailer</th>
                        <th class="p-3 text-right">Per month</th>
                        <th class="p-3">Warranty</th>
                        <th class="p-3">Effective</th>
                        <th class="p-3">Offered</th>
                        <th class="p-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-sm text-slate-700">
                    @foreach($packages as $p)
                        <tr>
                            <td class="p-3">
                                <div class="font-semibold">{{ $p->label }}</div>
                                <div class="text-[11px] text-slate-500">{{ $p->positioning }}</div>
                            </td>
                            @if($p->customer_price !== null)
                                <td class="p-3 text-right font-semibold">Rs. {{ number_format($p->customer_price, 2) }}</td>
                                <td class="p-3 text-right">{{ number_format($p->company_margin, 2) }}</td>
                                <td class="p-3 text-right">{{ number_format($p->distributor_margin, 2) }}</td>
                                <td class="p-3 text-right">{{ number_format($p->retailer_margin, 2) }}</td>
                                <td class="p-3 text-right">{{ number_format($p->customer_price / $p->months, 2) }}</td>
                            @else
                                <td class="p-3 text-right text-amber-700 font-semibold" colspan="5">No approved price</td>
                            @endif
                            <td class="p-3">{{ $p->warranty_months > 0 ? $p->warranty_months . ' months' : 'None added' }}</td>
                            <td class="p-3">{{ $p->effective_from?->format('Y-m-d') ?: '—' }}</td>
                            <td class="p-3">
                                <span class="text-[11px] font-bold px-2 py-0.5 rounded-full {{ $p->isOffered() ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ $p->isOffered() ? 'Yes' : 'No' }}</span>
                            </td>
                            <td class="p-3 text-right">
                                <button type="button" @click="editing = editing === {{ $p->id }} ? null : {{ $p->id }}" class="text-xs font-bold text-blue-700 hover:underline">Edit</button>
                            </td>
                        </tr>
                        <tr x-show="editing === {{ $p->id }}" x-cloak class="bg-slate-50">
                            <td colspan="10" class="p-4">
                                <form method="POST" action="{{ route('admin.renewal-packages.update', $p) }}" class="flex flex-wrap items-end gap-3">
                                    @csrf @method('PATCH')
                                    @foreach(['customer_price' => 'Customer price', 'company_margin' => 'Company', 'distributor_margin' => 'Distributor', 'retailer_margin' => 'Retailer'] as $f => $l)
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-700 mb-1">{{ $l }} (Rs.)</label>
                                            <input type="number" step="0.01" min="0" name="{{ $f }}" value="{{ $p->$f !== null ? number_format((float) $p->$f, 2, '.', '') : '' }}" class="rounded-lg border-slate-300 text-sm w-32">
                                        </div>
                                    @endforeach
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-700 mb-1">Warranty (months)</label>
                                        <input type="number" min="0" max="120" name="warranty_months" required value="{{ $p->warranty_months }}" class="rounded-lg border-slate-300 text-sm w-28">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-700 mb-1">Effective from</label>
                                        <input type="date" name="effective_from" required value="{{ $p->effective_from?->format('Y-m-d') ?: now()->local()->toDateString() }}" class="rounded-lg border-slate-300 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-700 mb-1">Active</label>
                                        <select name="is_active" class="rounded-lg border-slate-300 text-sm">
                                            <option value="1" {{ $p->is_active ? 'selected' : '' }}>Active</option>
                                            <option value="0" {{ ! $p->is_active ? 'selected' : '' }}>Off</option>
                                        </select>
                                    </div>
                                    <button type="submit" class="bg-blue-950 text-white text-xs font-bold px-4 py-2.5 rounded-lg">Save</button>
                                </form>
                                <p class="text-[11px] text-slate-500 mt-2">Leave all four amounts empty to mark the package as unpriced.</p>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection