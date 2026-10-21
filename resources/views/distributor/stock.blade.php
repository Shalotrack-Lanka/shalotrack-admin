@extends('layouts.distributor')

@section('title', 'My stock')

@section('content')
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-gray-100">
        <h1 class="text-lg font-bold text-slate-900">My stock</h1>
        <p class="text-xs text-slate-500">Every device currently held by {{ $distributor->full_name }}.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-slate-600">
                <tr>
                    <th class="p-3">IMEI</th>
                    <th class="p-3">SIM</th>
                    <th class="p-3">Category</th>
                    <th class="p-3">Status</th>
                    <th class="p-3">Received</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($devices as $d)
                    <tr>
                        <td class="p-3 font-mono">{{ $d->imei_number }}</td>
                        <td class="p-3">{{ $d->sim_number ?: '—' }}</td>
                        <td class="p-3">{{ $d->device_category ?: '—' }}</td>
                        <td class="p-3">{{ $d->status }}</td>
                        <td class="p-3 text-xs text-slate-500">{{ $d->allocated_at ? $d->allocated_at->local()->format('Y-m-d H:i') : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-6 text-center text-slate-500">No devices in your stock yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-4">{{ $devices->links() }}</div>
</div>
@endsection