@extends('layouts.admin')

@section('content')
<div class="p-4 sm:p-6 bg-gray-50 min-h-screen">

    {{-- Page Header --}}
    <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4 mb-6">
        <div>
            <h2 class="text-xl sm:text-2xl font-bold text-gray-800">Device Command Center</h2>
            <p class="text-xs sm:text-sm text-gray-500 mt-0.5">Send real-time commands to connected GPS devices</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if($gatewayOnline)
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-green-50 border border-green-200 text-green-700 rounded-full text-xs font-bold">
                    <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                    Gateway Online
                </span>
            @else
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-red-50 border border-red-200 text-red-700 rounded-full text-xs font-bold">
                    <span class="w-2 h-2 bg-red-500 rounded-full"></span>
                    Gateway Offline
                </span>
            @endif
            <span class="inline-flex items-center px-3 py-1.5 bg-blue-50 border border-blue-200 text-blue-700 rounded-full text-xs font-bold">
                {{ $connectedDevices->count() }} Connected
            </span>
        </div>
    </div>

    {{-- Alert if gateway is offline --}}
    @unless($gatewayOnline)
    <div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-700 rounded-xl text-xs sm:text-sm font-medium flex items-center gap-2">
        <span>⚠️</span>
        <span>The gateway command API is currently unreachable. Commands cannot be sent until the gateway is restored.</span>
    </div>
    @endunless

    {{-- Flash Message --}}
    <div id="flash-message" class="hidden mb-4 p-4 rounded-xl font-medium text-sm"></div>

    {{-- Mobile View: Cards Layout (Visible only on mobile) --}}
    <div class="block md:hidden space-y-3 mb-6">
        @forelse($vehicles as $vehicle)
        <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 flex flex-col justify-between space-y-3" id="mobile-row-{{ $vehicle->imei }}">
            <div class="flex justify-between items-start">
                <div>
                    <h3 class="font-bold text-blue-600 text-base">{{ $vehicle->vehicle_number }}</h3>
                    <p class="font-mono text-xs text-gray-400 mt-0.5">IMEI: {{ $vehicle->imei }}</p>
                </div>
                <div>
                    @if($vehicle->is_online)
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-green-50 text-green-700 border border-green-200 text-xs font-bold">
                            <span class="w-1.5 h-1.5 bg-green-500 rounded-full animate-pulse"></span>
                            Online
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-gray-50 text-gray-500 border border-gray-200 text-xs font-bold">
                            <span class="w-1.5 h-1.5 bg-gray-400 rounded-full"></span>
                            Offline
                        </span>
                    @endif
                </div>
            </div>

            <div class="flex justify-between items-center pt-2 border-t border-gray-50 text-xs text-gray-500">
                <div>
                    <span class="text-gray-400">Customer:</span> 
                    <span class="font-medium text-gray-700">{{ $vehicle->customer_name ?? 'N/A' }}</span>
                </div>
                <div>
                    {{ $vehicle->last_seen ? \Carbon\Carbon::parse($vehicle->last_seen)->diffForHumans() : '—' }}
                </div>
            </div>

            <button
                onclick="openCommandPanel('{{ $vehicle->imei }}', '{{ $vehicle->vehicle_number }}')"
                @unless($vehicle->is_online) disabled @endunless
                class="w-full py-2.5 text-xs font-semibold rounded-lg transition text-center
                    {{ $vehicle->is_online
                        ? 'bg-blue-600 text-white hover:bg-blue-700 active:scale-[0.98]'
                        : 'bg-gray-100 text-gray-400 cursor-not-allowed' }}">
                Send Command
            </button>
        </div>
        @empty
        <div class="bg-white p-6 rounded-xl text-center text-gray-400 font-medium text-sm border border-gray-100">
            No GPS devices found. Assign devices to vehicles first.
        </div>
        @endforelse
    </div>

    {{-- Desktop View: Table Layout (Hidden on mobile) --}}
    <div class="hidden md:block bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full whitespace-nowrap">
                <thead class="bg-gray-50 text-gray-500 font-semibold text-left text-xs uppercase tracking-wider border-b border-gray-100">
                    <tr>
                        <th class="px-6 py-4">Vehicle</th>
                        <th class="px-6 py-4">IMEI</th>
                        <th class="px-6 py-4">Customer</th>
                        <th class="px-6 py-4">Status</th>
                        <th class="px-6 py-4">Last Seen</th>
                        <th class="px-6 py-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="text-sm divide-y divide-gray-100 text-gray-700">
                    @forelse($vehicles as $vehicle)
                    <tr class="hover:bg-gray-50/50 transition" id="row-{{ $vehicle->imei }}">
                        <td class="px-6 py-4 font-bold text-blue-600">{{ $vehicle->vehicle_number }}</td>
                        <td class="px-6 py-4 font-mono text-xs text-gray-500">{{ $vehicle->imei }}</td>
                        <td class="px-6 py-4">{{ $vehicle->customer_name ?? 'N/A' }}</td>
                        <td class="px-6 py-4">
                            @if($vehicle->is_online)
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-green-50 text-green-700 border border-green-200 text-xs font-bold">
                                    <span class="w-1.5 h-1.5 bg-green-500 rounded-full animate-pulse"></span>
                                    Online
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-gray-50 text-gray-500 border border-gray-200 text-xs font-bold">
                                    <span class="w-1.5 h-1.5 bg-gray-400 rounded-full"></span>
                                    Offline
                                </span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-gray-500 text-xs">
                            {{ $vehicle->last_seen ? \Carbon\Carbon::parse($vehicle->last_seen)->diffForHumans() : '—' }}
                        </td>
                        <td class="px-6 py-4 text-right">
                            <button
                                onclick="openCommandPanel('{{ $vehicle->imei }}', '{{ $vehicle->vehicle_number }}')"
                                @unless($vehicle->is_online) disabled @endunless
                                class="px-4 py-1.5 text-xs font-semibold rounded-lg transition
                                    {{ $vehicle->is_online
                                        ? 'bg-blue-600 text-white hover:bg-blue-700 cursor-pointer active:scale-95'
                                        : 'bg-gray-100 text-gray-400 cursor-not-allowed' }}">
                                Send Command
                            </button>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-gray-400 font-medium">
                            No GPS devices found. Assign devices to vehicles first.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- Command Panel Modal (Bottom Sheet on Mobile, Centered on Desktop) --}}
