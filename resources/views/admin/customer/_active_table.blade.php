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
                    <th class="p-3">IMEI</th>
                    <th class="p-3">SIM</th>
                    <th class="p-3">Device</th>
                    <th class="p-3">Period</th>
                    <th class="p-3">Start</th>
                    <th class="p-3">End</th>
                    <th class="p-3">Invoice</th>
                    <th class="p-3 text-center">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($activeCustomers as $c)
                <tr>
                    <td class="p-3 font-semibold">{{ $c->full_name }}</td>
                    <td class="p-3">{{ $c->phone_number }}</td>
                    <td class="p-3">{{ $c->imei_number ?? '-' }}</td>
                    <td class="p-3">{{ $c->sim_number ?? '-' }}</td>
                    <td class="p-3">{{ $c->device_type ?? '-' }}</td>
                    <td class="p-3">{{ $c->subscription_period ?? '-' }}</td>
                    <td class="p-3">{{ $c->subscription_start_date?->format('Y-m-d') ?? '-' }}</td>
                    <td class="p-3">{{ $c->subscription_end_date?->format('Y-m-d') ?? '-' }}</td>
                    <td class="p-3">
                        @if($c->bank_invoice_path)
                            <a href="{{ route('admin.customer-setup.invoice', $c->customer_id) }}" target="_blank" class="text-blue-600 hover:underline">View</a>
                        @else
                            <span class="text-gray-400">None</span>
                        @endif
                    </td>
                    <td class="p-3 text-center">
                        <button
                            @click="editModal = true; editing = {{ json_encode($c) }}"
                            class="bg-blue-900 hover:bg-blue-800 text-white px-3 py-1.5 rounded text-xs font-semibold">
                            Edit
                        </button>
                    </td>
                </tr>
                @empty
                <tr><td colspan="10" class="p-6 text-center text-gray-400">No active customers.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>