<div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full">
    <div class="px-6 py-4 border-b border-gray-100 bg-green-50 flex items-center justify-between flex-wrap gap-3">
        <h3 class="text-xl font-bold text-gray-800">Active Customers</h3>
        <div class="flex items-center gap-3">
            <span class="text-sm font-semibold text-green-700">{{ $activeCustomers->count() }} customers</span>
            <button @click="refreshTables()" :disabled="refreshing"
                    class="text-xs font-semibold text-green-800 border border-green-300 hover:bg-green-100 rounded px-2 py-1 disabled:opacity-50">
                <span x-show="!refreshing">⟳ Refresh</span>
                <span x-show="refreshing">Refreshing...</span>
            </button>
            <a href="{{ route('admin.customer-setup.report', ['type' => 'active']) }}" target="_blank"
               class="bg-green-600 hover:bg-green-700 text-white text-xs font-bold px-3 py-1.5 rounded-lg shadow-sm transition inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Generate Active Report
            </a>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs border-collapse">
            <thead class="bg-gray-50 border-b border-gray-200 font-bold text-gray-600 text-[11px] uppercase tracking-wider">
                <tr>
                    <th class="p-3">Name</th>
                    <th class="p-3">Phone</th>
                    <th class="p-3">Email</th>
                    <th class="p-3">NIC</th>
                    <th class="p-3">Vehicles / GPS</th>
                    <th class="p-3">Source</th>
                    <th class="p-3 text-center">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($activeCustomers as $c)
                <tr class="hover:bg-gray-50 transition">
                    <td class="p-3 font-bold text-blue-950">{{ $c->full_name }}</td>
                    <td class="p-3 font-mono text-[11px]">{{ $c->phone_number }}</td>
                    <td class="p-3 text-slate-600 max-w-[160px] truncate">{{ $c->email }}</td>
                    <td class="p-3 text-slate-600">{{ $c->nic_number ?: '—' }}</td>

                    {{-- Vehicles with GPS status --}}
                    <td class="p-3 min-w-[200px]">
                        @if($c->vehicles->isNotEmpty())
                            <div class="space-y-1">
                                @foreach($c->vehicles as $v)
                                    <div class="flex items-center gap-1.5 text-[11px]">
                                        <span class="font-bold text-slate-800">{{ $v->vehicle_number ?? 'No plate' }}</span>
                                        <span class="text-slate-500">{{ $v->make }} {{ $v->model }}</span>
                                        @if($v->has_gps_device)
                                            <span class="px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-200 text-[10px] font-bold">
                                                GPS · {{ $v->imei }}
                                            </span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <span class="text-slate-400 italic text-[11px]">No vehicles</span>
                        @endif
                    </td>

                    {{-- Dealer source --}}
                    <td class="p-3">
                        @if(isset($c->dealerLead) && $c->dealerLead)
                            <div class="text-[11px]">
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-cyan-50 text-cyan-700 border border-cyan-200 text-[10px] font-bold">
                                    Via Dealer
                                </span>
                                <div class="mt-0.5 text-slate-600 font-bold">{{ $c->dealerLead->dealer->full_name ?? '—' }}</div>
                                <div class="text-slate-400 text-[10px]">
                                    {{ ucfirst(str_replace('_', ' ', $c->dealerLead->dealer->region ?? '')) }}
                                </div>
                            </div>
                        @else
                            <span class="text-slate-400 text-[11px] italic">Direct / Unknown</span>
                        @endif
                    </td>

                    <td class="p-3 text-center">
                        @include('admin.customer._status_toggle', ['c' => $c])
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="p-6 text-center text-gray-400">No active customers.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>