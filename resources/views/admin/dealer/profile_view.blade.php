@extends('layouts.admin')

@section('content')
<div class="p-6 bg-gray-50 min-h-screen">

    @if(session('success'))
        <div class="mb-5 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-700 text-sm px-4 py-3 rounded shadow-sm">
            {{ session('success') }}
        </div>
    @endif

    <!-- Header -->
    <div class="bg-white p-6 border border-gray-100 rounded-2xl shadow-sm mb-6">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-full bg-cyan-600 text-white flex items-center justify-center text-xl font-bold">
                    {{ strtoupper(substr($dealer->full_name ?? '?', 0, 1)) }}
                </div>
                <div>
                    <h1 class="text-xl font-bold text-gray-800">{{ $dealer->full_name }}</h1>
                    <p class="text-xs text-gray-500">{{ ucfirst($dealer->dealer_status ?? '-') }} &middot; {{ ucfirst($dealer->region ?? '-') }}</p>
                </div>
                @if($dealer->status === 'active')
                    <span class="px-2.5 py-1 rounded-full bg-green-50 text-green-700 border border-green-200 text-xs font-bold">Active</span>
                @else
                    <span class="px-2.5 py-1 rounded-full bg-gray-100 text-gray-500 border border-gray-200 text-xs font-bold">Archived</span>
                @endif
            </div>
            <div class="flex gap-2">
                <form action="{{ route('admin.dealer.toggle-status', $dealer->id) }}" method="POST"
                      onsubmit="return confirm('{{ $dealer->status === 'active' ? 'Deactivate' : 'Activate' }} this dealer?');">
                    @csrf
                    @method('PATCH')
                    <button type="submit"
                        class="px-4 py-2 rounded-lg text-sm font-semibold shadow-sm
                        {{ $dealer->status === 'active' ? 'bg-red-50 text-red-700 border border-red-200 hover:bg-red-100' : 'bg-green-50 text-green-700 border border-green-200 hover:bg-green-100' }}">
                        {{ $dealer->status === 'active' ? 'Deactivate Dealer' : 'Activate Dealer' }}
                    </button>
                </form>
                <span class="px-4 py-2 rounded-lg text-sm font-semibold bg-gray-100 text-gray-400 cursor-not-allowed" title="Full edit form not built yet">
                    Edit Dealer
                </span>
            </div>
        </div>
    </div>

    <!-- Performance Summary -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm">
            <div class="text-xs text-gray-400 uppercase font-bold mb-1">Assigned Devices</div>
            <div class="text-2xl font-bold text-gray-800">{{ $assignedDevices->count() }}</div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm">
            <div class="text-xs text-gray-400 uppercase font-bold mb-1">Total Stock Transferred</div>
            <div class="text-2xl font-bold text-gray-800">{{ $totalDevicesTransferred }}</div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm opacity-50">
            <div class="text-xs text-gray-400 uppercase font-bold mb-1">Registered Customers</div>
            <div class="text-sm text-gray-400 mt-1">Not linked yet</div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm opacity-50">
            <div class="text-xs text-gray-400 uppercase font-bold mb-1">Active Vehicles</div>
            <div class="text-sm text-gray-400 mt-1">Not linked yet</div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Left: Contact + Recent Activity -->
        <div class="lg:col-span-1 space-y-6">
            <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-4">Contact Details</h2>
                <div class="space-y-3 text-sm">
                    <div>
                        <div class="text-xs text-gray-400 uppercase font-bold">Email</div>
                        <div class="text-gray-700">{{ $dealer->contact_email ?? '-' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400 uppercase font-bold">Region</div>
                        <div class="text-gray-700">{{ ucfirst($dealer->region ?? '-') }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400 uppercase font-bold">Pin Code</div>
                        <div class="text-gray-700">{{ $dealer->pin_code ?? '-' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400 uppercase font-bold">Login ID</div>
                        <div class="text-gray-700">{{ $dealer->login_id ?? '-' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400 uppercase font-bold">Network</div>
                        <div class="text-gray-700">{{ $dealer->network ?? '-' }}</div>
                    </div>
                </div>
            </div>

            <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-4">Recent Activity</h2>
                @forelse($recentActivity as $item)
                    <div class="text-xs text-gray-600 border-l-2 border-cyan-200 pl-3 pb-3">
                        <div class="text-gray-400">{{ $item['date']->format('d M Y, h:i A') }}</div>
                        <div>{{ $item['text'] }}</div>
                    </div>
                @empty
                    <p class="text-xs text-gray-400">No activity yet.</p>
                @endforelse
            </div>

            <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-2">Reports</h2>
                <p class="text-xs text-gray-400 mb-3">Dealer-specific filtering not built yet — these link to the general reports.</p>
                <a href="{{ route('admin.stock-in-report') }}" class="block text-xs text-cyan-600 hover:underline mb-1">Stock In Report</a>
                <a href="{{ route('admin.credit-invoice-report') }}" class="block text-xs text-cyan-600 hover:underline">Credit Invoice Report</a>
            </div>
        </div>

        <!-- Right: Devices, Transfers, Customers placeholder -->
        <div class="lg:col-span-2 space-y-6">

            <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-4">Assigned Devices ({{ $assignedDevices->count() }})</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-gray-50 text-gray-500 uppercase">
                            <tr>
                                <th class="p-2">IMEI</th>
                                <th class="p-2">Model</th>
                                <th class="p-2">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($assignedDevices as $device)
                                <tr>
                                    <td class="p-2">{{ $device->imei_number }}</td>
                                    <td class="p-2">{{ $device->device_category }}</td>
                                    <td class="p-2">{{ $device->status }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="p-4 text-center text-gray-400">No devices assigned.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm">
                <h2 class="font-bold text-gray-800 text-sm mb-4">Stock Transfer History</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-gray-50 text-gray-500 uppercase">
                            <tr>
                                <th class="p-2">Date</th>
                                <th class="p-2">Device Category / Type</th>
                                <th class="p-2">Quantity</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($transfers as $transfer)
                                <tr>
                                    <td class="p-2">{{ $transfer->created_at->format('d M Y') }}</td>
                                    <td class="p-2">{{ $transfer->device_category }}</td>
                                    <td class="p-2">{{ $transfer->quantity }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="p-4 text-center text-gray-400">No stock transfers yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white p-6 rounded-2xl border border-dashed border-gray-300 shadow-sm">
                <h2 class="font-bold text-gray-500 text-sm mb-2">Registered Customers & Vehicles</h2>
                <p class="text-xs text-gray-400">
                    Not available yet — there's currently no link between Dealers and Customers/Vehicles
                    in the system. Customer data lives on the ShaloTrack API's database, which doesn't
                    have a Dealer field on any Customer or Vehicle record. This needs a schema change
                    on the API side before this section can show real data.
                </p>
            </div>

        </div>
    </div>

</div>
@endsection