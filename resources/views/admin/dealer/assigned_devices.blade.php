@extends('layouts.admin')

@section('title', 'Assigned Devices')

@section('content')
<div class="space-y-6">

    <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full">
        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50">
            <h3 class="text-base font-bold text-gray-800">Search Assigned Devices</h3>
        </div>
        <div class="p-6">
            <form method="GET" action="{{ route('admin.dealer.assigned-devices') }}"
                  class="flex flex-col md:flex-row gap-3 text-xs font-semibold text-gray-700">
                <input type="text" name="search" value="{{ $search ?? '' }}"
                       placeholder="Search by IMEI or SIM number..."
                       class="flex-1 rounded-lg border-gray-300 h-10 shadow-sm">

                <select name="dealer_id" class="w-full md:w-56 rounded-lg border-gray-300 h-10 shadow-sm">
                    <option value="">All Dealers</option>
                    @foreach($dealers as $dealer)
                        <option value="{{ $dealer->id }}" {{ (string) ($dealerId ?? '') === (string) $dealer->id ? 'selected' : '' }}>
                            {{ $dealer->full_name }}
                        </option>
                    @endforeach
                </select>

                <button type="submit" class="bg-[#17a2b8] hover:bg-[#138496] text-white px-5 h-10 rounded-lg font-bold shadow-sm">
                    Search
                </button>

                @if(($search ?? '') !== '' || ($dealerId ?? '') !== '')
                    <a href="{{ route('admin.dealer.assigned-devices') }}"
                       class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 h-10 flex items-center rounded-lg font-bold shadow-sm">
                        Clear
                    </a>
                @endif
            </form>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full">
        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
            <h3 class="text-base font-bold text-gray-800">Assigned Devices</h3>
            <span class="text-xs text-gray-400 font-semibold">{{ $devices->count() }} device(s)</span>
        </div>
        <div class="p-6">
            <div class="border border-gray-200 rounded-lg overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-gray-50 border-b border-gray-200 text-xs text-gray-700 uppercase">
                        <tr>
                            <th class="p-4">IMEI Number</th>
                            <th class="p-4">SIM Number</th>
                            <th class="p-4">Device Type</th>
                            <th class="p-4">Dealer Name</th>
                            <th class="p-4">Status</th>
                            <th class="p-4">Allocation Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white text-sm font-medium text-gray-700">
                        @forelse($devices as $device)
                            <tr class="hover:bg-gray-50 transition">
                                <td class="p-4 font-mono text-xs">{{ $device->imei_number }}</td>
                                <td class="p-4">{{ $device->sim_number ?? '-' }}</td>
                                <td class="p-4">
                                    {{ $device->deviceType->device_category ?? $device->device_category ?? '-' }}
                                    @if($device->deviceType?->model)
                                        <span class="text-gray-400">— {{ $device->deviceType->model }}</span>
                                    @endif
                                </td>
                                <td class="p-4 font-bold">{{ $device->dealer->full_name ?? '-' }}</td>
                                <td class="p-4">
                                    <span class="px-2 py-1 rounded-full bg-blue-50 text-blue-700 border border-blue-200 text-[10px] font-bold">
                                        {{ $device->status }}
                                    </span>
                                </td>
                                <td class="p-4 text-xs text-gray-500">
                                    @if($device->allocated_at)
                                        {{ $device->allocated_at->format('d M Y, h:i A') }}
                                    @else
                                        <span class="text-gray-400 italic">Not recorded (allocated before tracking was added)</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="p-8 text-center text-gray-400">
                                    @if(($search ?? '') !== '' || ($dealerId ?? '') !== '')
                                        No assigned devices match your search.
                                    @else
                                        No devices have been assigned to any dealer yet.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
@endsection