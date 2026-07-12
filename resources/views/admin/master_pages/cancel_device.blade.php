<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShaloTrack Admin - Cancel Device</title>

    @vite(['resources/css/app.css','resources/js/app.js'])

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body x-data="{ sidebarOpen: false }">

<div class="flex h-screen overflow-hidden">

    @include('partials.sidebars.admin')

    <div class="flex-1 flex flex-col overflow-y-auto">
        @include('partials.header')

        <main class="p-4 md:p-6 flex-1 space-y-6">

            @if(session('success'))
                <div class="p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-xs font-bold">
                    {{ session('success') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg shadow-xs">
                    <div class="flex items-center gap-2 mb-2">
                        <svg class="w-4 h-4 text-red-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        <span class="text-xs font-bold uppercase tracking-wider">Please fix the following:</span>
                    </div>
                    <ul class="list-disc pl-5 space-y-1 text-[11px] font-semibold">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- ===================== NOT ACTIVATED DEVICES ===================== --}}
            <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden w-full">
                <div class="px-5 py-4 border-b border-gray-100 bg-gradient-to-r from-amber-50 to-white flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                        <h2 class="font-bold text-gray-800 text-sm tracking-wide">Not Activated Devices</h2>
                        <span class="font-normal text-gray-400 text-xs">— never assigned to a customer yet</span>
                    </div>
                    <span class="text-[11px] font-bold text-amber-600 bg-amber-50 border border-amber-200 px-2.5 py-1 rounded-full">
                        {{ $notActivatedDevices->count() }} pending
                    </span>
                </div>
                <div class="p-5">
                    <div class="border border-gray-200 rounded-xl overflow-x-auto text-xs font-semibold text-gray-700">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="p-3">IMEI</th>
                                    <th class="p-3">Model</th>
                                    <th class="p-3">SIM</th>
                                    <th class="p-3">Status</th>
                                    <th class="p-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                @forelse($notActivatedDevices as $device)
                                    <tr class="hover:bg-gray-50 transition">
                                        <td class="p-3">{{ $device->imei_number }}</td>
                                        <td class="p-3">{{ $device->device_category }}</td>
                                        <td class="p-3">{{ $device->sim_number ?? '-' }}</td>
                                        <td class="p-3">
                                            <form action="{{ route('admin.cancel-device.update', $device->shdevice_id) }}"
                                                  method="POST"
                                                  onsubmit="return confirm('Activate device {{ $device->imei_number }}?');"
                                                  class="flex items-center gap-2">
                                                @csrf
                                                @method('PATCH')
                                                <select name="status" required
                                                        class="appearance-none bg-amber-50 border border-amber-200 text-amber-700 text-[11px] font-bold rounded-full px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-amber-500 cursor-pointer">
                                                    <option value="Not Activated" selected>Not Activated</option>
                                                    <option value="Activated">Activate</option>
                                                </select>
                                                <button type="submit"
                                                        class="px-3 py-1.5 rounded-lg bg-gray-800 text-white text-[11px] font-bold hover:bg-gray-900">
                                                    Save
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="p-6 text-center text-gray-400">No devices pending activation.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- ===================== ACTIVATED / TEMPORARILY STOPPED DEVICES ===================== --}}
            <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden w-full">
                <div class="px-5 py-4 border-b border-gray-100 bg-gradient-to-r from-green-50 to-white flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-green-500"></span>
                        <h2 class="font-bold text-gray-800 text-sm tracking-wide">Activated Devices</h2>
                        <span class="font-normal text-gray-400 text-xs">— stop a device if the customer isn't paying, or reactivate one that's stopped</span>
                    </div>
                    <span class="text-[11px] font-bold text-green-600 bg-green-50 border border-green-200 px-2.5 py-1 rounded-full">
                        {{ $activatedDevices->count() }} devices
                    </span>
                </div>
                <div class="p-5">
                    <div class="border border-gray-200 rounded-xl overflow-x-auto text-xs font-semibold text-gray-700">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="p-3">IMEI</th>
                                    <th class="p-3">Dealer</th>
                                    <th class="p-3">Status</th>
                                    <th class="p-3">Reason</th>
                                    <th class="p-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                @forelse($activatedDevices as $device)
                                    @php($formId = 'form-'.$device->shdevice_id)
                                    <tr class="hover:bg-gray-50 transition align-top">
                                        <td class="p-3 pt-4">
                                            {{ $device->imei_number }}
                                            {{-- Empty form, lives in this one cell only. Fields in the other
                                                 cells attach to it via the HTML5 form="{{ $formId }}" attribute
                                                 instead of being nested inside it — a <form> can't legally span
                                                 multiple <td> cells, this is the correct way to do it. --}}
                                            <form id="{{ $formId }}"
                                                  action="{{ route('admin.cancel-device.update', $device->shdevice_id) }}"
                                                  method="POST"
                                                  onsubmit="return confirm('Update device {{ $device->imei_number }}?');">
                                                @csrf
                                                @method('PATCH')
                                            </form>
                                        </td>
                                        <td class="p-3 pt-4">
                                            <select name="dealer_id" form="{{ $formId }}"
                                                    class="w-32 rounded-lg border-gray-300 text-[11px] shadow-sm">
                                                <option value="">-- No Dealer --</option>
                                                @foreach($dealers as $dealer)
                                                    <option value="{{ $dealer->id }}" {{ $device->dealer_id == $dealer->id ? 'selected' : '' }}>
                                                        {{ $dealer->full_name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="p-3 pt-4">
                                            @if($device->status === 'Activated')
                                                <select name="status" form="{{ $formId }}" required
                                                        class="appearance-none bg-green-50 border border-green-200 text-green-700 text-[11px] font-bold rounded-full px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-green-500 cursor-pointer">
                                                    <option value="Activated" selected>Activated</option>
                                                    <option value="Temporarily Stopped">Stop</option>
                                                </select>
                                            @else
                                                <select name="status" form="{{ $formId }}" required
                                                        class="appearance-none bg-red-50 border border-red-200 text-red-700 text-[11px] font-bold rounded-full px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-red-500 cursor-pointer">
                                                    <option value="Temporarily Stopped" selected>Temporarily Stopped</option>
                                                    <option value="Activated">Reactivate</option>
                                                </select>
                                            @endif
                                        </td>
                                        <td class="p-3 pt-4 max-w-[180px]">
                                            <input type="text" name="cancel_reason" form="{{ $formId }}"
                                                   value="{{ $device->cancel_reason }}"
                                                   placeholder="Reason (required to Stop)"
                                                   class="w-full rounded-lg border-gray-300 text-[11px] shadow-sm">
                                        </td>
                                        <td class="p-3 pt-4">
                                            <button type="submit" form="{{ $formId }}"
                                                    class="px-3 py-1.5 rounded-lg bg-gray-800 text-white text-[11px] font-bold hover:bg-gray-900">
                                                Save
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="p-6 text-center text-gray-400">No activated devices yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>

</body>
</html>