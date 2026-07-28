@extends('layouts.admin')

@section('content')
<div class="p-6 bg-gray-50 min-h-screen">
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-800">GPS Tracking History</h2>
    </div>

    @if($errorMessage ?? false)
        <div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg font-medium">
            {{ $errorMessage }}
        </div>
    @endif

    <!-- 1. Search Filter Section -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <form action="{{ route('admin.vehicles.gps') }}" method="GET">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Vehicle ID or IMEI</label>
                    <input type="text" name="search" value="{{ $search }}"
                           class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 p-2 border"
                           placeholder="Paste a Vehicle UUID or 15-digit IMEI">
                    <p class="text-xs text-gray-400 mt-1">Either works — detected automatically.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">From Date/Time</label>
                    <input type="datetime-local" name="from_date" value="{{ $fromDate }}" class="w-full border-gray-300 rounded-md shadow-sm p-2 border">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">To Date/Time</label>
                    <input type="datetime-local" name="to_date" value="{{ $toDate }}" class="w-full border-gray-300 rounded-md shadow-sm p-2 border">
                </div>
            </div>

            <div class="mt-4 flex justify-end gap-2">
                <a href="{{ route('admin.vehicles.gps') }}" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400">Clear</a>
                @if($historyData->isNotEmpty())
                    <a href="{{ route('admin.vehicles.gps.export', request()->query()) }}"
                       class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 font-semibold shadow flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        Generate Report
                    </a>
                @endif
                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 font-semibold shadow">
                    Search Route
                </button>
            </div>
        </form>
    </div>

    <!-- 2. Vehicle / Device / Current Location Summary -->
    @if($vehicle)
        <div class="bg-white rounded-lg shadow mb-6 p-6">
            <h3 class="font-semibold text-gray-700 mb-4">Vehicle & Device Summary</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <div>
                    <div class="text-gray-400 text-xs uppercase font-bold mb-1">Vehicle</div>
                    <div class="font-medium">{{ $vehicle['vehicleNumber'] ?? '-' }}</div>
                    <div class="text-gray-500">{{ $vehicle['make'] ?? '' }} {{ $vehicle['model'] ?? '' }}</div>
                </div>
                <div>
                    <div class="text-gray-400 text-xs uppercase font-bold mb-1">Customer</div>
                    <div class="font-medium">{{ $vehicle['customerName'] ?? '-' }}</div>
                </div>
                <div>
                    <div class="text-gray-400 text-xs uppercase font-bold mb-1">GPS Device</div>
                    @if($vehicle['hasGpsDevice'] ?? false)
                        <span class="px-2 py-0.5 rounded-full bg-green-50 text-green-700 border border-green-200 text-xs font-bold">{{ $vehicle['imei'] ?? 'Linked' }}</span>
                    @else
                        <span class="px-2 py-0.5 rounded-full bg-gray-50 text-gray-500 border border-gray-200 text-xs font-bold">None</span>
                    @endif
                </div>
                <div>
                    <div class="text-gray-400 text-xs uppercase font-bold mb-1">Current Location</div>
                    @if($currentLocation)
                        <div class="font-medium">{{ $currentLocation['latitude'] }}, {{ $currentLocation['longitude'] }}</div>
                        <div class="text-gray-500">{{ \Carbon\Carbon::parse($currentLocation['eventTime'])->diffForHumans() }}</div>
                    @else
                        <div class="text-gray-400">No recent data</div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <!-- 3. Map Section -->
    <div class="bg-white rounded-lg shadow mb-6 overflow-hidden">
        <div class="p-4 border-b bg-gray-50 flex justify-between items-center">
            <h3 class="font-semibold text-gray-700">Route Map View</h3>
            <span class="text-sm text-green-600 font-bold flex items-center gap-1">
                <div class="w-3 h-3 rounded-full bg-green-500"></div> Start Location
            </span>
            <span class="text-sm text-red-600 font-bold flex items-center gap-1">
                <div class="w-3 h-3 rounded-full bg-red-500"></div> End Location
            </span>
        </div>
        @if($historyData->isNotEmpty())
            <div id="tracking-map" class="w-full h-96"></div>
            <div class="p-4 border-t bg-gray-50">
                <div class="flex items-center gap-3">
                    <button type="button" id="playback-toggle"
                            class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 font-semibold shadow text-sm w-20">
                        Play
                    </button>
                    <input type="range" id="playback-slider" min="0" max="0" value="0"
                           class="flex-1 h-2 rounded-lg appearance-none bg-gray-200 cursor-pointer">
                    <select id="playback-speed" class="border-gray-300 rounded-md shadow-sm text-sm p-1.5">
                        <option value="1">1x</option>
                        <option value="4" selected>4x</option>
                        <option value="10">10x</option>
                        <option value="30">30x</option>
                    </select>
                </div>
                <div class="mt-2 text-sm text-gray-600 flex justify-between">
                    <span id="playback-time">-</span>
                    <span id="playback-speed-value">-</span>
                </div>
            </div>
        @else
            <div id="tracking-map" class="w-full h-96 bg-gray-200 flex items-center justify-center">
                <span class="text-gray-500 font-medium">Search a Vehicle ID or IMEI to view the route on map.</span>
            </div>
        @endif
    </div>

    <!-- 4. Data List Section -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 class="font-semibold text-gray-700">Tracking Data History</h3>
            <span class="text-xs text-gray-400 font-medium">{{ $historyData->count() }} points (max 100 per page)</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full whitespace-nowrap">
                <thead class="bg-gray-100 text-gray-600 text-left text-sm">
                    <tr>
                        <th class="px-6 py-3">Date & Time</th>
                        <th class="px-6 py-3">Latitude</th>
                        <th class="px-6 py-3">Longitude</th>
                        <th class="px-6 py-3">Speed (km/h)</th>
                        <th class="px-6 py-3">Heading</th>
                        <th class="px-6 py-3">Satellites</th>
                    </tr>
                </thead>
                <tbody class="text-sm text-gray-700">
                    @forelse($historyData as $point)
                    <tr class="border-b hover:bg-gray-50">
                        <td class="px-6 py-3">{{ \Carbon\Carbon::parse($point['eventTime'])->format('Y-m-d H:i:s') }}</td>
                        <td class="px-6 py-3">{{ $point['latitude'] }}</td>
                        <td class="px-6 py-3">{{ $point['longitude'] }}</td>
                        <td class="px-6 py-3">{{ $point['speed'] }}</td>
                        <td class="px-6 py-3">{{ $point['heading'] }}&deg;</td>
                        <td class="px-6 py-3">{{ $point['satellites'] }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                            Search a Vehicle ID or IMEI to view its tracking history.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
    window.gpsHistoryData = @json($historyData->reverse()->values());

    document.addEventListener('DOMContentLoaded', function () {
        const points = window.gpsHistoryData;
        if (!points || points.length === 0) return;

        const latLngs = points.map(p => [parseFloat(p.latitude), parseFloat(p.longitude)]);

        // Smooths the raw GPS points into a curved line for display only —
        // playback still moves through the real recorded points/timestamps
        // below, this is purely visual so the route doesn't look jagged.
        function smoothPath(pts, segmentsPerPoint = 6) {
            if (pts.length < 3) return pts;
            const at = (i) => pts[Math.max(0, Math.min(pts.length - 1, i))];
            const out = [];
            for (let i = 0; i < pts.length - 1; i++) {
                const p0 = at(i - 1), p1 = at(i), p2 = at(i + 1), p3 = at(i + 2);
                for (let t = 0; t < segmentsPerPoint; t++) {
                    const s = t / segmentsPerPoint, s2 = s * s, s3 = s2 * s;
                    out.push([
                        0.5 * (2 * p1[0] + (-p0[0] + p2[0]) * s + (2*p0[0] - 5*p1[0] + 4*p2[0] - p3[0]) * s2 + (-p0[0] + 3*p1[0] - 3*p2[0] + p3[0]) * s3),
                        0.5 * (2 * p1[1] + (-p0[1] + p2[1]) * s + (2*p0[1] - 5*p1[1] + 4*p2[1] - p3[1]) * s2 + (-p0[1] + 3*p1[1] - 3*p2[1] + p3[1]) * s3),
                    ]);
                }
            }
            out.push(pts[pts.length - 1]);
            return out;
        }

        const map = L.map('tracking-map');
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors',
            maxZoom: 19,
        }).addTo(map);

        L.polyline(smoothPath(latLngs), { color: '#2563eb', weight: 4, opacity: 0.8, smoothFactor: 1 }).addTo(map);

        const startIcon = L.divIcon({
            className: '', html: '<div style="width:14px;height:14px;background:#22c55e;border:2px solid white;border-radius:50%;box-shadow:0 0 0 1px #22c55e"></div>',
            iconSize: [14, 14], iconAnchor: [7, 7],
        });
        const endIcon = L.divIcon({
            className: '', html: '<div style="width:14px;height:14px;background:#ef4444;border:2px solid white;border-radius:50%;box-shadow:0 0 0 1px #ef4444"></div>',
            iconSize: [14, 14], iconAnchor: [7, 7],
        });

        L.marker(latLngs[0], { icon: startIcon })
            .bindPopup('Start — ' + points[0].eventTime)
            .addTo(map);

        L.marker(latLngs[latLngs.length - 1], { icon: endIcon })
            .bindPopup('End — ' + points[points.length - 1].eventTime)
            .addTo(map);

        map.fitBounds(latLngs, { padding: [30, 30] });

        // --- Playback ---
        // Top-down "3D" car: gradient body + windshield panes + soft drop
        // shadow for depth, rotated to match each point's heading. The
        // rotation transform lives on an INNER div, not the marker's own
        // element — Leaflet uses the outer element's transform for
        // positioning, overwriting it directly would break map placement.
        const playbackIcon = L.divIcon({
            className: '',
            html: `
                <div class="car-rotate" style="width:28px;height:28px;transform:rotate(0deg);transition:transform 0.12s linear;">
                    <svg width="28" height="28" viewBox="0 0 28 28">
                        <defs>
                            <linearGradient id="carBody" x1="0" y1="0" x2="1" y2="1">
                                <stop offset="0%" stop-color="#fbbf24"/>
                                <stop offset="100%" stop-color="#b45309"/>
                            </linearGradient>
                        </defs>
                        <ellipse cx="14" cy="22" rx="7" ry="2" fill="black" opacity="0.25"/>
                        <rect x="9" y="4" width="10" height="18" rx="4" fill="url(#carBody)" stroke="#78350f" stroke-width="1"/>
                        <rect x="10.5" y="7" width="7" height="5" rx="1.5" fill="#bfdbfe" opacity="0.9"/>
                        <rect x="10.5" y="14" width="7" height="4" rx="1.5" fill="#bfdbfe" opacity="0.6"/>
                    </svg>
                </div>`,
            iconSize: [28, 28], iconAnchor: [14, 14],
        });
        const playbackMarker = L.marker(latLngs[0], { icon: playbackIcon }).addTo(map);

        const toggleBtn = document.getElementById('playback-toggle');
        const slider = document.getElementById('playback-slider');
        const speedSelect = document.getElementById('playback-speed');
        const timeLabel = document.getElementById('playback-time');
        const speedLabel = document.getElementById('playback-speed-value');

        slider.max = points.length - 1;

        let playIndex = 0;
        let playTimer = null;
        let isPlaying = false;

        function renderFrame(i) {
            playIndex = i;
            slider.value = i;
            playbackMarker.setLatLng(latLngs[i]);
            const el = playbackMarker.getElement();
            if (el) {
                const rotEl = el.querySelector('.car-rotate');
                if (rotEl) rotEl.style.transform = `rotate(${points[i].heading || 0}deg)`;
            }
            timeLabel.textContent = new Date(points[i].eventTime).toLocaleString();
            speedLabel.textContent = (points[i].speed ?? 0) + ' km/h';
        }

        function scheduleNext() {
            if (!isPlaying) return;
            if (playIndex >= points.length - 1) {
                isPlaying = false;
                toggleBtn.textContent = 'Play';
                return;
            }

            // Reduced clamp — snappier playback. Still paced by the real time
            // gap between points (scaled by speed), just with a tighter floor
            // and ceiling so it never feels sluggish even on a long trip.
            const speedMultiplier = parseFloat(speedSelect.value);
            const t0 = new Date(points[playIndex].eventTime).getTime();
            const t1 = new Date(points[playIndex + 1].eventTime).getTime();
            const deltaMs = Math.min(Math.max((t1 - t0) / speedMultiplier, 10), 400);

            playTimer = setTimeout(() => {
                renderFrame(playIndex + 1);
                scheduleNext();
            }, deltaMs);
        }

        toggleBtn.addEventListener('click', () => {
            if (isPlaying) {
                isPlaying = false;
                toggleBtn.textContent = 'Play';
                clearTimeout(playTimer);
            } else {
                if (playIndex >= points.length - 1) playIndex = 0;
                isPlaying = true;
                toggleBtn.textContent = 'Pause';
                scheduleNext();
            }
        });

        slider.addEventListener('input', () => {
            isPlaying = false;
            toggleBtn.textContent = 'Play';
            clearTimeout(playTimer);
            renderFrame(parseInt(slider.value, 10));
        });

        renderFrame(0);
    });
</script>
@endsection