<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ShaloTrack Admin - Cancel Sim</title>

    @vite(['resources/css/app.css','resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body x-data="{ sidebarOpen: false }">

<div class="flex h-screen overflow-hidden">

    @include('partials.sidebars.admin')

    <div class="flex-1 flex flex-col overflow-y-auto">
        @include('partials.header')

        <main class="p-4 md:p-6 flex-1 bg-gray-50 space-y-6" x-data="cancelSimPage()">

            <!-- Header & Refresh Button -->
            <div class="flex justify-between items-center">
                <h2 class="text-xl font-bold text-gray-800">SIM Status Management</h2>
                <button
                    @click="refresh()"
                    :disabled="refreshing"
                    class="flex items-center gap-2 bg-white border border-gray-300 hover:bg-gray-100 disabled:opacity-50 text-gray-700 text-sm font-semibold px-4 py-2 rounded-lg shadow-sm transition">
                    <svg class="w-4 h-4" :class="refreshing ? 'animate-spin' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    Refresh
                </button>
            </div>

            <!-- TABLE 1: AVAILABLE / ACTIVE SIMS -->
            <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full">
                <div class="px-6 py-4 border-b border-gray-100 bg-emerald-50/50 flex items-center justify-between">
                    <h3 class="text-md font-bold text-emerald-800 flex items-center gap-2">
                        <span class="w-3 h-3 bg-emerald-500 rounded-full animate-pulse"></span>
                        Available / Active SIMs
                    </h3>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead class="bg-gray-50 border-b border-gray-200 text-gray-700">
                            <tr>
                                <th class="p-3 font-bold">SIM Number</th>
                                <th class="p-3 font-bold">SIM Type</th>
                                <th class="p-3 font-bold w-52">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            <template x-for="sim in activeSims" :key="sim.id">
                                <tr class="hover:bg-gray-50/50 transition">
                                    <td class="p-3 font-semibold text-gray-800" x-text="sim.sim_number"></td>
                                    <td class="p-3" x-text="sim.sim_type"></td>
                                    
                                    <td class="p-3">
                                        <select 
                                            :value="sim.sim_status" 
                                            @change="updateStatus(sim, $event)"
                                            :disabled="sim.saving"
                                            :class="getStatusBadgeClass(sim.sim_status)"
                                            class="w-full rounded-lg border-0 text-xs font-bold py-1.5 px-3 focus:ring-2 focus:ring-blue-400 cursor-pointer shadow-sm transition">
                                            <option value="Activated">Activated</option>
                                            <option value="Not Activated">Not Activated</option>
                                            <option value="Temporary Blocked">Temporary Blocked</option>
                                            <option value="Canceled">Canceled</option>
                                        </select>
                                    </td>
                                </tr>
                            </template>

                            <tr x-show="activeSims.length === 0">
                                <td colspan="3" class="p-6 text-center text-gray-400 font-semibold">No active or available SIMs found.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TABLE 2: CANCELED SIMS -->
            <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full">
                <div class="px-6 py-4 border-b border-gray-100 bg-red-50/50 flex items-center justify-between">
                    <h3 class="text-md font-bold text-red-800 flex items-center gap-2">
                        <span class="w-3 h-3 bg-red-500 rounded-full"></span>
                        Canceled SIMs
                    </h3>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead class="bg-gray-50 border-b border-gray-200 text-gray-700">
                            <tr>
                                <th class="p-3 font-bold">SIM Number</th>
                                <th class="p-3 font-bold">SIM Type</th>
                                <th class="p-3 font-bold w-52">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            <template x-for="sim in canceledSims" :key="sim.id">
                                <tr class="bg-red-50/20 hover:bg-red-50/40 transition">
                                    <td class="p-3 font-semibold text-gray-800" x-text="sim.sim_number"></td>
                                    <td class="p-3" x-text="sim.sim_type"></td>
                                    
                                    <td class="p-3">
                                        <select 
                                            :value="sim.sim_status" 
                                            @change="updateStatus(sim, $event)"
                                            :disabled="sim.saving"
                                            :class="getStatusBadgeClass(sim.sim_status)"
                                            class="w-full rounded-lg border-0 text-xs font-bold py-1.5 px-3 focus:ring-2 focus:ring-blue-400 cursor-pointer shadow-sm transition">
                                            <option value="Activated">Activated</option>
                                            <option value="Not Activated">Not Activated</option>
                                            <option value="Temporary Blocked">Temporary Blocked</option>
                                            <option value="Canceled">Canceled</option>
                                        </select>
                                    </td>
                                </tr>
                            </template>

                            <tr x-show="canceledSims.length === 0">
                                <td colspan="3" class="p-6 text-center text-gray-400 font-semibold">No canceled SIMs.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TOAST NOTIFICATION -->
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

<script>
function cancelSimPage() {
    return {
        sims: @json($sims).map(s => ({ ...s, saving: false })),
        refreshing: false,
        toast: { show: false, message: '', type: 'success' },

        get activeSims() {
            return this.sims.filter(s => s.sim_status !== 'Canceled');
        },

        get canceledSims() {
            return this.sims.filter(s => s.sim_status === 'Canceled');
        },

        getStatusBadgeClass(status) {
            if (status === 'Activated') return 'bg-green-100 text-green-700';
            if (status === 'Not Activated') return 'bg-gray-100 text-gray-700';
            if (status === 'Temporary Blocked') return 'bg-amber-100 text-amber-700';
            if (status === 'Canceled') return 'bg-red-100 text-red-700';
            return 'bg-gray-100 text-gray-700';
        },

        async updateStatus(sim, event) {
            const newStatus = event.target.value;
            const oldStatus = sim.sim_status;

            sim.saving = true;

            try {
                const response = await fetch(`/admin/master-pages/cancel-sim/${sim.id}`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    },
                    body: JSON.stringify({
                        status: newStatus
                    }),
                });

                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Database update failed');
                }

                
                sim.sim_status = newStatus;
                this.showToast(`${sim.sim_number} status updated to ${newStatus}.`, 'success');

            } catch (e) {
               
                event.target.value = oldStatus;
                sim.sim_status = oldStatus;
                this.showToast('Failed to update Database!', 'error');
                console.error(e);
            } finally {
                sim.saving = false;
            }
        },

        async refresh() {
            this.refreshing = true;
            try {
                const res = await fetch(window.location.href, {
                    headers: { 'Accept': 'application/json' },
                });
                if (!res.ok) throw new Error('Refresh failed');
                const data = await res.json();
                this.sims = data.map(s => ({ ...s, saving: false }));
                this.showToast('Table refreshed.', 'success');
            } catch (e) {
                this.showToast('Failed to refresh.', 'error');
            } finally {
                this.refreshing = false;
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