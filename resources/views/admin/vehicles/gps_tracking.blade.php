@extends('layouts.admin') 

@section('content')
<div class="p-6 bg-gray-50 min-h-screen">
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-800">GPS Tracking History</h2>
    </div>

    <!-- 1. Search Filter Section -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <form action="{{ route('admin.vehicles.gps') }}" method="GET">
            <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-4">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">IMEI Number</label>
                    <input type="text" name="imei" value="{{ request('imei') }}" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 p-2 border" placeholder="Enter IMEI">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Vehicle ID</label>
                    <input type="text" name="vehicle_id" value="{{ request('vehicle_id') }}" class="w-full border-gray-300 rounded-md shadow-sm p-2 border" placeholder="Vehicle UUID">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Customer ID</label>
                    <input type="text" name="customer_id" value="{{ request('customer_id') }}" class="w-full border-gray-300 rounded-md shadow-sm p-2 border" placeholder="Customer UUID">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">From Date/Time</label>
                    <input type="datetime-local" name="from_date" value="{{ request('from_date') }}" class="w-full border-gray-300 rounded-md shadow-sm p-2 border">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">To Date/Time</label>
                    <input type="datetime-local" name="to_date" value="{{ request('to_date') }}" class="w-full border-gray-300 rounded-md shadow-sm p-2 border">
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

    <!-- 2. Map Section -->
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
        <!-- Map Container -->
        <!-- මෙතනට ඔයා පාවිච්චි කරන Map API එක (Google Maps/Leaflet) connect කරන්න -->
        <div id="tracking-map" class="w-full h-96 bg-gray-200 flex items-center justify-center">
            @if(request('imei') || request('vehicle_id'))
                <span class="text-gray-500 font-medium">Map will render here with Start/End markers based on JS...</span>
            @else
                <span class="text-gray-500 font-medium">Please search a vehicle or IMEI to view the route on map.</span>
            @endif
        </div>
    </div>

    <!-- 3. Report Generation & Data List Section -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 class="font-semibold text-gray-700">Tracking Data History</h3>
            <button class="px-4 py-2 bg-green-600 text-white text-sm rounded hover:bg-green-700 shadow flex items-center gap-2">
                Download Report (PDF/Excel)
            </button>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full whitespace-nowrap">
                <thead class="bg-gray-100 text-gray-600 text-left text-sm">
                    <tr>
                        <th class="px-6 py-3">Date & Time</th>
                        <th class="px-6 py-3">Latitude</th>
                        <th class="px-6 py-3">Longitude</th>
                        <th class="px-6 py-3">Speed (km/h)</th>
                        <th class="px-6 py-3">Engine Status</th>
                    </tr>
                </thead>
                <tbody class="text-sm text-gray-700">
                    <!-- Dummy data loop for testing. Replace with $historyData -->
                    @if(request('imei'))
                    <tr class="border-b hover:bg-gray-50">
                        <td class="px-6 py-3">2026-07-21 08:30:00</td>
                        <td class="px-6 py-3">6.9271</td>
                        <td class="px-6 py-3">79.8612</td>
                        <td class="px-6 py-3">45 km/h</td>
                        <td class="px-6 py-3 text-green-600 font-bold">ON</td>
                    </tr>
                    <tr class="border-b hover:bg-gray-50">
                        <td class="px-6 py-3">2026-07-21 09:45:00</td>
                        <td class="px-6 py-3">6.8402</td>
                        <td class="px-6 py-3">79.9953</td>
                        <td class="px-6 py-3">0 km/h</td>
                        <td class="px-6 py-3 text-red-600 font-bold">OFF</td>
                    </tr>
                    @else
                    <tr>
                        <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                            Search for tracking history to generate the list and map.
                        </td>
                    </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Map Scripts here (Google Maps API or Leaflet JS) -->
<script>
    // Example: JS Logic to grab history data and draw lines/markers on the map
    // You will need to pass $historyData to Javascript as JSON.
</script>
@endsection