<div id="command-modal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-end sm:items-center justify-center p-0 sm:p-4 transition-opacity">
    <div class="bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto transform transition-all">

        {{-- Drag Handle Indicator for Mobile --}}
        <div class="sm:hidden w-12 h-1.5 bg-gray-300 rounded-full mx-auto my-2.5"></div>

        <div class="flex justify-between items-center px-5 py-4 border-b border-gray-100 sticky top-0 bg-white z-10">
            <div>
                <h3 class="text-base sm:text-lg font-bold text-gray-800">Send Command</h3>
                <p class="text-xs text-gray-500 mt-0.5 font-mono" id="modal-vehicle-label">—</p>
            </div>
            <button onclick="closeCommandPanel()" class="p-1.5 text-gray-400 hover:text-gray-600 rounded-lg hover:bg-gray-100 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="p-5">
            <input type="hidden" id="modal-imei" value="">

            {{-- Quick Commands --}}
            <div class="mb-5">
                <p class="text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-2.5">Quick Commands</p>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                    @foreach([
                        ['where',    'Where',    'bg-blue-50 text-blue-700 border-blue-200',     '📍'],
                        ['status',   'Status',   'bg-green-50 text-green-700 border-green-200',   '📊'],
                        ['relay_on', 'Relay On', 'bg-emerald-50 text-emerald-700 border-emerald-200', '🔓'],
                        ['reset',    'Reset',    'bg-orange-50 text-orange-700 border-orange-200', '🔄'],
                        ['version',  'Version',  'bg-purple-50 text-purple-700 border-purple-200', 'ℹ️'],
                        ['params',   'Params',   'bg-gray-50 text-gray-700 border-gray-200',      '⚙️'],
                    ] as [$cmd, $label, $classes, $icon])
                    <button
                        onclick="sendQuickCommand('{{ $cmd }}')"
                        class="px-3 py-2.5 rounded-xl border text-xs font-semibold {{ $classes }} hover:opacity-80 active:scale-95 transition flex items-center justify-center sm:justify-start gap-2">
                        <span>{{ $icon }}</span>
                        <span>{{ $label }}</span>
                    </button>
                    @endforeach
                </div>
            </div>

            {{-- Custom Command --}}
            <div class="border-t border-gray-100 pt-5">
                <p class="text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-2.5">Custom Command</p>
                <div class="flex flex-col sm:flex-row gap-2">
                    <select id="custom-command" class="flex-1 border border-gray-200 rounded-xl px-3 py-2.5 text-xs sm:text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition bg-gray-50/50">
                        <option value="">Select a command...</option>
                        <optgroup label="Query">
                            <option value="where">where — Current position</option>
                            <option value="status">status — Device status</option>
                            <option value="version">version — Firmware version</option>
                            <option value="imei">imei — Device IMEI</option>
                            <option value="params">params — All parameters</option>
                            <option value="gprsset">gprsset — GPRS settings</option>
                            <option value="timer_query">timer_query — Upload interval</option>
                            <option value="speed_query">speed_query — Overspeed settings</option>
                            <option value="sos_query">sos_query — SOS numbers</option>
                            <option value="fence_query">fence_query — Geofence settings</option>
                        </optgroup>
                        <optgroup label="Control">
                            <option value="relay_on">relay_on — Restore engine relay ✅</option>
                            <option value="reset">reset — Reboot device</option>
                            <option value="sos_delete">sos_delete — Clear SOS numbers</option>
                        </optgroup>
                        <optgroup label="Configuration">
                            <option value="timer">timer — Set upload interval</option>
                            <option value="speed_alarm">speed_alarm — Overspeed alarm</option>
                            <option value="moving_alarm">moving_alarm — Movement alarm</option>
                            <option value="batalm">batalm — Low battery alarm</option>
                            <option value="poweralm">poweralm — Power cut alarm</option>
                        </optgroup>
                    </select>
                    <button
                        onclick="sendCustomCommand()"
                        class="w-full sm:w-auto px-5 py-2.5 bg-blue-600 text-white text-xs sm:text-sm font-semibold rounded-xl hover:bg-blue-700 active:scale-95 transition">
                        Send
                    </button>
                </div>
            </div>

            {{-- Response Area --}}
            <div id="command-response" class="hidden mt-4 p-3.5 rounded-xl text-xs sm:text-sm font-medium"></div>

            {{-- Sending Indicator --}}
            <div id="sending-indicator" class="hidden mt-4 flex items-center justify-center sm:justify-start gap-2 text-xs sm:text-sm text-gray-500 py-1">
                <svg class="animate-spin w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span>Sending command to device...</span>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    const CSRF_TOKEN = '{{ csrf_token() }}';
    const SEND_URL   = '{{ route("admin.vehicles.device-commands.send") }}';

    function openCommandPanel(imei, vehicleNumber) {
        document.getElementById('modal-imei').value = imei;
        document.getElementById('modal-vehicle-label').textContent = vehicleNumber + ' • IMEI: ' + imei;
        document.getElementById('command-response').classList.add('hidden');
        document.getElementById('command-modal').classList.remove('hidden');
        document.body.classList.add('overflow-hidden'); // Prevent background scrolling
    }

    function closeCommandPanel() {
        document.getElementById('command-modal').classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }

    function sendQuickCommand(command) {
        doSendCommand(command, {});
    }

    function sendCustomCommand() {
        const command = document.getElementById('custom-command').value;
        if (!command) { showResponse('Please select a command.', false); return; }
        doSendCommand(command, {});
    }

    async function doSendCommand(command, params) {
        const imei      = document.getElementById('modal-imei').value;
        const indicator = document.getElementById('sending-indicator');
        const responseEl = document.getElementById('command-response');

        indicator.classList.remove('hidden');
        responseEl.classList.add('hidden');

        try {
            const res = await fetch(SEND_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF_TOKEN,
                },
                body: JSON.stringify({ imei, command, params }),
            });
            const data = await res.json();
            showResponse(data.message, data.success);
        } catch (e) {
            showResponse('Network error. Please try again.', false);
        } finally {
            indicator.classList.add('hidden');
        }
    }

    function showResponse(message, success) {
        const el = document.getElementById('command-response');
        el.textContent = message;
        el.className = 'mt-4 p-3.5 rounded-xl text-xs sm:text-sm font-medium ' +
            (success
                ? 'bg-green-50 border border-green-200 text-green-700'
                : 'bg-red-50 border border-red-200 text-red-700');
    }

    document.getElementById('command-modal').addEventListener('click', function(e) {
        if (e.target === this) closeCommandPanel();
    });
</script>
@endpush
@endsection