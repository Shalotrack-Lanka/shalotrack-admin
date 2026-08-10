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
