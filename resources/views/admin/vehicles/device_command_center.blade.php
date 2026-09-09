@extends('layouts.dealer')

@section('title', 'Device Command Center')

@section('content')
<div class="max-w-7xl mx-auto space-y-5">

    {{-- Header --}}
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <h1 class="text-2xl font-black text-blue-950">Device Command Center</h1>
            <p class="text-xs text-slate-500 mt-0.5">Send real-time commands to your connected GPS devices.</p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            @if($gatewayOnline)
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-full text-xs font-bold">
                    <span class="w-2 h-2 bg-emerald-500 rounded-full animate-pulse"></span>
                    Gateway Online
                </span>
            @else
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-red-50 border border-red-200 text-red-700 rounded-full text-xs font-bold">
                    <span class="w-2 h-2 bg-red-500 rounded-full animate-pulse"></span>
                    Gateway Offline
                </span>
            @endif
            <span class="inline-flex items-center px-3 py-1.5 bg-blue-50 border border-blue-200 text-blue-700 rounded-full text-xs font-bold">
                {{ $connectedDevices->count() }} Connected
            </span>
        </div>
    </div>

    {{-- Gateway offline banner --}}
    @unless($gatewayOnline)
        <div class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-2xl text-xs font-semibold flex items-center gap-3">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            The gateway command API is currently unreachable. Commands cannot be sent until the gateway is restored.
        </div>
    @endunless

    {{-- Mobile Cards (visible below md) --}}
    <div class="block md:hidden space-y-3">
        @forelse($vehicles as $vehicle)
        <div class="bg-white p-4 rounded-2xl shadow-sm border border-slate-100 space-y-3">
            <div class="flex justify-between items-start">
                <div>
                    <h3 class="font-black text-blue-950 text-base">{{ $vehicle->vehicle_number ?? 'No Plate' }}</h3>
                    <p class="font-mono text-[11px] text-slate-400 mt-0.5">IMEI: {{ $vehicle->imei }}</p>
                </div>
                @if($vehicle->is_online)
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-[10px] font-bold">
                        <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                        Online
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-slate-100 text-slate-500 border border-slate-200 text-[10px] font-bold">
                        <span class="w-1.5 h-1.5 bg-slate-400 rounded-full"></span>
                        Offline
                    </span>
                @endif
            </div>
            <div class="flex justify-between items-center pt-2 border-t border-slate-50 text-xs text-slate-500">
                <span><span class="text-slate-400">Customer:</span> <span class="font-bold text-slate-700">{{ $vehicle->customer_name ?? '—' }}</span></span>
                <span>{{ $vehicle->last_seen ? \Carbon\Carbon::parse($vehicle->last_seen)->diffForHumans() : '—' }}</span>
            </div>
            <div class="flex gap-2">
                <button
                    onclick="openCommandPanel('{{ $vehicle->imei }}', '{{ addslashes($vehicle->vehicle_number ?? 'Device') }}')"
                    @unless($vehicle->is_online) disabled @endunless
                    class="flex-1 py-2.5 text-xs font-bold rounded-xl transition
                        {{ $vehicle->is_online ? 'bg-blue-600 text-white hover:bg-blue-700 active:scale-95' : 'bg-slate-100 text-slate-400 cursor-not-allowed' }}">
                    Send Command
                </button>
                <button
                    onclick="openHistoryPanel('{{ $vehicle->vehicle_id }}', '{{ addslashes($vehicle->vehicle_number ?? 'Device') }}')"
                    class="flex-1 py-2.5 text-xs font-bold rounded-xl bg-slate-100 text-slate-700 hover:bg-slate-200 active:scale-95 transition">
                    History
                </button>
            </div>
        </div>
        @empty
        <div class="bg-white p-8 rounded-2xl border border-slate-100 text-center text-slate-400 text-sm font-medium">
            No registered GPS devices found for your customers.
        </div>
        @endforelse
    </div>

    {{-- Desktop Table (visible from md up) --}}
    <div class="hidden md:block bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs whitespace-nowrap">
                <thead class="bg-slate-50 text-slate-500 uppercase font-extrabold tracking-wider border-b border-slate-200 text-[11px]">
                    <tr>
                        <th class="px-5 py-3.5">Vehicle</th>
                        <th class="px-5 py-3.5">IMEI</th>
                        <th class="px-5 py-3.5">Customer</th>
                        <th class="px-5 py-3.5">Status</th>
                        <th class="px-5 py-3.5">Last Seen</th>
                        <th class="px-5 py-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white text-sm text-slate-700">
                    @forelse($vehicles as $vehicle)
                    <tr class="hover:bg-slate-50/50 transition">
                        <td class="px-5 py-4 font-black text-blue-950">{{ $vehicle->vehicle_number ?? 'No Plate' }}</td>
                        <td class="px-5 py-4 font-mono text-xs text-slate-500">{{ $vehicle->imei }}</td>
                        <td class="px-5 py-4 font-medium">{{ $vehicle->customer_name ?? '—' }}</td>
                        <td class="px-5 py-4">
                            @if($vehicle->is_online)
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-[10px] font-bold">
                                    <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                                    Online
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-slate-100 text-slate-500 border border-slate-200 text-[10px] font-bold">
                                    <span class="w-1.5 h-1.5 bg-slate-400 rounded-full"></span>
                                    Offline
                                </span>
                            @endif
                        </td>
                        <td class="px-5 py-4 text-slate-500 text-xs">
                            {{ $vehicle->last_seen ? \Carbon\Carbon::parse($vehicle->last_seen)->diffForHumans() : '—' }}
                        </td>
                        <td class="px-5 py-4 text-right">
                            <div class="flex justify-end gap-2">
                                <button
                                    onclick="openCommandPanel('{{ $vehicle->imei }}', '{{ addslashes($vehicle->vehicle_number ?? 'Device') }}')"
                                    @unless($vehicle->is_online) disabled @endunless
                                    class="px-4 py-1.5 text-xs font-bold rounded-lg transition
                                        {{ $vehicle->is_online ? 'bg-blue-600 text-white hover:bg-blue-700 cursor-pointer active:scale-95' : 'bg-slate-100 text-slate-400 cursor-not-allowed' }}">
                                    Send Command
                                </button>
                                <button
                                    onclick="openHistoryPanel('{{ $vehicle->vehicle_id }}', '{{ addslashes($vehicle->vehicle_number ?? 'Device') }}')"
                                    class="px-4 py-1.5 text-xs font-bold rounded-lg bg-slate-100 text-slate-700 hover:bg-slate-200 cursor-pointer active:scale-95 transition">
                                    History
                                </button>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-5 py-10 text-center text-slate-400 font-medium">
                            No registered GPS devices found for your customers.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>

