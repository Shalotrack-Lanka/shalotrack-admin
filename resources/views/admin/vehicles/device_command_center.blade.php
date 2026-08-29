@extends('layouts.admin')

@section('content')
<div class="p-6 bg-gray-50 min-h-screen">

    {{-- Page Header --}}
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-2xl font-bold text-gray-800">Device Command Center</h2>
            <p class="text-sm text-gray-500 mt-1">Send real-time commands to connected GPS devices</p>
        </div>
        <div class="flex items-center gap-2">
            @if($gatewayOnline)
                <span class="flex items-center gap-1.5 px-3 py-1.5 bg-green-50 border border-green-200 text-green-700 rounded-full text-xs font-bold">
                    <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                    Gateway Online
                </span>
            @else
                <span class="flex items-center gap-1.5 px-3 py-1.5 bg-red-50 border border-red-200 text-red-700 rounded-full text-xs font-bold">
                    <span class="w-2 h-2 bg-red-500 rounded-full"></span>
                    Gateway Offline
                </span>
            @endif
            <span class="px-3 py-1.5 bg-blue-50 border border-blue-200 text-blue-700 rounded-full text-xs font-bold">
                {{ $connectedDevices->count() }} Connected
            </span>
        </div>
    </div>

    {{-- Alert if gateway is offline --}}
    @unless($gatewayOnline)
    <div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg font-medium">
        ⚠️ The gateway command API is currently unreachable. Commands cannot be sent until the gateway is restored.
    </div>
    @endunless

    {{-- Flash Message --}}
    <div id="flash-message" class="hidden mb-4 p-4 rounded-lg font-medium text-sm"></div>

    {{-- Device Table --}}
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full whitespace-nowrap">
                <thead class="bg-gray-100 text-gray-600 font-semibold text-left text-sm">
                    <tr>
                        <th class="px-6 py-4">Vehicle</th>
                        <th class="px-6 py-4">IMEI</th>
                        <th class="px-6 py-4">Customer</th>
                        <th class="px-6 py-4">Status</th>
                        <th class="px-6 py-4">Last Seen</th>
                        <th class="px-6 py-4">Actions</th>
                    </tr>
                </thead>
                <tbody class="text-sm text-gray-700">
                    @forelse($vehicles as $vehicle)
                    <tr class="border-b hover:bg-gray-50" id="row-{{ $vehicle->imei }}">
                        <td class="px-6 py-4 font-bold text-blue-600">{{ $vehicle->vehicle_number }}</td>
                        <td class="px-6 py-4 font-mono text-xs text-gray-500">{{ $vehicle->imei }}</td>
                        <td class="px-6 py-4">{{ $vehicle->customer_name ?? 'N/A' }}</td>
                        <td class="px-6 py-4">
                            @if($vehicle->is_online)
                                <span class="flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-green-50 text-green-700 border border-green-200 text-xs font-bold w-fit">
                                    <span class="w-1.5 h-1.5 bg-green-500 rounded-full animate-pulse"></span>
                                    Online
                                </span>
                            @else
                                <span class="flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-gray-50 text-gray-500 border border-gray-200 text-xs font-bold w-fit">
                                    <span class="w-1.5 h-1.5 bg-gray-400 rounded-full"></span>
                                    Offline
                                </span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-gray-500 text-xs">
                            {{ $vehicle->last_seen ? \Carbon\Carbon::parse($vehicle->last_seen)->diffForHumans() : '—' }}
                        </td>
                        <td class="px-6 py-4">
                            <button
                                onclick="openCommandPanel('{{ $vehicle->imei }}', '{{ $vehicle->vehicle_number }}')"
                                @unless($vehicle->is_online) disabled @endunless
                                class="px-3 py-1.5 text-xs font-semibold rounded-md
                                    {{ $vehicle->is_online
                                        ? 'bg-blue-600 text-white hover:bg-blue-700 cursor-pointer'
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

{{-- Command Panel Modal --}}
<div id="command-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg">

        <div class="flex justify-between items-center p-6 border-b">
            <div>
                <h3 class="text-lg font-bold text-gray-800">Send Command</h3>
                <p class="text-sm text-gray-500 mt-0.5" id="modal-vehicle-label">—</p>
            </div>
            <button onclick="closeCommandPanel()" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="p-6">
            <input type="hidden" id="modal-imei" value="">

            {{-- Quick Commands --}}
            <div class="mb-6">
                <p class="text-xs font-bold text-gray-500 uppercase mb-3">Quick Commands</p>
                <div class="grid grid-cols-3 gap-2">
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
                        class="px-3 py-2 rounded-lg border text-xs font-semibold {{ $classes }} hover:opacity-80 transition flex items-center gap-1.5">
                        <span>{{ $icon }}</span>
                        <span>{{ $label }}</span>
                    </button>
                    @endforeach
                </div>
            </div>

            {{-- Custom Command --}}
            <div class="border-t pt-5">
                <p class="text-xs font-bold text-gray-500 uppercase mb-3">Custom Command</p>
                <div class="flex gap-2">
                    <select id="custom-command" class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500">
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
                        class="px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition">
                        Send
                    </button>
                </div>
            </div>

            {{-- Response Area --}}
            <div id="command-response" class="hidden mt-4 p-3 rounded-lg text-sm font-medium"></div>

            {{-- Sending Indicator --}}
            <div id="sending-indicator" class="hidden mt-4 flex items-center gap-2 text-sm text-gray-500">
                <svg class="animate-spin w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                Sending command to device...
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
        document.getElementById('modal-vehicle-label').textContent = vehicleNumber + ' — IMEI: ' + imei;
        document.getElementById('command-response').classList.add('hidden');
        document.getElementById('command-modal').classList.remove('hidden');
    }

    function closeCommandPanel() {
        document.getElementById('command-modal').classList.add('hidden');
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
        el.className = 'mt-4 p-3 rounded-lg text-sm font-medium ' +
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