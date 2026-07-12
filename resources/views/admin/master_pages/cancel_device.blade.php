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

        <main class="p-4 md:p-6 flex-1 space-y-6"
              x-data="{
                  modalOpen: false,
                  deviceId: null,
                  deviceLabel: '',
                  reason: '',
                  errorMsg: '',

                  openCancelModal(id, label) {
                      this.deviceId = id;
                      this.deviceLabel = label;
                      this.reason = '';
                      this.errorMsg = '';
                      this.modalOpen = true;
                  },

                  submitCancel() {
                      if (!this.reason.trim()) {
                          this.errorMsg = 'Please provide a reason for cancelling this device.';
                          return;
                      }

                      fetch(`/admin/master-pages/cancel-device/${this.deviceId}`, {
                          method: 'PATCH',
                          headers: {
                              'Content-Type': 'application/json',
                              'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                              'Accept': 'application/json',
                          },
                          body: JSON.stringify({ status: 'Canceled', cancel_reason: this.reason })
                      })
                      .then(res => res.json())
                      .then(() => {
                          this.modalOpen = false;
                          window.location.reload();
                      })
                      .catch(() => { this.errorMsg = 'Something went wrong. Please try again.'; });
                  },

                  reactivate(id) {
                      if (!confirm('Reactivate this device?')) return;

                      fetch(`/admin/master-pages/cancel-device/${id}`, {
                          method: 'PATCH',
                          headers: {
                              'Content-Type': 'application/json',
                              'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                              'Accept': 'application/json',
                          },
                          body: JSON.stringify({ status: 'Activated' })
                      })
                      .then(res => res.json())
                      .then(() => window.location.reload())
                      .catch(() => alert('Something went wrong. Please try again.'));
                  }
              }">

            @if(session('success'))
                <div class="p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg flex items-center gap-2 text-xs font-bold shadow-xs">
                    <svg class="w-4 h-4 text-green-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>{{ session('success') }}</span>
                </div>
            @endif

            <!-- TABLE 1: ACTIVATED / CANCELED DEVICES (manageable) -->
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">
                    Activated Devices
                    <span class="font-normal text-gray-400">— cancel a device if the customer stops paying, or reactivate a canceled one</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-left">
                        <thead class="bg-gray-50 text-gray-500 uppercase text-[10px]">
                            <tr>
                                <th class="px-5 py-2">ID</th>
                                <th class="px-5 py-2">Device Category</th>
                                <th class="px-5 py-2">IMEI Number</th>
                                <th class="px-5 py-2">SIM Number</th>
                                <th class="px-5 py-2">Status</th>
                                <th class="px-5 py-2">Canceled Date</th>
                                <th class="px-5 py-2">Reason</th>
                                <th class="px-5 py-2 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-gray-700">
                            @forelse($manageableDevices as $device)
                                <tr>
                                    <td class="px-5 py-2">{{ $device->shdevice_id }}</td>
                                    <td class="px-5 py-2">{{ $device->device_category }}</td>
                                    <td class="px-5 py-2">{{ $device->imei_number }}</td>
                                    <td class="px-5 py-2">{{ $device->sim_number ?? '-' }}</td>
                                    <td class="px-5 py-2">
                                        @if($device->status === 'Activated')
                                            <span class="px-2 py-0.5 rounded-full bg-green-50 text-green-700 border border-green-200 font-bold">Activated</span>
                                        @else
                                            <span class="px-2 py-0.5 rounded-full bg-red-50 text-red-700 border border-red-200 font-bold">Canceled</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-2">{{ $device->canceled_date?->format('Y-m-d H:i') ?? '-' }}</td>
                                    <td class="px-5 py-2 max-w-[160px] truncate" title="{{ $device->cancel_reason }}">{{ $device->cancel_reason ?? '-' }}</td>
                                    <td class="px-5 py-2 text-right">
                                        @if($device->status === 'Activated')
                                            <button type="button"
                                                    @click="openCancelModal({{ $device->shdevice_id }}, '{{ $device->imei_number }}')"
                                                    class="px-3 py-1 rounded-lg bg-red-50 text-red-700 border border-red-200 hover:bg-red-100 font-bold">
                                                Cancel
                                            </button>
                                        @else
                                            <button type="button"
                                                    @click="reactivate({{ $device->shdevice_id }})"
                                                    class="px-3 py-1 rounded-lg bg-green-50 text-green-700 border border-green-200 hover:bg-green-100 font-bold">
                                                Reactivate
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-5 py-6 text-center text-gray-400">No activated devices yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TABLE 2: NOT ACTIVATED DEVICES (read-only) -->
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 font-bold text-gray-800 text-sm">
                    Not Activated Devices
                    <span class="font-normal text-gray-400">— still in stock, never handed to a customer, nothing to cancel</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-left">
                        <thead class="bg-gray-50 text-gray-500 uppercase text-[10px]">
                            <tr>
                                <th class="px-5 py-2">ID</th>
                                <th class="px-5 py-2">Device Category</th>
                                <th class="px-5 py-2">IMEI Number</th>
                                <th class="px-5 py-2">SIM Number</th>
                                <th class="px-5 py-2">Setup Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-gray-700">
                            @forelse($notActivatedDevices as $device)
                                <tr>
                                    <td class="px-5 py-2">{{ $device->shdevice_id }}</td>
                                    <td class="px-5 py-2">{{ $device->device_category }}</td>
                                    <td class="px-5 py-2">{{ $device->imei_number }}</td>
                                    <td class="px-5 py-2">{{ $device->sim_number ?? '-' }}</td>
                                    <td class="px-5 py-2">{{ $device->created_at->format('Y-m-d H:i') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-5 py-6 text-center text-gray-400">No devices pending activation.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- CANCEL REASON MODAL -->
            <div x-show="modalOpen" x-cloak
                 class="fixed inset-0 bg-black/40 flex items-center justify-center z-50"
                 @keydown.escape.window="modalOpen = false">
                <div class="bg-white rounded-xl shadow-lg w-full max-w-sm p-5" @click.outside="modalOpen = false">
                    <h3 class="font-bold text-gray-800 text-sm mb-1">Cancel Device</h3>
                    <p class="text-xs text-gray-500 mb-3">IMEI: <span x-text="deviceLabel"></span></p>

                    <label class="block text-xs font-bold text-gray-700 mb-1">Reason for cancellation</label>
                    <textarea x-model="reason" rows="3"
                              placeholder="e.g. Customer not paying since June 2026"
                              class="w-full rounded-lg border-gray-300 shadow-sm text-xs mb-1"></textarea>
                    <p class="text-red-600 text-[11px] font-bold mb-3" x-show="errorMsg" x-text="errorMsg"></p>

                    <div class="flex justify-end gap-2">
                        <button type="button" @click="modalOpen = false"
                                class="px-4 py-1.5 rounded-lg border border-gray-300 text-gray-700 text-xs font-bold hover:bg-gray-100">
                            Cancel
                        </button>
                        <button type="button" @click="submitCancel()"
                                class="px-4 py-1.5 rounded-lg bg-red-600 text-white text-xs font-bold hover:bg-red-700">
                            Confirm Cancellation
                        </button>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>

</body>
</html>