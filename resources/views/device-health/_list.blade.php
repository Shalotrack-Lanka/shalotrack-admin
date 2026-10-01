{{-- Shared by the staff and dealer "Device health" pages. --}}
@php
    use App\Services\DeviceHealthReport;

    $link = fn (array $q) => route($routeName, array_filter(array_merge(['level' => $level, 'type' => $type], $q), fn ($v) => $v !== 'all'));
@endphp

<div class="space-y-5">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <div>
            <h1 class="text-2xl font-black text-blue-950">Device health</h1>
            <p class="text-xs text-slate-500 mt-0.5">
                Paid devices that need attention: silent for {{ DeviceHealthReport::WARN_HOURS }}h+ (critical at {{ DeviceHealthReport::CRITICAL_HOURS }}h), never connected, unplugged, or not linked in the app.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ $link(['level' => 'all']) }}"
               class="px-4 py-2 rounded-xl text-xs font-bold border {{ $level === 'all' ? 'bg-blue-950 text-white border-blue-950' : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50' }}">
                All ({{ $counts['total'] }})
            </a>
            <a href="{{ $link(['level' => 'critical']) }}"
               class="px-4 py-2 rounded-xl text-xs font-bold border {{ $level === 'critical' ? 'bg-red-700 text-white border-red-700' : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50' }}">
                Critical ({{ $counts['critical'] }})
            </a>
            <a href="{{ $link(['level' => 'warning']) }}"
               class="px-4 py-2 rounded-xl text-xs font-bold border {{ $level === 'warning' ? 'bg-amber-600 text-white border-amber-600' : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50' }}">
                Warning ({{ $counts['warning'] }})
            </a>
            @if($available)
                <a href="{{ $link(['export' => 'csv']) }}"
                   class="px-4 py-2 rounded-xl text-xs font-bold border border-slate-300 bg-white text-slate-700 hover:bg-slate-50">
                    Export CSV
                </a>
            @endif
        </div>
    </div>

    @unless($available)
        <div class="bg-red-50 border border-red-200 text-red-800 rounded-2xl p-5">
            <div class="font-black">Device health is unavailable right now.</div>
            <p class="text-sm mt-1">
                We could not reach the tracking service, so this is <b>not</b> an all-clear. Try again in a minute; if it keeps happening, tell the dev team.
            </p>
        </div>
    @else
        <div class="flex flex-wrap gap-2">
            <a href="{{ $link(['type' => 'all']) }}"
               class="px-3 py-1.5 rounded-lg text-[11px] font-bold border {{ $type === 'all' ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-300 hover:bg-slate-50' }}">
                Any problem
            </a>
            @foreach(DeviceHealthReport::TYPE_LABELS as $key => $label)
                <a href="{{ $link(['type' => $key]) }}"
                   class="px-3 py-1.5 rounded-lg text-[11px] font-bold border {{ $type === $key ? 'bg-slate-800 text-white border-slate-800' : 'bg-white text-slate-600 border-slate-300 hover:bg-slate-50' }}">
                    {{ $label }} ({{ $typeCounts[$key] }})
                </a>
            @endforeach
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-slate-50 border-b border-slate-200 text-[11px] text-slate-600 uppercase tracking-wide">
                        <tr>
                            <th class="p-3">Problem</th>
                            <th class="p-3">Customer</th>
                            <th class="p-3">Phone</th>
                            <th class="p-3">Vehicle</th>
                            <th class="p-3">IMEI</th>
                            @if($showDealer)<th class="p-3">Dealer</th>@endif
                            <th class="p-3">Last contact</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-sm text-slate-700">
                        @forelse($rows as $r)
                            <tr class="align-top">
                                <td class="p-3">
                                    <span class="inline-block px-2.5 py-1 rounded-lg text-[11px] font-bold {{ $r['level'] === 'critical' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800' }}">
                                        {{ DeviceHealthReport::TYPE_LABELS[$r['type']] }}
                                    </span>
                                    <div class="text-[11px] text-slate-500 mt-1 max-w-xs">{{ $r['detail'] }}</div>
                                </td>
                                <td class="p-3 font-semibold">{{ $r['customer_name'] ?: '—' }}</td>
                                <td class="p-3">
                                    @if($r['customer_phone'])
                                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $r['customer_phone']) }}" class="text-blue-700 hover:underline">{{ $r['customer_phone'] }}</a>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="p-3">{{ $r['vehicle_number'] ?: '—' }}</td>
                                <td class="p-3 font-mono text-xs">{{ $r['imei'] }}</td>
                                @if($showDealer)<td class="p-3">{{ $r['dealer_name'] ?: 'Company' }}</td>@endif
                                <td class="p-3">
                                    @if($r['last_contact'])
                                        {{ $r['last_contact']->copy()->local()->format('Y-m-d H:i') }}
                                        <div class="text-[11px] text-slate-400">{{ DeviceHealthReport::duration($r['hours']) }} ago</div>
                                    @else
                                        <span class="text-slate-400">Never</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="p-8 text-center text-slate-400 text-sm">
                                    No problems found. Every paid device is reporting normally.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($rows->hasPages())
                <div class="p-4 border-t border-slate-100">{{ $rows->links() }}</div>
            @endif
        </div>

        <p class="text-[11px] text-slate-400 leading-relaxed">
            Times are Sri Lanka time. “Last contact” is the tracker's last heartbeat or status report. A parked vehicle in a basement can look silent; call the customer before sending anyone out.
            The list refreshes every couple of minutes.
        </p>
    @endunless
</div>