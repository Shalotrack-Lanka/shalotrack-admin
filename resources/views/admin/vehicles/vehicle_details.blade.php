@extends('layouts.admin')

@section('content')
<div class="p-6 bg-gray-50 min-h-screen">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-gray-800">Vehicle Details List</h2>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full whitespace-nowrap">
                <thead class="bg-gray-100 text-gray-600 font-semibold text-left text-sm">
                    <tr>
                        <th class="px-6 py-4">Vehicle Number</th>
                        <th class="px-6 py-4">Make & Model</th>
                        <th class="px-6 py-4">Year</th>
                        <th class="px-6 py-4">Color</th>
                        <th class="px-6 py-4">Chassis Number</th>
                        <th class="px-6 py-4">Engine Number</th>
                        <th class="px-6 py-4">Type & Fuel</th>
                        <th class="px-6 py-4">Customer</th>
                        <th class="px-6 py-4">GPS Device</th>
                        <th class="px-6 py-4">Last Synced</th>
                    </tr>
                </thead>
                <tbody class="text-sm text-gray-700">
                    @forelse($vehicles as $vehicle)
                    <tr class="border-b hover:bg-gray-50">
                        <td class="px-6 py-4 font-bold text-blue-600">{{ $vehicle->vehicle_number }}</td>
                        <td class="px-6 py-4">{{ $vehicle->make }} - {{ $vehicle->model }}</td>
                        <td class="px-6 py-4">{{ $vehicle->year }}</td>
                        <td class="px-6 py-4">{{ $vehicle->color }}</td>
                        <td class="px-6 py-4">{{ $vehicle->chassis_number ?? 'N/A' }}</td>
                        <td class="px-6 py-4">{{ $vehicle->engine_number ?? 'N/A' }}</td>
                        <td class="px-6 py-4">
                            {{ $vehicle->vehicle_type ?? '-' }} / {{ $vehicle->fuel_type ?? '-' }}
                        </td>
                        <td class="px-6 py-4">{{ $vehicle->customer_name ?? 'N/A' }}</td>
                        <td class="px-6 py-4">
                            @if($vehicle->has_gps_device)
                                <span class="px-2 py-0.5 rounded-full bg-green-50 text-green-700 border border-green-200 text-xs font-bold">{{ $vehicle->imei ?? 'Linked' }}</span>
                            @else
                                <span class="px-2 py-0.5 rounded-full bg-gray-50 text-gray-500 border border-gray-200 text-xs font-bold">None</span>
                            @endif
                        </td>
                        <td class="px-6 py-4">{{ $vehicle->last_synced_at?->format('Y-m-d H:i') ?? '-' }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="10" class="px-6 py-4 text-center text-red-500 font-medium">
                            No Vehicles Found.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t">
            {{ $vehicles->links() }}
        </div>
    </div>
</div>
@endsection