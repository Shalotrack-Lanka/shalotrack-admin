<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin - Setup Shalotrack Device</title>

    @vite(['resources/css/app.css','resources/js/app.js'])

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body x-data="{ sidebarOpen: false }">

<div class="flex h-screen overflow-hidden">

    @include('partials.sidebars.admin')

    <div class="flex-1 flex flex-col overflow-y-auto">
        @include('partials.header')

        <main class="p-4 md:p-6 flex-1 space-y-6">

            @if ($errors->any())
                <div class="p-3 bg-red-50 text-red-700 border border-red-200 rounded-lg text-xs font-bold">
                    <ul class="list-disc pl-4 space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if(session('success'))
                <div class="p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg flex items-center gap-2 text-xs font-bold shadow-xs transition duration-300">
                    <svg class="w-4 h-4 text-green-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>{{ session('success') }}</span>
                </div>
            @endif

            <!-- FORM -->
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">
                    Setup Shalotrack Device
                </div>
                <div class="p-5 text-xs font-semibold text-gray-700">
                    <form action="{{ route('admin.device.store') }}" method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @csrf

                        <div>
                            <label class="block mb-1">Device Category / Type</label>
                            <select name="device_category" required class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm h-10">
                                <option value="" selected disabled>--Select Device Type--</option>
                                @forelse($deviceTypes as $type)
                                    <option value="{{ $type->device_category }} with {{ $type->model }}">
                                        {{ $type->device_category }} with {{ $type->model }}
                                    </option>
                                @empty
                                    <option value="" disabled>No device types configured yet</option>
                                @endforelse
                            </select>
                        </div>

                        <div>
                            <label class="block mb-1">Device Imei Number</label>
                            <input type="text" name="imei_number" required maxlength="15" pattern="\d{15}" inputmode="numeric" title="IMEI must be exactly 15 digits" class="w-full rounded-lg border-gray-300 h-10 shadow-sm">
                        </div>

                        <div>
                            <label class="block mb-1">Device SIM Number</label>
                            <input type="text" name="sim_number" maxlength="10" inputmode="numeric" pattern="\d{10}" placeholder="0771234567" class="w-full rounded-lg border-gray-300 h-10 shadow-sm">
                        </div>

                        <div class="md:col-span-2 flex gap-2 pt-2">
                            <button type="reset" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 py-2 rounded-lg font-bold shadow-sm transition">Reset</button>
                            <button type="submit" class="bg-[#17a2b8] hover:bg-[#138496] text-white px-5 py-2 rounded-lg font-bold shadow-sm transition">Setup Device</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- LIST -->
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
                    <span class="font-bold text-gray-800 text-sm">Setup Shalotrack Devices</span>
                    <button id="refreshBtn" type="button"
                            class="px-3 py-1.5 rounded-lg border border-gray-300 text-gray-700 text-xs font-bold hover:bg-gray-100 flex items-center gap-1.5">
                        <svg id="refreshIcon" class="w-3.5 h-3.5 transition-transform duration-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        Refresh
                    </button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-left">
                        <thead class="bg-gray-50 text-gray-500 uppercase text-[10px]">
                            <tr>
                                <th class="px-5 py-2">ID</th>
                                <th class="px-5 py-2">Device Category</th>
                                <th class="px-5 py-2">IMEI Number</th>
                                <th class="px-5 py-2">SIM Number</th>
                                <th class="px-5 py-2">Setup Date</th>
                            </tr>
                        </thead>
                        <tbody id="deviceTableBody" class="divide-y divide-gray-100 text-gray-700">
                            @forelse($devices as $device)
                                <tr>
                                    <td class="px-5 py-2">{{ $device->shdevice_id }}</td>
                                    <td class="px-5 py-2">{{ $device->device_category }}</td>
                                    <td class="px-5 py-2">{{ $device->imei_number }}</td>
                                    <td class="px-5 py-2">{{ $device->sim_number ?? '-' }}</td>
                                    <td class="px-5 py-2">{{ $device->created_at->format('Y-m-d H:i') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-5 py-6 text-center text-gray-400">No devices setup yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>
</div>

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>

<script>
document.getElementById('refreshBtn').addEventListener('click', function () {
    const icon = document.getElementById('refreshIcon');
    const body = document.getElementById('deviceTableBody');
    icon.classList.add('animate-spin');

    fetch("{{ route('admin.device.list') }}", { headers: { 'Accept': 'application/json' } })
        .then(res => res.json())
        .then(devices => {
            if (!devices.length) {
                body.innerHTML = `<tr><td colspan="5" class="px-5 py-6 text-center text-gray-400">No devices setup yet.</td></tr>`;
                return;
            }
            body.innerHTML = devices.map(d => `
                <tr>
                    <td class="px-5 py-2">${d.shdevice_id}</td>
                    <td class="px-5 py-2">${d.device_category}</td>
                    <td class="px-5 py-2">${d.imei_number}</td>
                    <td class="px-5 py-2">${d.sim_number ?? '-'}</td>
                    <td class="px-5 py-2">${new Date(d.created_at).toLocaleString()}</td>
                </tr>
            `).join('');
        })
        .catch(err => console.error('Refresh failed:', err))
        .finally(() => setTimeout(() => icon.classList.remove('animate-spin'), 300));
});
</script>
</body>
</html>