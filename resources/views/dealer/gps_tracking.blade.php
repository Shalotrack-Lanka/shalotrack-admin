@extends('layouts.dealer')

@section('title', 'GPS Tracking')

@section('content')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>

<div class="max-w-7xl mx-auto space-y-5">

    {{-- Header & Vehicle Selector --}}
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <div>
            <h1 class="text-2xl font-black text-blue-950">GPS Live Tracking</h1>
            <p class="text-xs text-slate-500 mt-0.5">Track your customers' registered vehicles in real-time via ShaloTrack System.</p>
        </div>

        <form action="{{ route('dealer.gps-tracking') }}" method="GET" class="flex items-center gap-3 w-full sm:w-auto">
            <select name="vehicle_id" onchange="this.form.submit()" 
                    class="w-full sm:w-64 text-xs font-bold border border-slate-300 rounded-xl px-3 py-2.5 focus:ring focus:ring-blue-200 bg-slate-50">
                @forelse($vehicles as $v)
                    <option value="{{ $v->vehicle_id }}" {{ $selectedVehicle && $selectedVehicle->vehicle_id == $v->vehicle_id ? 'selected' : '' }}>
                        {{ $v->vehicle_number ?? 'No Plate' }} ({{ $v->make }} {{ $v->model }})
                    </option>
                @empty
                    <option value="">No GPS Vehicles Found</option>
                @endforelse
            </select>
        </form>
    </div>

    @if($selectedVehicle)
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
            {{-- Map Container --}}
            <div class="lg:col-span-2 bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden min-h-[500px] relative">
                <div id="dealer-map" style="width: 100%; height: 520px; min-height: 520px; z-index: 10;"></div>
            </div>

            {{-- Vehicle Details & Live Status --}}
            <div class="space-y-4">
                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-3">
                    <div class="text-[10px] font-black uppercase text-slate-400 tracking-wider">Vehicle Overview</div>
                    <div class="text-lg font-black text-blue-950">{{ $selectedVehicle->vehicle_number ?? 'No Plate' }}</div>
                    
                    <div class="text-xs text-slate-600 space-y-2 border-t border-slate-100 pt-3">
                        <div class="flex justify-between"><span class="font-bold text-slate-500">Make & Model:</span> <span>{{ $selectedVehicle->make }} {{ $selectedVehicle->model }}</span></div>
                        <div class="flex justify-between"><span class="font-bold text-slate-500">IMEI:</span> <span class="font-mono bg-slate-100 px-2 py-0.5 rounded text-[11px]">{{ $selectedVehicle->imei }}</span></div>
                        <div class="flex justify-between"><span class="font-bold text-slate-500">Customer:</span> <span class="font-bold text-blue-900">{{ $selectedVehicle->customer_name ?? '—' }}</span></div>
                    </div>
                </div>

                {{-- Live API Telemetry Card --}}
                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-3">
                    <div class="text-[10px] font-black uppercase text-slate-400 tracking-wider">Telemetry Status</div>
                    
                    <div class="flex items-center justify-between {{ $locationData['is_online'] ? 'bg-emerald-50 border-emerald-200' : 'bg-amber-50 border-amber-200' }} border p-3 rounded-xl">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full {{ $locationData['is_online'] ? 'bg-emerald-500 animate-pulse' : 'bg-amber-500' }}"></span>
                            <span class="text-xs font-bold {{ $locationData['is_online'] ? 'text-emerald-800' : 'text-amber-800' }}">
                                {{ $locationData['is_online'] ? 'Live GPS Location' : 'Default Location' }}
                            </span>
                        </div>
                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full {{ $locationData['is_online'] ? 'bg-emerald-200 text-emerald-800' : 'bg-amber-200 text-amber-800' }}">
                            {{ $locationData['speed'] }} km/h
                        </span>
                    </div>

                    <div class="text-xs text-slate-600 space-y-2 pt-1">
                        <div>
                            <span class="font-bold text-slate-500">Resolved Address:</span>
                            <p class="text-slate-800 font-medium mt-0.5 bg-slate-50 p-2.5 rounded-lg border border-slate-100 leading-relaxed text-[11px]">
                                {{ $resolvedAddress }}
                            </p>
                        </div>
                        <div class="flex justify-between pt-1 border-t border-slate-100">
                            <span class="font-bold text-slate-500">Coordinates:</span>
                            <span class="font-mono text-slate-700 font-semibold">{{ number_format($locationData['latitude'], 5) }}, {{ number_format($locationData['longitude'], 5) }}</span>
                        </div>
                        @if($locationData['timestamp'])
                            <div class="flex justify-between">
                                <span class="font-bold text-slate-500">Last Updated:</span>
                                <span class="text-slate-600 font-mono text-[11px]">{{ \Carbon\Carbon::parse($locationData['timestamp'])->diffForHumans() }}</span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-slate-200 p-12 text-center text-slate-400 text-sm">
            No active GPS vehicles assigned to your registered customers.
        </div>
    @endif

</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script>
    function initDealerMap() {
        @if($selectedVehicle)
            const mapElement = document.getElementById('dealer-map');
            if (!mapElement) return;

            const vehLat = {{ $locationData['latitude'] }};
            const vehLng = {{ $locationData['longitude'] }};
            const vehPlate = "{{ $selectedVehicle->vehicle_number ?? 'Vehicle' }}";
            const vehAddress = "{{ addslashes($resolvedAddress) }}";

            // Map Initialization
            const map = L.map('dealer-map').setView([vehLat, vehLng], 15);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);

            // Custom Vehicle Marker
            const carIcon = L.icon({
                iconUrl: 'https://cdn-icons-png.flaticon.com/512/684/684908.png',
                iconSize: [36, 36],
                iconAnchor: [18, 36],
                popupAnchor: [0, -36]
            });

            // Add Marker
            L.marker([vehLat, vehLng], { icon: carIcon }).addTo(map)
                .bindPopup('<div class="p-1"><b style="font-size:13px;">' + vehPlate + '</b><br><span style="font-size:11px; color:#666;">IMEI: {{ $selectedVehicle->imei }}</span><br><p style="font-size:10px; margin-top:4px; color:#333;">' + vehAddress + '</p></div>')
                .openPopup();

            // Resize Fix
            setTimeout(function() {
                map.invalidateSize();
            }, 300);
        @endif
    }

    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        setTimeout(initDealerMap, 100);
    } else {
        document.addEventListener('DOMContentLoaded', initDealerMap);
    }
</script>
@endsection