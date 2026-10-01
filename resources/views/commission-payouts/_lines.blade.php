{{-- Statement lines. $lines: array of line arrays from CommissionStatement. $showReason: show the "why pending" column. --}}
@php
    use App\Services\CommissionStatement;
    $tz = CommissionStatement::TZ;
    $showReason = $showReason ?? false;
    $day = fn ($c) => $c ? $c->copy()->setTimezone($tz)->format('Y-m-d') : '—';
@endphp

<div class="overflow-x-auto">
    <table class="w-full text-left border-collapse">
        <thead class="bg-slate-50 border-b border-slate-200 text-[11px] text-slate-600 uppercase tracking-wide">
            <tr>
                <th class="p-3">Device</th>
                <th class="p-3">Customer</th>
                <th class="p-3">Recorded</th>
                <th class="p-3">Subscription start</th>
                <th class="p-3">{{ $showReason ? 'Why not payable yet' : 'Payable from' }}</th>
                <th class="p-3 text-right">Amount (LKR)</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 text-sm text-slate-700">
            @forelse($lines as $l)
                <tr>
                    <td class="p-3">
                        <div class="font-mono text-xs">{{ $l['imei'] }}</div>
                        @if($l['kind'] === 'clawback')
                            <span class="inline-block mt-1 px-2 py-0.5 rounded-lg text-[11px] font-bold bg-red-100 text-red-700">Clawback</span>
                        @endif
                    </td>
                    <td class="p-3">
                        {{ $l['customer_name'] ?: '—' }}
                        <div class="text-[11px] text-slate-400">{{ $l['vehicle_number'] ?: '' }}</div>
                    </td>
                    <td class="p-3">{{ $day($l['sold_on']) }}</td>
                    <td class="p-3">{{ $day($l['sub_start']) }}</td>
                    <td class="p-3">
                        @if($showReason)
                            {{ $l['reason'] }}
                            @if($l['payable_from'])<div class="text-[11px] text-slate-400">earliest {{ $day($l['payable_from']) }}</div>@endif
                        @elseif($l['kind'] === 'clawback')
                            <span class="text-[11px] text-slate-500">{{ $l['reason'] }}</span>
                        @else
                            {{ $day($l['payable_from']) }}
                        @endif
                    </td>
                    <td class="p-3 text-right font-semibold {{ $l['amount_cents'] < 0 ? 'text-red-700' : '' }}">{{ CommissionStatement::money($l['amount_cents']) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="p-6 text-center text-slate-400 text-sm">None.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>