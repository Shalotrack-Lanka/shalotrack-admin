<div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full">
    <div class="px-6 py-4 border-b border-gray-100 bg-green-50 flex items-center justify-between">
        <h3 class="text-xl font-bold text-gray-800">Active Customers</h3>
        <div class="flex items-center gap-3">
            <span class="text-sm font-semibold text-green-700">{{ $activeCustomers->count() }} customers</span>
            <button
                @click="refreshTables()"
                :disabled="refreshing"
                class="text-xs font-semibold text-green-800 border border-green-300 hover:bg-green-100 rounded px-2 py-1 disabled:opacity-50">
                <span x-show="!refreshing">⟳ Refresh</span>
                <span x-show="refreshing">Refreshing...</span>
            </button>
            <a href="{{ route('admin.customer-setup.report', ['type' => 'active']) }}" target="_blank" class="bg-green-600 hover:bg-green-700 text-white text-xs font-bold px-3 py-1.5 rounded-lg shadow-sm transition inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                Generate Active Report
            </a>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm border-collapse">
            <thead class="bg-gray-50 border-b border-gray-200 font-bold text-gray-700">
                <tr>
                    <th class="p-3">Name</th>
                    <th class="p-3">Phone</th>
                    <th class="p-3">Email</th>
                    <th class="p-3">NIC</th>
                    <th class="p-3 text-center">Cus-status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($activeCustomers as $c)
                <tr>
                    <td class="p-3 font-semibold">{{ $c->full_name }}</td>
                    <td class="p-3">{{ $c->phone_number }}</td>
                    <td class="p-3">{{ $c->email }}</td>
                    <td class="p-3">{{ $c->nic_number }}</td>
                    <td class="p-3 text-center">
                        @include('admin.customer._status_toggle', ['c' => $c])
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" class="p-6 text-center text-gray-400">No active customers.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
