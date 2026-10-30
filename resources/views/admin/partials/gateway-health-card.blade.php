{{-- Gateway health card. Expects $gatewayStatus, $gatewayHistory, $gatewaySchedulerRunning.
     Only renders when something needs attention OR history exists, so a healthy gateway stays quiet. --}}
@php
    $gwState   = $gatewayStatus['state'] ?? 'down';
    $gwReasons = $gatewayStatus['reasons'] ?? [];
    $gwAge     = (int) ($gatewayStatus['age_s'] ?? 0);
    $gwTone    = match ($gwState) {
        'ok'       => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        'degraded' => 'border-amber-200 bg-amber-50 text-amber-800',
        default    => 'border-red-200 bg-red-50 text-red-800',
    };
    $gwLabel = ['ok' => 'Healthy', 'degraded' => 'Degraded', 'down' => 'Down'][$gwState] ?? 'Unknown';
@endphp
<div class="mb-6 st-card rounded-xl border p-4 {{ $gwTone }}" id="gateway-health">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-sm font-extrabold">Gateway health: {{ $gwLabel }}</h2>
        <span class="text-[11px] font-medium opacity-75">
            checked {{ $gwAge < 5 ? 'just now' : $gwAge . 's ago' }}
            @if(($gatewayStatus['public_path_ok'] ?? null) !== null)
                · public endpoint {{ $gatewayStatus['public_path_ok'] ? 'reachable' : 'NOT reachable' }}
            @endif
        </span>
    </div>

    @if(count($gwReasons) > 0)
        <ul class="mt-2 list-disc pl-5 text-xs font-semibold space-y-1">
            @foreach($gwReasons as $reason)
                <li>{{ $reason }}</li>
            @endforeach
        </ul>
    @endif

    <div class="mt-3 text-[11px] font-medium opacity-90">
        @if($gatewaySchedulerRunning && ($gatewayHistory['available'] ?? false) && $gatewayHistory['availability_pct'] !== null)
            Availability, last {{ $gatewayHistory['covered_days'] }} {{ \Illuminate\Support\Str::plural('day', $gatewayHistory['covered_days']) }}: <strong>{{ number_format($gatewayHistory['availability_pct'], 2) }}%</strong>
        @elseif(! $gatewaySchedulerRunning)
            History is not being recorded: the scheduled check (<code>gateway:check</code>) is not running on this server, so this card shows live status only.
        @else
            No history recorded yet.
        @endif
    </div>

    @if(($gatewayHistory['available'] ?? false) && $gatewayHistory['recent']->isNotEmpty())
        <ul class="mt-2 text-[11px] space-y-0.5">
            @foreach($gatewayHistory['recent'] as $event)
                <li>
                    <span class="font-bold">{{ ucfirst($event->state) }}</span>
                    since {{ $event->recorded_at->local()->format('M j, h:i A') }}
                    @if(!empty($event->reasons)) — {{ \Illuminate\Support\Str::limit((string) ($event->reasons[0] ?? ''), 90) }} @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>