@extends('layouts.admin')

@section('title', 'Installs and warranty')

@section('content')
@php
    use App\Services\InstallsWarrantyReport;
@endphp

<div class="space-y-5">
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm space-y-3">
        <div>
            <h1 class="text-2xl font-black text-blue-950">Installs and warranty</h1>
            <p class="text-xs text-slate-500 mt-0.5">
                Warranty is the subscription period: it runs while the device is paid, and ends when the subscription does.
                To add or correct an install, use Edit on
                <a href="{{ route('admin.customer-device-management') }}" class="text-blue-700 underline">Customer Device Management</a>.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @foreach($filters as $key => $label)
                <a href="{{ route('admin.installs-warranty', ['filter' => $key, 'days' => $days]) }}"
                   class="px-4 py-2 rounded-xl text-xs font-bold border {{ $filter === $key ? 'bg-blue-950 text-white border-blue-950' : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50' }}">
                    {{ $label }} ({{ $counts[$key] }})
                </a>
            @endforeach

            @if($filter === InstallsWarrantyReport::FILTER_ENDING)
                <form method="GET" action="{{ route('admin.installs-warranty') }}" class="flex items-center gap-2">
                    <input type="hidden" name="filter" value="{{ $filter }}">
                    <select name="days" onchange="this.form.submit()"
                            class="text-xs font-bold border border-slate-300 rounded-xl px-3 py-2 bg-slate-50">
                        @foreach($windows as $w)
                            <option value="{{ $w }}" {{ $days === $w ? 'selected' : '' }}>Next {{ $w }} days</option>
                        @endforeach
                    </select>
                </form>
            @endif

            <a href="{{ route('admin.installs-warranty', ['filter' => $filter, 'days' => $days, 'export' => 'csv']) }}"
               class="px-4 py-2 rounded-xl text-xs font-bold border border-slate-300 bg-white text-slate-700 hover:bg-slate-50">
                Export CSV
            </a>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead class="bg-slate-50 border-b border-slate-200 text-[11px] text-slate-600 uppercase tracking-wide">
                    <tr>
                        <th class="p-3">Customer</th>
                        <th class="p-3">Vehicle</th>
                        <th class="p-3">IMEI</th>
                        <th class="p-3">Dealer</th>
                        <th class="p-3">Installed on</th>
                        <th class="p-3">Installed by</th>
                        <th class="p-3">Warranty</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-sm text-slate-700">
                    @forelse($rows as $row)
                        @php
                            $w = InstallsWarrantyReport::warranty($row);
                            $tone = match (true) {
                                $w['state'] === 'ended' => 'bg-red-100 text-red-700',
                                $w['state'] === 'none' => 'bg-slate-100 text-slate-600',
                                ($w['days'] ?? 99) <= 7 => 'bg-amber-100 text-amber-700',
                                default => 'bg-emerald-100 text-emerald-700',
                            };
                        @endphp
                        <tr>
                            <td class="p-3 font-semibold">{{ $row->customer_name ?: '—' }}</td>
                            <td class="p-3">{{ $row->vehicle_number ?: '—' }}</td>
                            <td class="p-3 font-mono text-xs">{{ $row->imei_number }}</td>
                            <td class="p-3">{{ $row->dealer_name ?: 'Company' }}</td>
                            <td class="p-3">
                                @if($row->installed_on)
                                    {{ $row->installed_on->format('Y-m-d') }}
                                @else
                                    <span class="text-amber-700 font-semibold text-xs">Not recorded</span>
                                @endif
                            </td>
                            <td class="p-3">
                                {{ $row->installed_by ?: '—' }}
                                @if($row->install_notes)
                                    <div class="text-[11px] text-slate-500 max-w-xs truncate" title="{{ $row->install_notes }}">{{ $row->install_notes }}</div>
                                @endif
                            </td>
                            <td class="p-3">
                                <span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-bold {{ $tone }}">{{ $w['label'] }}</span>
                                @if($w['date'])
                                    <div class="text-[11px] text-slate-500 mt-0.5">
                                        {{ $w['state'] === 'active' ? 'Ends' : 'Ended' }} {{ $w['date']->copy()->local()->format('Y-m-d') }}
                                        @if($w['state'] === 'active' && $w['days'] !== null) · {{ $w['days'] }} days left @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="p-6 text-center text-sm text-slate-500">Nothing here.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $rows->links() }}</div>
    </div>
</div>
@endsection