{{-- ── Send Command Modal ───────────────────────────────────────────────────── --}}
<div id="command-modal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-end sm:items-center justify-center p-0 sm:p-4">
    <div class="bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">

        {{-- Mobile drag handle --}}
        <div class="sm:hidden w-12 h-1.5 bg-slate-300 rounded-full mx-auto my-3"></div>

        <div class="flex justify-between items-center px-5 py-4 border-b sticky top-0 bg-white z-10">
            <div>
                <h3 class="text-base sm:text-lg font-black text-blue-950">Send Command</h3>
                <p class="text-xs text-slate-500 mt-0.5" id="modal-vehicle-label">—</p>
            </div>
            <button onclick="closeCommandPanel()"
                    class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="p-5">
            <input type="hidden" id="modal-imei" value="">

            {{-- Quick command grid --}}
            <div class="mb-5">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2.5">Quick Commands</p>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                    <button onclick="sendQuickCommand('where')"   class="px-3 py-2.5 rounded-xl border text-xs font-bold bg-blue-50   text-blue-700   border-blue-200   hover:opacity-80 active:scale-95 transition flex items-center justify-center gap-1.5"><span>📍</span><span>Where</span></button>
                    <button onclick="sendQuickCommand('status')"  class="px-3 py-2.5 rounded-xl border text-xs font-bold bg-emerald-50 text-emerald-700 border-emerald-200 hover:opacity-80 active:scale-95 transition flex items-center justify-center gap-1.5"><span>📊</span><span>Status</span></button>
                    <button onclick="sendQuickCommand('relay_on')" class="px-3 py-2.5 rounded-xl border text-xs font-bold bg-green-50  text-green-700  border-green-200  hover:opacity-80 active:scale-95 transition flex items-center justify-center gap-1.5"><span>🔓</span><span>Relay On</span></button>
                    <button onclick="sendQuickCommand('reset')"   class="px-3 py-2.5 rounded-xl border text-xs font-bold bg-orange-50 text-orange-700 border-orange-200 hover:opacity-80 active:scale-95 transition flex items-center justify-center gap-1.5"><span>🔄</span><span>Reset</span></button>
                    <button onclick="sendQuickCommand('version')" class="px-3 py-2.5 rounded-xl border text-xs font-bold bg-purple-50 text-purple-700 border-purple-200 hover:opacity-80 active:scale-95 transition flex items-center justify-center gap-1.5"><span>ℹ️</span><span>Version</span></button>
                    <button onclick="sendQuickCommand('params')"  class="px-3 py-2.5 rounded-xl border text-xs font-bold bg-slate-50  text-slate-700  border-slate-200  hover:opacity-80 active:scale-95 transition flex items-center justify-center gap-1.5"><span>⚙️</span><span>Params</span></button>
                </div>
            </div>

            {{-- Custom command selector --}}
            <div class="border-t border-slate-100 pt-5">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2.5">Custom Command</p>
                <div class="flex flex-col sm:flex-row gap-2">
                    <select id="custom-command"
                            class="flex-1 border border-slate-200 rounded-xl px-3 py-2.5 text-xs focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none bg-slate-50/50">
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
                    <button onclick="sendCustomCommand()"
                            class="w-full sm:w-auto px-5 py-2.5 bg-blue-600 text-white text-xs font-bold rounded-xl hover:bg-blue-700 active:scale-95 transition">
                        Send
                    </button>
                </div>
            </div>

            {{-- Response area --}}
            <div id="command-response" class="hidden mt-4 p-3.5 rounded-xl text-xs font-semibold"></div>

            {{-- Sending indicator --}}
            <div id="sending-indicator" class="hidden mt-4 flex items-center gap-2 text-xs text-slate-500">
                <svg class="animate-spin w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                Sending command to device...
            </div>
        </div>
    </div>
