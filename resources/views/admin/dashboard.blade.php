<!DOCTYPE html>
<html lang="en" id="htmlRoot">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin - Dashboard</title>

    @vite(['resources/css/app.css','resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        /* Design tokens kept local to this view so a redesign here can't
           leak into other pages that reuse the same Tailwind utility names. */
        :root {
            --st-radius: 14px;
            --st-border: #e7eaf0;
            --st-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 1px 12px rgba(15, 23, 42, 0.03);
        }

        .st-card {
            background: #fff;
            border: 1px solid var(--st-border);
            border-radius: var(--st-radius);
            box-shadow: var(--st-shadow);
        }

        .st-tile-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .st-section-label {
            font-size: 0.68rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #94a3b8;
        }

        .st-num {
            font-variant-numeric: tabular-nums;
        }
    </style>
</head>

<body x-data="{ sidebarOpen: false }" class="font-sans antialiased bg-[#f6f7fa] text-slate-800 transition-colors duration-200">

    <div class="flex h-screen overflow-hidden">
        @include('partials.sidebars.admin')

        <div class="flex-1 flex flex-col h-screen overflow-y-auto main-content">

            @include('partials.header')

            <main class="p-4 md:p-8 flex-1 w-full max-w-7xl mx-auto">

                {{-- ================= PAGE HEADER ================= --}}
                <div class="mb-6 flex flex-col sm:flex-row sm:justify-between sm:items-end gap-4">
                    <div>
                        <h1 class="text-2xl md:text-[28px] font-extrabold text-slate-900 tracking-tight">Dashboard</h1>
                        <p class="text-slate-500 text-sm mt-1">Live operational overview — ShaloTrack fleet platform.</p>
                    </div>

                    <div class="flex items-center gap-2 flex-wrap">
                        @if($gatewayOnline)
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-emerald-200 text-emerald-700 rounded-full text-xs font-bold shadow-sm">
                            <span class="relative flex h-2 w-2">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                            </span>
                            Gateway Online
                        </span>
                        @else
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-red-200 text-red-700 rounded-full text-xs font-bold shadow-sm">
                            <span class="relative flex h-2 w-2">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2 w-2 bg-red-500"></span>
                            </span>
                            Gateway Offline
                        </span>
                        @endif
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-slate-200 text-slate-700 rounded-full text-xs font-bold shadow-sm">
                            <span class="w-2 h-2 bg-blue-500 rounded-full"></span>
                            {{ $devicesOnlineNow }} / {{ $totalTrackedVehicles }} devices live
                        </span>
                        <span class="text-[11px] text-slate-400 font-medium px-1">
                            Updated {{ now()->format('h:i A') }}
                        </span>
                    </div>
                </div>

                {{-- ================= EXCEPTION BANNER =================
                 Production dashboards should lead with what's WRONG, not
                 bury it inside a stat tile's fine print. Only renders when
                 there is actually something to act on. --}}
                @php
                $alerts = [];
                if (!$gatewayOnline) {
                $alerts[] = ['level' => 'critical', 'text' => 'Gateway is unreachable — live device data is stale until it recovers.'];
                }
                if ($complaintsUnavailable) {
                $alerts[] = ['level' => 'warning', 'text' => "Complaints could not be loaded from the app server, so the complaint numbers on this page are not reliable right now."];
                }
                if ($openComplaintsCount > 0) {
                $alerts[] = ['level' => 'warning', 'text' => $openComplaintsCount . ' ' . Str::plural('complaint', $openComplaintsCount) . ' waiting on admin action.', 'href' => route('admin.complaints.index')];
                }
                if ($outOfStockCategories > 0) {
                $stockAlertText = $outOfStockCategories . ' stock ' . Str::plural('category', $outOfStockCategories) . ' at zero units';
                $stockAlertText .= count($outOfStockCategoryNames) > 0
                ? ' — ' . implode(', ', array_slice($outOfStockCategoryNames, 0, 3))
                . (count($outOfStockCategoryNames) > 3 ? ', +' . (count($outOfStockCategoryNames) - 3) . ' more' : '')
                : ' — restock needed.';
                $alerts[] = ['level' => 'warning', 'text' => $stockAlertText];
                }
                if ($staleDevicesCount > 0) {
                $alerts[] = ['level' => 'warning', 'text' => $staleDevicesCount . ' tracked ' . Str::plural('vehicle', $staleDevicesCount) . ' — no GPS sync in 24h+.'];
                }
                @endphp

                @if(count($alerts) > 0)
                <div class="mb-6 space-y-2">
                    @foreach($alerts as $alert)
                    @php
                    $isCritical = $alert['level'] === 'critical';
                    @endphp
                    <div class="flex items-center justify-between gap-3 rounded-xl border px-4 py-3 text-sm font-semibold
                            {{ $isCritical ? 'bg-red-50 border-red-200 text-red-800' : 'bg-amber-50 border-amber-200 text-amber-800' }}">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                            </svg>
                            <span class="truncate">{{ $alert['text'] }}</span>
                        </div>
                        @if(isset($alert['href']))
                        <a href="{{ $alert['href'] }}" class="flex-shrink-0 text-xs font-bold underline underline-offset-2 hover:opacity-75">Review &rarr;</a>
                        @endif
                    </div>
                    @endforeach
                </div>
                @endif

                {{-- ================= TREND HELPER =================
                 A raw count ("4 open complaints") has no sense of direction.
                 This renders a small delta badge next to a tile's number,
                 sourced from dashboard_metric_snapshots (7+ days old vs
                 today — see DashboardController). Returns nothing (not a
                 fake "no change") until a snapshot old enough actually
                 exists, so a freshly-deployed dashboard doesn't lie. --}}
                @php
                $renderTrend = function ($delta, string $goodDirection) use ($trendSnapshotDate) {
                if ($delta === null || $trendSnapshotDate === null) {
                return '<span class="text-[10px] text-slate-400 font-semibold">no trend data yet</span>';
                }
                if ($delta === 0) {
                return '<span class="text-[10px] text-slate-400 font-semibold">flat vs ' . e($trendSnapshotDate) . '</span>';
                }
                $isUp = $delta > 0;
                // goodDirection = 'up' means a rising number is good news (e.g. devices online);
                // 'down' means a rising number is bad news (e.g. open complaints).
                $isGood = $goodDirection === 'up' ? $isUp : !$isUp;
                $color = $isGood ? 'text-emerald-600' : 'text-red-600';
                $arrow = $isUp ? '&uarr;' : '&darr;';
                return '<span class="text-[10px] font-bold ' . $color . '">' . $arrow . ' ' . abs($delta) . ' vs ' . e($trendSnapshotDate) . '</span>';
                };
                @endphp

                {{-- ================= BUSINESS KPI STRIP ================= --}}
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8 w-full">
                    @php
                    $primaryKpis = [
                    ['label' => 'Total Customers', 'value' => $totalCustomers, 'sub' => number_format($verifiedCustomers) . ' verified', 'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z', 'accent' => 'blue'],
                    ['label' => 'Total Dealers', 'value' => $totalDealers, 'sub' => number_format($activeDealers) . ' active', 'icon' => 'M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z', 'accent' => 'violet'],
                    ['label' => 'Total Suppliers', 'value' => $totalSuppliers, 'sub' => number_format($activeSuppliers) . ' active', 'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4', 'accent' => 'amber'],
                    ['label' => 'Tracked Vehicles', 'value' => $totalTrackedVehicles, 'sub' => number_format($devicesOnlineNow) . ' online now', 'icon' => 'M8 17l4-9 4 9M6 21l6-13 6 13M4 21h16', 'accent' => 'emerald'],
                    ];
                    $accentMap = [
                    'blue' => ['bg' => 'bg-blue-50', 'text' => 'text-blue-600'],
                    'violet' => ['bg' => 'bg-violet-50', 'text' => 'text-violet-600'],
                    'amber' => ['bg' => 'bg-amber-50', 'text' => 'text-amber-600'],
                    'emerald' => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-600'],
                    ];
                    @endphp

                    @foreach($primaryKpis as $kpi)
                    @php $a = $accentMap[$kpi['accent']]; @endphp
                    <div class="st-card p-5">
                        <div class="flex items-start justify-between mb-4">
                            <span class="st-tile-icon {{ $a['bg'] }} {{ $a['text'] }}">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $kpi['icon'] }}" />
                                </svg>
                            </span>
                        </div>
                        <p class="st-section-label mb-1">{{ $kpi['label'] }}</p>
                        <h2 class="st-num text-2xl md:text-3xl font-extrabold text-slate-900 leading-tight">{{ number_format($kpi['value']) }}</h2>
                        <p class="text-xs text-slate-500 font-medium mt-1">{{ $kpi['sub'] }}</p>
                    </div>
                    @endforeach
                </div>

                {{-- ================= FLEET & COMPLAINTS ================= --}}
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-extrabold text-slate-700 uppercase tracking-wide">Fleet &amp; Complaints</h2>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8 w-full">
                    <a href="{{ route('admin.vehicles.device-commands') }}" class="st-card p-5 flex items-center gap-3.5 hover:border-blue-300 hover:shadow-md transition">
                        <span class="st-tile-icon bg-blue-50 text-blue-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <p class="st-section-label">Devices Online</p>
                            <p class="st-num text-lg font-extrabold text-slate-900">{{ $devicesOnlineNow }} <span class="text-sm font-semibold text-slate-400">/ {{ $totalTrackedVehicles }}</span></p>
                            <div class="mt-0.5">{!! $renderTrend($trends['devicesOnline'], 'up') !!}</div>
                        </div>
                    </a>

                    <div class="st-card p-5 flex items-center gap-3.5">
                        <span class="st-tile-icon {{ $staleDevicesCount > 0 ? 'bg-amber-50 text-amber-600' : 'bg-slate-50 text-slate-400' }}">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <p class="st-section-label">Stale Devices (24h+)</p>
                            <p class="st-num text-lg font-extrabold {{ $staleDevicesCount > 0 ? 'text-amber-600' : 'text-slate-900' }}">{{ $staleDevicesCount }}</p>
                            <p class="text-[10px] text-slate-400 font-medium mt-0.5">no GPS sync received</p>
                        </div>
                    </div>

                    <a href="{{ route('admin.complaints.index') }}" class="st-card p-5 flex items-center gap-3.5 hover:border-red-300 hover:shadow-md transition">
                        <span class="st-tile-icon {{ $openComplaintsCount > 0 ? 'bg-red-50 text-red-600' : 'bg-slate-50 text-slate-400' }}">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8-1.284 0-2.503-.24-3.606-.675L3 20l1.395-4.184C3.512 14.502 3 13.29 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <p class="st-section-label">Open Complaints</p>
                            @if($complaintsUnavailable)
                            <p class="st-num text-lg font-extrabold text-slate-400">&mdash;</p>
                            <p class="text-[10px] text-amber-600 font-semibold mt-0.5">unavailable right now</p>
                            @else
                            <p class="st-num text-lg font-extrabold {{ $openComplaintsCount > 0 ? 'text-red-600' : 'text-slate-900' }}">{{ $openComplaintsCount }}</p>
                            <div class="mt-0.5">{!! $renderTrend($trends['openComplaints'], 'down') !!}</div>
                            @endif
                        </div>
                    </a>

                    <div class="st-card p-5 flex items-center gap-3.5">
                        <span class="st-tile-icon bg-violet-50 text-violet-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6l4 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <p class="st-section-label">Avg Resolution Time</p>
                            @if($avgResolutionHours !== null)
                            <p class="st-num text-lg font-extrabold text-slate-900">
                                @if($avgResolutionHours >= 24)
                                {{ floor($avgResolutionHours / 24) }}d {{ round(fmod($avgResolutionHours, 24)) }}h
                                @else
                                {{ $avgResolutionHours }}h
                                @endif
                            </p>
                            @else
                            <p class="text-sm font-semibold text-slate-400">No resolved data yet</p>
                            @endif
                        </div>
                    </div>

                    <a href="{{ route('admin.complaints.resolved') }}" class="st-card p-5 flex items-center gap-3.5 hover:border-emerald-300 hover:shadow-md transition">
                        <span class="st-tile-icon bg-emerald-50 text-emerald-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <p class="st-section-label">Resolved Complaints</p>
                            <p class="st-num text-lg font-extrabold text-slate-900">{{ $resolvedComplaintsCount }}</p>
                        </div>
                    </a>

                    <div class="st-card p-5 flex items-center gap-3.5">
                        <span class="st-tile-icon bg-pink-50 text-pink-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.5 18.75h-9m9 0a3 3 0 013 3h-15a3 3 0 013-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H11.25m6.75-1.5a3 3 0 00-3-3H9a3 3 0 00-3 3v3.75m14.25-3.75a3 3 0 01-3 3h-9a3 3 0 01-3-3" />
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <p class="st-section-label">Top Complaint Source</p>
                            @if($topDealerName !== null)
                            <p class="text-sm font-extrabold text-slate-900 truncate">{{ $topDealerName }}</p>
                            <p class="text-[10px] text-slate-400 font-medium">{{ $topDealerComplaintCount }} open {{ Str::plural('complaint', $topDealerComplaintCount) }}</p>
                            @else
                            <p class="text-sm font-semibold text-slate-400">No open complaints</p>
                            @endif
                        </div>
                    </div>

                    <div class="st-card p-5 flex items-center gap-3.5">
                        <span class="st-tile-icon bg-amber-50 text-amber-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <p class="st-section-label">Pending Activation</p>
                            <p class="st-num text-lg font-extrabold text-slate-900">{{ $notActivatedDevices }} <span class="text-sm font-semibold text-slate-400">/ {{ $totalDevices }}</span></p>
                        </div>
                    </div>
                </div>

                {{-- ================= INVENTORY & PARTNERS ================= --}}
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-extrabold text-slate-700 uppercase tracking-wide">Inventory &amp; Partners</h2>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-10 w-full">
                    <div class="st-card p-5 flex items-center gap-3.5">
                        <span class="st-tile-icon bg-teal-50 text-teal-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <p class="st-section-label">Stock Units</p>
                            <p class="st-num text-lg font-extrabold text-slate-900">{{ number_format($stockUnitsAvailable) }}</p>
                            <div class="mt-0.5">{!! $renderTrend($trends['stockUnits'], 'up') !!}</div>
                            @if($outOfStockCategories > 0)
                            <p class="text-[10.5px] font-bold text-red-600 mt-0.5 truncate" title="{{ implode(', ', $outOfStockCategoryNames) }}">
                                {{ $outOfStockCategories }} {{ Str::plural('category', $outOfStockCategories) }} out: {{ implode(', ', array_slice($outOfStockCategoryNames, 0, 2)) }}{{ count($outOfStockCategoryNames) > 2 ? '…' : '' }}
                            </p>
                            @endif
                        </div>
                    </div>

                    <div class="st-card p-5 flex items-center gap-3.5">
                        <span class="st-tile-icon bg-indigo-50 text-indigo-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7a2 2 0 012-2h6.5L21 8.5V17a2 2 0 01-2 2H11a2 2 0 01-2-2z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 13h4M13 16h2" />
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <p class="st-section-label">SIM Inventory</p>
                            <p class="st-num text-lg font-extrabold text-slate-900">{{ $activatedSIMs }} <span class="text-sm font-semibold text-slate-400">/ {{ $totalSIMs }}</span></p>
                        </div>
                    </div>

                    <a href="{{ route('admin.dealer-management') }}" class="st-card p-5 flex items-center gap-3.5 hover:border-violet-300 hover:shadow-md transition">
                        <span class="st-tile-icon bg-violet-50 text-violet-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <p class="st-section-label">Active Dealers</p>
                            <p class="st-num text-lg font-extrabold text-slate-900">{{ $activeDealers }} <span class="text-sm font-semibold text-slate-400">/ {{ $totalDealers }}</span></p>
                        </div>
                    </a>

                    <div class="st-card p-5 flex items-center gap-3.5">
                        <span class="st-tile-icon bg-orange-50 text-orange-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5" />
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <p class="st-section-label">Active Suppliers</p>
                            <p class="st-num text-lg font-extrabold text-slate-900">{{ $activeSuppliers }} <span class="text-sm font-semibold text-slate-400">/ {{ $totalSuppliers }}</span></p>
                        </div>
                    </div>
                </div>

                {{-- ================= CHARTS ================= --}}
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-5 w-full">
                    <div class="lg:col-span-2 st-card p-5 md:p-6">
                        <div class="flex justify-between items-center mb-5">
                            <div>
                                <h3 class="font-extrabold text-base md:text-lg text-slate-900">Customer Growth</h3>
                                <p class="text-xs text-slate-400 mt-0.5">Cumulative registrations, last 6 months</p>
                            </div>
                            <span class="bg-blue-50 text-blue-600 px-2.5 py-1 rounded-full text-[11px] font-bold">6 mo</span>
                        </div>
                        <div class="h-[240px] md:h-[280px] w-full relative">
                            <canvas id="customerChart"></canvas>
                        </div>
                    </div>

                    <div class="lg:col-span-1 st-card p-5 md:p-6">
                        <h3 class="font-extrabold text-base md:text-lg text-slate-900 mb-1">Customer Status</h3>
                        <p class="text-xs text-slate-400 mb-4">Verification breakdown</p>
                        <div class="h-[190px] w-full relative flex justify-center items-center">
                            <canvas id="deviceChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-8 w-full">
                    <div class="lg:col-span-1 st-card p-5 md:p-6">
                        <h3 class="font-extrabold text-base md:text-lg text-slate-900 mb-1">Fleet Live Status</h3>
                        <p class="text-xs text-slate-400 mb-4">Devices connected to gateway right now</p>
                        <div class="h-[180px] w-full relative flex justify-center items-center">
                            <canvas id="fleetChart"></canvas>
                        </div>
                    </div>

                    <div class="lg:col-span-2 st-card overflow-hidden">
                        <div class="p-5 md:p-6 border-b border-slate-100 flex justify-between items-center">
                            <div>
                                <h3 class="font-extrabold text-base md:text-lg text-slate-900">Recent Open Complaints</h3>
                                <p class="text-slate-400 text-xs mt-0.5">Latest items currently with admin</p>
                            </div>
                            <a href="{{ route('admin.complaints.index') }}" class="text-xs font-bold text-blue-600 hover:text-blue-800 transition flex-shrink-0">View all &rarr;</a>
                        </div>
                        <div class="divide-y divide-slate-100 max-h-[300px] overflow-y-auto">
                            @forelse($recentComplaints as $complaint)
                            <div class="px-5 md:px-6 py-3.5 flex items-start justify-between gap-3">
                                <div class="flex items-start gap-3 min-w-0">
                                    <span class="mt-0.5 w-2 h-2 rounded-full bg-red-500 flex-shrink-0"></span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-bold text-slate-800 truncate">{{ $complaint['vehicleNumber'] ?? 'Unknown Vehicle' }}</p>
                                        <p class="text-xs text-slate-500 truncate mt-0.5">{{ \Illuminate\Support\Str::limit($complaint['description'] ?? '—', 70) }}</p>
                                    </div>
                                </div>
                                <span class="text-[10px] text-slate-400 flex-shrink-0 mt-0.5 whitespace-nowrap">
                                    {{ isset($complaint['createdAt']) ? \Carbon\Carbon::parse($complaint['createdAt'])->diffForHumans() : '' }}
                                </span>
                            </div>
                            @empty
                            <div class="px-5 md:px-6 py-10 text-center text-slate-400 text-sm font-medium">
                                Nothing open right now — inbox is clear.
                            </div>
                            @endforelse
                        </div>
                    </div>
                </div>

                {{-- ================= RECENT CUSTOMERS ================= --}}
                <div class="st-card w-full overflow-hidden">
                    <div class="p-5 md:p-6 border-b border-slate-100 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                        <div>
                            <h3 class="font-extrabold text-base md:text-lg text-slate-900">Recent Customers</h3>
                            <p class="text-slate-400 text-xs mt-0.5">Latest registrations</p>
                        </div>
                        <a href="{{ route('admin.customer-setup') }}" class="w-full sm:w-auto bg-slate-900 hover:bg-slate-800 text-white px-4 py-2.5 rounded-xl transition text-sm font-semibold shadow-sm text-center">
                            View all customers
                        </a>
                    </div>

                    <div class="overflow-x-auto w-full">
                        <table class="w-full min-w-[720px] text-left text-sm border-collapse whitespace-nowrap">
                            <thead class="bg-slate-50/80 text-slate-500 font-semibold text-[11px] uppercase tracking-wider">
                                <tr>
                                    <th class="px-6 py-3.5">Customer</th>
                                    <th class="px-6 py-3.5">Email</th>
                                    <th class="px-6 py-3.5">Phone</th>
                                    <th class="px-6 py-3.5">NIC</th>
                                    <th class="px-6 py-3.5">Address</th>
                                    <th class="px-6 py-3.5 text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-slate-700">
                                @forelse($recentCustomers as $c)
                                @php
                                $initials = collect(explode(' ', trim($c->full_name ?? 'NA')))
                                ->filter()
                                ->map(fn($p) => mb_strtoupper(mb_substr($p, 0, 1)))
                                ->take(2)
                                ->implode('');
                                @endphp
                                <tr class="hover:bg-slate-50/70 transition-colors duration-150">
                                    <td class="px-6 py-3.5">
                                        <div class="flex items-center gap-3">
                                            <span class="w-8 h-8 rounded-full bg-blue-50 text-blue-600 text-[11px] font-extrabold flex items-center justify-center flex-shrink-0">
                                                {{ $initials ?: '—' }}
                                            </span>
                                            <div class="min-w-0">
                                                <p class="font-bold text-slate-900 truncate">{{ $c->full_name ?? 'N/A' }}</p>
                                                <p class="font-mono text-[11px] text-slate-400">{{ $c->customer_id }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-3.5 text-slate-600">{{ $c->email ?? 'N/A' }}</td>
                                    <td class="px-6 py-3.5 font-mono text-xs">{{ $c->phone_number ?? 'N/A' }}</td>
                                    <td class="px-6 py-3.5 font-mono text-xs">{{ $c->nic_number ?? 'N/A' }}</td>
                                    <td class="px-6 py-3.5 text-slate-600 max-w-[200px] truncate" title="{{ $c->address }}">
                                        {{ $c->address ?? 'N/A' }}
                                    </td>
                                    <td class="px-6 py-3.5 text-center">
                                        @if($c->cus_status === 'verified')
                                        <span class="inline-flex items-center gap-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200 px-2.5 py-1 rounded-full text-[11px] font-bold">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Verified
                                        </span>
                                        @else
                                        <span class="inline-flex items-center gap-1.5 bg-amber-50 text-amber-700 border border-amber-200 px-2.5 py-1 rounded-full text-[11px] font-bold">
                                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span> Not Verified
                                        </span>
                                        @endif
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-12 text-center text-slate-400 bg-slate-50/40">
                                        <div class="flex flex-col items-center justify-center">
                                            <svg class="w-9 h-9 mb-3 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                                            </svg>
                                            No customers found in database.
                                        </div>
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <script>
        let customerChartInstance = null;
        let deviceChartInstance = null;
        let fleetChartInstance = null;

        function buildCharts() {
            const ctxLineElement = document.getElementById('customerChart');
            if (!ctxLineElement) return;

            const ctxLine = ctxLineElement.getContext('2d');

            let gradientFill = ctxLine.createLinearGradient(0, 0, 0, 280);
            gradientFill.addColorStop(0, 'rgba(59, 130, 246, 0.35)');
            gradientFill.addColorStop(1, 'rgba(59, 130, 246, 0.0)');

            Chart.defaults.font.family = "'Inter', 'sans-serif'";
            Chart.defaults.color = '#64748b';

            if (customerChartInstance) customerChartInstance.destroy();
            if (deviceChartInstance) deviceChartInstance.destroy();
            if (fleetChartInstance) fleetChartInstance.destroy();

            customerChartInstance = new Chart(ctxLine, {
                type: 'line',
                data: {
                    labels: @json($customerGrowthLabels),
                    datasets: [{
                        label: 'Total Customers',
                        data: @json($customerGrowthData),
                        borderColor: '#3b82f6',
                        backgroundColor: gradientFill,
                        borderWidth: 2.5,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#ffffff',
                        pointBorderColor: '#3b82f6',
                        pointBorderWidth: 2,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        pointHoverBackgroundColor: '#3b82f6',
                        pointHoverBorderColor: '#ffffff',
                        pointHoverBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            padding: 10,
                            titleFont: {
                                size: 12
                            },
                            bodyFont: {
                                size: 13,
                                weight: 'bold'
                            },
                            displayColors: false,
                            cornerRadius: 8
                        }
                    },
                    scales: {
                        x: {
                            ticks: {
                                color: '#94a3b8',
                                font: {
                                    size: 11
                                }
                            },
                            grid: {
                                display: false
                            },
                            border: {
                                display: false
                            }
                        },
                        y: {
                            ticks: {
                                color: '#94a3b8',
                                font: {
                                    size: 11
                                },
                                padding: 8,
                                precision: 0
                            },
                            grid: {
                                color: '#f1f5f9',
                                borderDash: [4, 4]
                            },
                            border: {
                                display: false
                            },
                            beginAtZero: true
                        }
                    }
                }
            });

            const ctxDoughnut = document.getElementById('deviceChart');
            deviceChartInstance = new Chart(ctxDoughnut, {
                type: 'doughnut',
                data: {
                    labels: ['Verified', 'Not Verified'],
                    datasets: [{
                        data: @json([$verifiedCustomers, $notVerifiedCustomers]),
                        backgroundColor: ['#10b981', '#f59e0b'],
                        borderWidth: 0,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: {
                        padding: 6
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 16,
                                usePointStyle: true,
                                pointStyle: 'circle',
                                font: {
                                    size: 11,
                                    weight: '500'
                                }
                            }
                        },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            padding: 10,
                            bodyFont: {
                                size: 12
                            },
                            cornerRadius: 8,
                            callbacks: {
                                label: (ctx) => ' ' + ctx.label + ': ' + ctx.raw
                            }
                        }
                    },
                    cutout: '72%'
                }
            });

            const ctxFleet = document.getElementById('fleetChart');
            fleetChartInstance = new Chart(ctxFleet, {
                type: 'doughnut',
                data: {
                    labels: ['Online', 'Offline'],
                    datasets: [{
                        data: @json([$devicesOnlineNow, $devicesOfflineNow]),
                        backgroundColor: ['#3b82f6', '#e2e8f0'],
                        borderWidth: 0,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: {
                        padding: 6
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 14,
                                usePointStyle: true,
                                pointStyle: 'circle',
                                font: {
                                    size: 11,
                                    weight: '500'
                                }
                            }
                        },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            padding: 10,
                            bodyFont: {
                                size: 12
                            },
                            cornerRadius: 8,
                            callbacks: {
                                label: (ctx) => ' ' + ctx.label + ': ' + ctx.raw
                            }
                        }
                    },
                    cutout: '72%'
                }
            });
        }

        window.addEventListener('DOMContentLoaded', () => {
            buildCharts();
        });
    </script>

</body>

</html>