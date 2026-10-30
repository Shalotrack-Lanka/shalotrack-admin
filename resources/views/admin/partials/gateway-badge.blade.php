{{-- Three-state gateway badge. Expects $gatewayStatus (App\Services\GatewayStatus::current()). --}}
@php
    $gwState = $gatewayStatus['state'] ?? 'down';
    $gwStyle = match ($gwState) {
        'ok'       => ['border-emerald-200 text-emerald-700', 'bg-emerald-400', 'bg-emerald-500', 'Gateway Online'],
        'degraded' => ['border-amber-200 text-amber-700',     'bg-amber-400',   'bg-amber-500',   'Gateway Degraded'],
        default    => ['border-red-200 text-red-700',         'bg-red-400',     'bg-red-500',     'Gateway Offline'],
    };
@endphp
<span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border {{ $gwStyle[0] }} rounded-full text-xs font-bold shadow-sm">
    <span class="relative flex h-2 w-2">
        <span class="animate-ping absolute inline-flex h-full w-full rounded-full {{ $gwStyle[1] }} opacity-75"></span>
        <span class="relative inline-flex rounded-full h-2 w-2 {{ $gwStyle[2] }}"></span>
    </span>
    {{ $gwStyle[3] }}
</span>