</div>

{{-- ── Command History Modal ────────────────────────────────────────────────── --}}
<div id="history-modal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-end sm:items-center justify-center p-0 sm:p-4">
    <div class="bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-hidden flex flex-col">

        <div class="sm:hidden w-12 h-1.5 bg-slate-300 rounded-full mx-auto my-3"></div>

        <div class="flex justify-between items-center px-5 py-4 border-b sticky top-0 bg-white z-10">
            <div>
                <h3 class="text-base sm:text-lg font-black text-blue-950">Command History</h3>
                <p class="text-xs text-slate-500 mt-0.5" id="history-vehicle-label">—</p>
            </div>
            <button onclick="closeHistoryPanel()"
                    class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="p-5 overflow-y-auto flex-1">
            <div id="history-loading" class="flex items-center gap-2 text-xs text-slate-500">
                <svg class="animate-spin w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                Loading command history...
            </div>
            <div id="history-empty" class="hidden text-center py-8 text-slate-400 font-medium text-sm">
                No command history found for this device.
            </div>
            <div id="history-list" class="hidden space-y-3"></div>
        </div>
    </div>
</div>

{{-- ── JavaScript ───────────────────────────────────────────────────────────── --}}
<script>
    var CSRF_TOKEN   = '{{ csrf_token() }}';
    var SEND_URL     = '{{ route("device-commands.send") }}';
    var HISTORY_BASE = '{{ url("/admin/dealer/device-commands/history") }}';

    // ── Command Panel ─────────────────────────────────────────────────────────

    function openCommandPanel(imei, vehicleNumber) {
        document.getElementById('modal-imei').value = imei;
        document.getElementById('modal-vehicle-label').textContent = vehicleNumber + ' · IMEI: ' + imei;
        document.getElementById('command-response').classList.add('hidden');
        document.getElementById('sending-indicator').classList.add('hidden');
        document.getElementById('custom-command').value = '';
        document.getElementById('command-modal').classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    }

    function closeCommandPanel() {
        document.getElementById('command-modal').classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }

    function sendQuickCommand(command) {
        doSendCommand(command, {});
    }

    function sendCustomCommand() {
        var command = document.getElementById('custom-command').value;
        if (!command) {
            showResponse('Please select a command first.', false);
            return;
        }
        doSendCommand(command, {});
    }

    function doSendCommand(command, params) {
        var imei       = document.getElementById('modal-imei').value;
        var indicator  = document.getElementById('sending-indicator');
        var responseEl = document.getElementById('command-response');

        indicator.classList.remove('hidden');
        responseEl.classList.add('hidden');

        fetch(SEND_URL, {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF_TOKEN,
                'Accept':       'application/json',
            },
            body: JSON.stringify({ imei: imei, command: command, params: params }),
        })
        .then(function(res) { return res.json(); })
        .then(function(data) { showResponse(data.message, data.success); })
        .catch(function() { showResponse('Network error. Please try again.', false); })
        .finally(function() { indicator.classList.add('hidden'); });
    }

    function showResponse(message, success) {
        var el = document.getElementById('command-response');
        el.textContent = message;
        el.className = 'mt-4 p-3.5 rounded-xl text-xs font-semibold ' + (
            success
                ? 'bg-emerald-50 border border-emerald-200 text-emerald-700'
                : 'bg-red-50 border border-red-200 text-red-700'
        );
        el.classList.remove('hidden');
    }

    // ── History Panel ─────────────────────────────────────────────────────────

    function openHistoryPanel(vehicleId, vehicleNumber) {
        document.getElementById('history-vehicle-label').textContent = vehicleNumber;
        document.getElementById('history-loading').classList.remove('hidden');
        document.getElementById('history-empty').classList.add('hidden');
        document.getElementById('history-list').classList.add('hidden');
        document.getElementById('history-modal').classList.remove('hidden');
        document.body.classList.add('overflow-hidden');

        fetch(HISTORY_BASE + '/' + vehicleId + '?limit=20', {
            headers: { 'X-CSRF-TOKEN': CSRF_TOKEN, 'Accept': 'application/json' }
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            document.getElementById('history-loading').classList.add('hidden');
            var history = data.history || [];

            if (history.length === 0) {
                document.getElementById('history-empty').classList.remove('hidden');
                return;
            }

            var list = document.getElementById('history-list');
            list.innerHTML = '';

            history.forEach(function(item) {
                var date         = item.createdAt ? new Date(item.createdAt).toLocaleString() : '—';
                var commandBadge = '<span class="px-2 py-0.5 rounded-lg text-[11px] font-black bg-blue-100 text-blue-700">'
                                 + (item.command || 'UNKNOWN') + '</span>';
                var responseText = item.rawResponse
                    ? '<p class="mt-2 text-[11px] text-slate-600 font-mono bg-slate-50 rounded-lg p-2.5 break-all leading-relaxed">' + escapeHtml(item.rawResponse) + '</p>'
                    : '<p class="mt-1 text-[11px] text-slate-400 italic">No response received yet</p>';

                list.innerHTML +=
                    '<div class="border border-slate-100 rounded-xl p-3.5 hover:bg-slate-50/50 transition">' +
                        '<div class="flex items-center justify-between mb-1">' +
                            commandBadge +
                            '<span class="text-[10px] text-slate-400">' + date + '</span>' +
                        '</div>' +
                        responseText +
                    '</div>';
            });

            list.classList.remove('hidden');
        })
        .catch(function() {
            document.getElementById('history-loading').classList.add('hidden');
            document.getElementById('history-empty').classList.remove('hidden');
        });
    }

    function closeHistoryPanel() {
        document.getElementById('history-modal').classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }

    // Prevent raw device responses from injecting HTML
    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // Close modals on backdrop click
    document.getElementById('command-modal').addEventListener('click', function(e) {
        if (e.target === this) closeCommandPanel();
    });
    document.getElementById('history-modal').addEventListener('click', function(e) {
        if (e.target === this) closeHistoryPanel();
    });

    // Close modals on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeCommandPanel();
            closeHistoryPanel();
        }
    });
</script>

@endsection
