@extends('layouts.distributor')

@section('title', 'My dealers')

@section('content')
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-gray-100">
        <h1 class="text-lg font-bold text-slate-900">My dealers</h1>
        <p class="text-xs text-slate-500">Dealers that report to {{ $distributor->full_name }}. Only the company can add or move a dealer.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-slate-600">
                <tr>
                    <th class="p-3">Dealer</th>
                    <th class="p-3">Region</th>
                    <th class="p-3">Email</th>
                    <th class="p-3 text-center">In stock</th>
                    <th class="p-3 text-center">Sold</th>
                    <th class="p-3 text-center">Customers</th>
                    <th class="p-3">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($dealers as $d)
                    <tr>
                        <td class="p-3 font-medium text-slate-900">{{ $d->full_name }}</td>
                        <td class="p-3">{{ ucfirst(str_replace('_', ' ', (string) $d->region)) }}</td>
                        <td class="p-3 break-all">{{ $d->contact_email ?: '—' }}</td>
                        <td class="p-3 text-center">{{ $stockByDealer[$d->id] ?? 0 }}</td>
                        <td class="p-3 text-center">{{ $soldByDealer[$d->id] ?? 0 }}</td>
                        <td class="p-3 text-center">{{ $leadsByDealer[$d->id] ?? 0 }}</td>
                        <td class="p-3">{{ ucfirst($d->status) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="p-6 text-center text-slate-500">No dealers report to you yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection