{{-- Shared by the staff and dealer "Dealer scorecard" pages. --}}
@php
    use App\Services\DealerScorecard;

    // Sortable header link (staff view only). Clicking the active column flips the direction.
    $th = function (string $key, string $label) use ($routeName, $days, $sort, $dir, $ownView) {
        if ($ownView) {
            return e($label);
        }
        $next = ($sort === $key && $dir === 'desc') ? 'asc' : 'desc';
        $arrow = $sort === $key ? ($dir === 'desc' ? ' ↓' : ' ↑') : '';
        $url = route($routeName, ['days' => $days, 'sort' => $key, 'dir' => $next]);

        return '<a href="' . e($url) . '" class="hover:text-blue-700">' . e($label) . $arrow . '</a>';
    };

    $pct = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.') . '%';
    $num = fn ($v) => $v === null ? '—' : number_format($v);
@endphp

<div class="space-y-5">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <div>
            <h1 class="text-2xl font-black text-blue-950">{{ $ownView ? 'My scorecard' : 'Dealer scorecard' }}</h1>
            <p class="text-xs text-slate-500 mt-0.5">
                @if($ownView)
                    Your sales, stock and customer health. Period figures cover the last {{ $days }} days.
                @else
                    Dealers side by side. Period figures cover the last {{ $days }} days; stock and subscription figures are as of now.
                @endif
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <form method="GET" action="{{ route($routeName) }}" class="flex items-center gap-2">
                @unless($ownView)
                    <input type="hidden" name="sort" value="{{ $sort }}">
                    <input type="hidden" name="dir" value="{{ $dir }}">
                @endunless
                <select name="days" onchange="this.form.submit()"
                        class="text-xs font-bold border border-slate-300 rounded-xl px-3 py-2 bg-slate-50">
                    @foreach($windows as $w)
                        <option value="{{ $w }}" {{ $days === $w ? 'selected' : '' }}>Last {{ $w }} days</option>
                    @endforeach
                </select>
            </form>

            @unless($ownView)
                <a href="{{ route($routeName, ['days' => $days, 'sort' => $sort, 'dir' => $dir, 'export' => 'csv']) }}"
                   class="px-4 py-2 rounded-xl text-xs font-bold border border-slate-300 bg-white text-slate-700 hover:bg-slate-50">
                    Export CSV
                </a>
            @endunless
        </div>
    </div>

    @if($complaintsDown)
        <div class="bg-amber-50 border border-amber-200 text-amber-800 text-xs font-semibold rounded-xl p-3">
            Complaint figures could not be loaded right now and are shown as “—”. Everything else is live.
        </div>
    @endif

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead class="bg-slate-50 border-b border-slate-200 text-[11px] text-slate-600 uppercase tracking-wide">
                    <tr>
                        <th class="p-3">{!! $th('name', 'Dealer') !!}</th>
                        <th class="p-3">{!! $th('sold_window', 'Sold (' . $days . 'd)') !!}</th>
                        <th class="p-3">{!! $th('sold_total', 'Sold total') !!}</th>
                        <th class="p-3">{!! $th('active_rate', 'Active rate') !!}</th>
                        <th class="p-3">{!! $th('lapsed_window', 'Lapsed (' . $days . 'd)') !!}</th>
                        <th class="p-3">{!! $th('stock', 'In stock') !!}</th>
                        <th class="p-3">{!! $th('stale_stock', 'Unsold > ' . DealerScorecard::STALE_STOCK_DAYS . 'd') !!}</th>
                        <th class="p-3">{!! $th('faulty', 'Faulty') !!}</th>
                        <th class="p-3">{!! $th('complaints', 'Complaints (' . $days . 'd)') !!}</th>
                        <th class="p-3">{!! $th('flags', 'Flags') !!}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-sm text-slate-700">
                    @forelse($rows as $r)
                        <tr class="align-top">
                            <td class="p-3">
                                <div class="font-semibold">{{ $r['name'] }}</div>
                                <div class="text-[11px] text-slate-400">{{ trim(($r['tier'] ?? '') . ($r['region'] ? ' · ' . $r['region'] : ''), ' ·') ?: '—' }}</div>
                            </td>
                            <td class="p-3 font-bold">{{ $num($r['sold_window']) }}</td>
                            <td class="p-3">{{ $num($r['sold_total']) }}</td>
                            <td class="p-3">
                                @php
                                    $low = $r['active_rate'] !== null && $r['sold_total'] >= DealerScorecard::MIN_SOLD_FOR_RATE_FLAG && $r['active_rate'] < DealerScorecard::LOW_ACTIVE_RATE;
                                @endphp
                                <span class="{{ $low ? 'text-red-700 font-bold' : '' }}">{{ $pct($r['active_rate']) }}</span>
                                @if($r['sold_total'] > 0)
                                    <div class="text-[11px] text-slate-400">{{ $r['active'] }} of {{ $r['sold_total'] }}</div>
                                @endif
                            </td>
                            <td class="p-3">{{ $num($r['lapsed_window']) }}</td>
                            <td class="p-3">
                                {{ $num($r['stock']) }}
                                @if($r['awaiting'] > 0)
                                    <div class="text-[11px] text-slate-400">+{{ $r['awaiting'] }} awaiting bind</div>
                                @endif
                                @if($r['avg_stock_days'] !== null)
                                    <div class="text-[11px] text-slate-400">avg {{ $r['avg_stock_days'] }}d · oldest {{ $r['oldest_stock_days'] }}d</div>
                                @endif
                            </td>
                            <td class="p-3 {{ $r['stale_stock'] > 0 ? 'text-amber-700 font-bold' : '' }}">{{ $num($r['stale_stock']) }}</td>
                            <td class="p-3">
                                {{ $num($r['faulty']) }}
                                @if($r['fault_rate'] !== null && $r['faulty'] > 0)
                                    <div class="text-[11px] text-slate-400">{{ $pct($r['fault_rate']) }} of devices</div>
                                @endif
                            </td>
                            <td class="p-3">
                                {{ $num($r['complaints']) }}
                                @if($r['complaints'] !== null)
                                    <div class="text-[11px] text-slate-400">
                                        {{ $r['complaints_open'] }} open now
                                        @if($r['complaints_escalated']) · {{ $r['complaints_escalated'] }} escalated @endif
                                        @if($r['complaints_avg_hours'] !== null) · avg {{ $r['complaints_avg_hours'] }}h to resolve @endif
                                    </div>
                                @endif
                            </td>
                            <td class="p-3">
                                @forelse($r['flags'] as $flag)
                                    <span class="inline-block mb-1 px-2 py-0.5 rounded-lg text-[11px] font-bold bg-amber-100 text-amber-800">{{ $flag }}</span>
                                @empty
                                    <span class="text-slate-300">—</span>
                                @endforelse
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="p-8 text-center text-slate-400 text-sm">No dealers to show.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-[11px] text-slate-400 leading-relaxed">
        <b>Sold</b> = devices staff have bound to a customer's vehicle.
        <b>Active rate</b> = sold devices whose subscription is currently paid (new sales not yet paid count as not active).
        <b>Lapsed</b> = subscriptions that expired in the period.
        <b>Unsold</b> = activated devices still in the dealer's stock.
        <b>Awaiting bind</b> = assigned to a customer, waiting for staff to bind.
    </p>
</div>