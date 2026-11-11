@extends('layouts.admin')

@section('title', 'Gateway Devices')

@section('content')
@php
    $mode = $report['trial_mode'] ?? 'off';
    $labels = [
        'trial_expired'     => ['Trial ended, bind to enable', 'bg-red-50 text-red-700 border-red-200'],
        'stopped'           => ['Stopped', 'bg-slate-100 text-slate-600 border-slate-200'],
        'trial'             => ['In free trial', 'bg-amber-50 text-amber-700 border-amber-200'],
        'trial_not_started' => ['Registered, not connected yet', 'bg-blue-50 text-blue-700 border-blue-200'],
        'allowed'           => ['Active', 'bg-emerald-50 text-emerald-700 border-emerald-200'],
    ];
    $tabs = ['attention' => 'Needs attention', 'connected' => 'Connected now', 'all' => 'All listed'];
@endphp
<div class="max-w-7xl mx-auto space-y-5">

    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <h1 class="text-2xl font-black text-blue-950">Gateway Devices</h1>
            <p class="text-xs text-slate-500 mt-0.5">Who is connected, who is in the free trial, who is waiting for you to bind them.</p>
        </div>
        @if($ok)
            <div class="flex items-center gap-2 flex-wrap text-xs font-bold">
                <span class="px-3 py-1.5 bg-blue-50 border border-blue-200 text-blue-700 rounded-full">{{ $report['connected_now'] ?? 0 }} connected</span>
                <span class="px-3 py-1.5 bg-slate-50 border border-slate-200 text-slate-600 rounded-full">Trial rule: {{ $mode }}</span>
                <span class="px-3 py-1.5 bg-slate-50 border border-slate-200 text-slate-600 rounded-full">Re-check: {{ $report['recheck_mode'] ?? 'off' }}</span>
            </div>
        @endif
    </div>

    @unless($ok)
        <div class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-2xl text-xs font-semibold">
            @if($status === 404)
                The gateway is running an older version without this report. Update the gateway, then reload this page.
            @elseif($status === 401)
                The gateway rejected this portal's key. Check GATEWAY_COMMAND_API_KEY.
            @else
                The gateway could not be reached or could not build the report. Try again in a minute.
            @endif
        </div>
    @else
        @if($mode !== 'enforce')
            <div class="p-4 bg-amber-50 border border-amber-200 text-amber-800 rounded-2xl text-xs font-semibold">
                The free-trial rule is in <b>{{ $mode }}</b> mode, so nothing is cut off yet. "Trial ended" shows what <b>would</b>
                be refused once the rule is switched on.
            </div>
        @endif

        {{-- Counts --}}
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
            @foreach(['allowed' => 'Active', 'trial' => 'In trial', 'trial_not_started' => 'Never connected', 'trial_expired' => 'Trial ended', 'stopped' => 'Stopped'] as $key => $title)
                <div class="bg-white p-4 rounded-2xl shadow-sm border border-slate-100">
                    <p class="text-[11px] font-bold text-slate-400 uppercase">{{ $title }}</p>
                    <p class="text-2xl font-black {{ $key === 'trial_expired' && ($report['counts'][$key] ?? 0) > 0 ? 'text-red-600' : 'text-blue-950' }}">{{ $report['counts'][$key] ?? 0 }}</p>
                </div>
            @endforeach
        </div>

        @if(!empty($report['connected_not_registered']))
            <div class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-2xl text-xs font-semibold">
                Connected but not registered in the ERP: {{ implode(', ', $report['connected_not_registered']) }}
            </div>
        @endif

        {{-- Filter tabs --}}
        <div class="flex gap-2 flex-wrap">
            @foreach($tabs as $key => $title)
                <a href="{{ route('admin.vehicles.gateway-devices', ['filter' => $key]) }}"
                   class="px-3 py-1.5 rounded-full text-xs font-bold border {{ $filter === $key ? 'bg-blue-900 text-white border-blue-900' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' }}">{{ $title }}</a>
            @endforeach
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-[11px] uppercase text-slate-500">
                    <tr>
                        <th class="text-left px-4 py-3">IMEI</th>
                        <th class="text-left px-4 py-3">Vehicle / customer</th>
                        <th class="text-left px-4 py-3">ERP status</th>
                        <th class="text-left px-4 py-3">Access</th>
                        <th class="text-left px-4 py-3">Trial left</th>
                        <th class="text-left px-4 py-3">First connected</th>
                        <th class="text-left px-4 py-3">Now</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($rows as $d)
                        @php [$label, $cls] = $labels[$d['category']] ?? [$d['category'], 'bg-slate-100 text-slate-600 border-slate-200']; @endphp
                        <tr>
                            <td class="px-4 py-3 font-mono text-xs">{{ $d['imei'] }}</td>
                            <td class="px-4 py-3">
                                @if($d['vehicle_number'] || $d['customer_name'])
                                    <div class="font-bold text-blue-950">{{ $d['vehicle_number'] ?? '-' }}</div>
                                    <div class="text-[11px] text-slate-500">{{ $d['customer_name'] ?? '' }}</div>
                                @else
                                    <span class="text-slate-400 text-xs">Not bound</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs">{{ $d['status'] }}</td>
                            <td class="px-4 py-3"><span class="inline-block px-2.5 py-1 rounded-full border text-[10px] font-bold {{ $cls }}">{{ $label }}</span></td>
                            <td class="px-4 py-3 text-xs">
                                @if($d['category'] === 'trial' || $d['category'] === 'trial_not_started')
                                    {{ $d['trial_hours_left'] }} h
                                @else
                                    -
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs">{{ $d['first_seen_at'] ? \Carbon\Carbon::parse($d['first_seen_at'])->local()->format('d M Y H:i') : '-' }}</td>
                            <td class="px-4 py-3 text-xs">
                                @if($d['connected'])
                                    <span class="text-emerald-600 font-bold">Connected</span>
                                @else
                                    <span class="text-slate-400">Offline</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-xs text-slate-400">Nothing to show for this filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(!empty($report['truncated']))
            <p class="text-[11px] text-slate-400">Showing the first {{ count($report['devices']) }} devices that need attention; the counts above cover all {{ $report['total_devices'] }}.</p>
        @endif
        <p class="text-[11px] text-slate-400">Trial window: {{ $report['trial_hours'] ?? 24 }} hours from the first time a device connects. Binding a device to a customer's vehicle in the ERP ends its trial.</p>
    @endunless
</div>
@endsection
