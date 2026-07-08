<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ShaloTrack Admin - Cancel Device</title>

    @vite(['resources/css/app.css','resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body x-data="{ sidebarOpen: false }">

<div class="flex h-screen overflow-hidden">

    @include('partials.sidebars.admin')

    <div class="flex-1 flex flex-col overflow-y-auto">
        @include('partials.header')

        <main class="p-4 md:p-6 flex-1 bg-gray-50" x-data="cancelDevicePage()">

            <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full">

                <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
                    <h3 class="text-lg font-bold text-gray-800">Cancel Device</h3>
                    <button
                        @click="refresh()"
                        class="flex items-center gap-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-semibold px-4 py-2 rounded-lg shadow-sm transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        Refresh
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead class="bg-gray-50 border-b border-gray-200 text-gray-700">
                            <tr>
                                <th class="p-3 font-bold">IMEI Number</th>
                                <th class="p-3 font-bold">SIM Number</th>
                                <th class="p-3 font-bold">Device Model</th>
                                <th class="p-3 font-bold w-44">Status</th>
                                <th class="p-3 font-bold">Description</th>
                                <th class="p-3 font-bold">Canceled Date</th>
                                <th class="p-3 font-bold text-center w-24">Save</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            <template x-for="device in devices" :key="device.id">
                                <tr>
                                    <td class="p-3 font-semibold text-gray-800" x-text="device.imei_number"></td>
                                    <td class="p-3" x-text="device.sim_number ?? '-'"></td>
                                    <td class="p-3" x-text="device.device_model"></td>

                                    <td class="p-3">
                                        <select
                                            x-model="device.status"
                                            :class="statusClass(device.status)"
                                            class="w-full rounded-lg border-0 font-bold text-xs h-9 px-2 focus:ring-2 focus:ring-blue-400">
                                            <option value="Available">Available</option>
                                            <option value="Temporarily Stopped">Temporarily Stopped</option>
                                            <option value="Canceled">Canceled</option>
                                        </select>
                                    </td>

                                    <td class="p-3">
                                        <input
                                            type="text"
                                            x-model="device.description"
                                            class="w-full rounded-lg border-gray-300 text-xs h-9 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    </td>

                                    <td class="p-3 text-gray-500" x-text="device.canceled_date ?? '-'"></td>

                                    <td class="p-3 text-center">
                                        <button
                                            @click="save(device)"
                                            :disabled="device.saving"
                                            class="bg-[#17a2b8] hover:bg-[#138496] disabled:opacity-50 text-white font-bold text-xs px-3 py-2 rounded-lg shadow-sm transition">
                                            <span x-show="!device.saving">Save</span>
                                            <span x-show="device.saving">...</span>
                                        </button>
                                    </td>
                                </tr>
                            </template>

                            <tr x-show="devices.length === 0">
                                <td colspan="7" class="p-6 text-center text-gray-400 font-semibold">No devices found.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>

            <!-- Toast -->
            <div
                x-show="toast.show"
                x-transition
                x-cloak
                class="fixed top-6 right-6 z-50 px-5 py-3 rounded-lg shadow-lg font-semibold text-sm text-white"
                :class="toast.type === 'error' ? 'bg-red-600' : 'bg-green-600'"
                x-text="toast.message">
            </div>

        </main>
    </div>
</div>

<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>

<script>
function cancelDevicePage() {
    return {
        devices: @json($devices).map(d => ({ ...d, saving: false })),
        toast: { show: false, message: '', type: 'success' },

        statusClass(status) {
            if (status === 'Available') return 'bg-green-100 text-green-700';
            if (status === 'Temporarily Stopped') return 'bg-yellow-100 text-yellow-700';
            if (status === 'Canceled') return 'bg-red-100 text-red-700';
            return 'bg-gray-100 text-gray-700';
        },

        refresh() {
            location.reload();
        },

        async save(device) {
            device.saving = true;

            try {
                const res = await fetch(`/admin/master-pages/cancel-device/${device.id}`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        status: device.status,
                        description: device.description,
                    }),
                });

                if (!res.ok) throw new Error('Save failed');

                const data = await res.json();
                device.canceled_date = data.canceled_date;

                this.showToast(`${device.imei_number} updated successfully.`, 'success');
            } catch (e) {
                this.showToast('Failed to save. Check the console.', 'error');
                console.error(e);
            } finally {
                device.saving = false;
            }
        },

        showToast(message, type = 'success') {
            this.toast = { show: true, message, type };
            setTimeout(() => this.toast.show = false, 3000);
        }
    }
}
</script>

</body>
</html>