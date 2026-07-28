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

        const map = L.map('tracking-map');
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors',
            maxZoom: 19,
        }).addTo(map);

        L.polyline(latLngs, { color: '#2563eb', weight: 4, opacity: 0.8 }).addTo(map);

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
    });
</script>
@endsection