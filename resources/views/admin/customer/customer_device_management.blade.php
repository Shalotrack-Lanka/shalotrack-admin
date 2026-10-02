<!DOCTYPE html>
<html lang="en" id="htmlRoot">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ShaloTrack Admin - Customer Device Management</title>

    @vite(['resources/css/app.css','resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>
</head>

<body x-data="{ sidebarOpen: false }">

    <div class="flex h-screen overflow-hidden">

        @include('partials.sidebars.admin')

        <div class="flex-1 flex flex-col overflow-y-auto">
            @include('partials.header')

            <main class="p-4 md:p-6 flex-1 space-y-6">

                @if(session('success'))
                <div class="bg-green-100 text-green-800 border border-green-300 rounded-lg px-4 py-3 text-sm font-semibold">
                    {{ session('success') }}
                </div>
                @endif

                @if($errors->any())
                <div class="bg-red-100 text-red-800 border border-red-300 rounded-lg px-4 py-3 text-sm font-semibold">
                    <ul class="list-disc pl-5">
                        @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
                @endif

                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-2xl font-bold text-gray-800">Customer Device Management</h2>
                    <a href="{{ route('admin.customer-device-management.report') }}" target="_blank" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold px-4 py-2 rounded-lg shadow-md transition inline-flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        Generate Full Summary Report
                    </a>
                </div>

                {{-- ===================== ACTIVE DEVICES ===================== --}}
                <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full">
                    <div class="px-6 py-4 border-b border-gray-100 bg-green-50 flex items-center justify-between">
                        <h3 class="text-xl font-bold text-gray-800">Active Devices</h3>
                        <span class="text-sm font-semibold text-green-700">{{ $activeDevices->count() }} devices</span>
                    </div>
                    <div class="overflow-x-auto overflow-y-auto max-h-96">
                        <table class="w-full text-left text-sm border-collapse">
                            <thead class="bg-gray-50 border-b border-gray-200 font-bold text-gray-700 sticky top-0 z-10">
                                <tr>
                                    <th class="p-3">Customer ID</th>
                                    <th class="p-3">Customer Name</th>
                                    <th class="p-3">Vehicle Number</th>
                                    <th class="p-3">Model</th>
                                    <th class="p-3">GPS Device</th>
                                    <th class="p-3">Vehicle ID</th>
                                    <th class="p-3">Payment</th>
                                    <th class="p-3">Subscription / Warranty Ends</th>
                                    <th class="p-3 text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @forelse($activeDevices as $d)
                                @php
                                $editPayload = [
                                'activated_device_id' => $d->activated_device_id,
                                'customer_name' => $d->customer_name,
                                'vehicle_number' => $d->vehicle_number,
                                'imei_number' => $d->imei_number,
                                'sim_number' => $d->sim_number,
                                'device_category' => $d->device_category,
                                'payment_status' => $d->payment_status,
                                'subscription_model' => $d->subscription_model,
                                'subscription_start_date' => optional($d->subscription_start_date)->format('Y-m-d'),
                                'subscription_end_date' => optional($d->subscription_end_date)->format('Y-m-d'),
                                'bank_invoice' => $d->bank_invoice,
                                'installed_on' => optional($d->installed_on)->format('Y-m-d'),
                                'installed_by' => $d->installed_by,
                                'install_notes' => $d->install_notes,
                                'bank_slip_url' => $d->bank_slip ? route('admin.customer-device-management.bank-slip', $d->activated_device_id) : null,
                                ];
                                @endphp
                                <tr>
                                    <td class="p-3 font-mono text-xs" title="{{ $d->customer_id }}">{{ $d->customer_id }}</td>
                                    <td class="p-3 font-semibold">{{ $d->customer_name }}</td>
                                    <td class="p-3">{{ $d->vehicle_number }}</td>
                                    <td class="p-3">{{ $d->model }}</td>
                                    <td class="p-3">{{ $d->has_gps_device ? 'Yes' : 'No' }}</td>
                                    <td class="p-3 font-mono text-xs" title="{{ $d->vehicle_id }}">{{ $d->vehicle_id }}</td>
                                    <td class="p-3">
                                        <span data-payment-badge class="text-xs font-bold {{ $d->payment_status === 'Paid' ? 'text-green-600' : 'text-amber-600' }}">{{ $d->payment_status }}</span>
                                    </td>
                                    <td class="p-3 text-xs" data-subscription-ends>
                                        {{ $d->subscription_end_date ? $d->subscription_end_date->format('Y-m-d') : '—' }}
                                    </td>
                                    <td class="p-3 text-center">
                                        <div class="inline-flex items-center gap-2">
                                            <span class="text-xs font-bold text-green-600">{{ $d->status }}</span>
                                            <button type="button"
                                                title="Edit device"
                                                @click='$store.deviceMgmt.openEdit(@json($editPayload))'
                                                class="text-gray-500 hover:text-blue-600">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                </svg>
                                            </button>
                                            <button type="button" data-replace-button
                                                title="Replace faulty device"
                                                @click='$store.deviceMgmt.openReplace(@json($editPayload))'
                                                class="text-gray-500 hover:text-amber-600">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="9" class="p-6 text-center text-gray-400">No active devices yet.</td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- ===================== EXPIRED SUBSCRIPTION DEVICES ===================== --}}
                <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full">
                    <div class="px-6 py-4 border-b border-gray-100 bg-amber-50 flex items-center justify-between">
                        <h3 class="text-xl font-bold text-gray-800">Expired-Subscription-Devices</h3>
                        <span class="text-sm font-semibold text-amber-700">{{ $expiredDevices->count() }} devices</span>
                    </div>
                    <div class="overflow-x-auto overflow-y-auto max-h-96">
                        <table class="w-full text-left text-sm border-collapse">
                            <thead class="bg-amber-50/60 border-b border-gray-200 font-bold text-gray-700 sticky top-0 z-10">
                                <tr>
                                    <th class="p-3">Customer ID</th>
                                    <th class="p-3">Customer Name</th>
                                    <th class="p-3">Vehicle Number</th>
                                    <th class="p-3">Model</th>
                                    <th class="p-3">GPS Device</th>
                                    <th class="p-3">Vehicle ID</th>
                                    <th class="p-3 text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @forelse($expiredDevices as $e)
                                @php
                                $reactivatePayload = [
                                'expired_device_id' => $e->expired_device_id,
                                'customer_name' => $e->customer_name,
                                'vehicle_number' => $e->vehicle_number,
                                'imei_number' => $e->imei_number,
                                'sim_number' => $e->sim_number,
                                'device_category' => $e->device_category,
                                'expired_date' => optional($e->expired_date)->format('Y-m-d'),
                                ];
                                @endphp
                                <tr>
                                    <td class="p-3 font-mono text-xs" title="{{ $e->customer_id }}">{{ $e->customer_id }}</td>
                                    <td class="p-3 font-semibold">{{ $e->customer_name }}</td>
                                    <td class="p-3">{{ $e->vehicle_number }}</td>
                                    <td class="p-3">{{ $e->model }}</td>
                                    <td class="p-3">{{ $e->has_gps_device ? 'Yes' : 'No' }}</td>
                                    <td class="p-3 font-mono text-xs" title="{{ $e->vehicle_id }}">{{ $e->vehicle_id }}</td>
                                    <td class="p-3 text-center">
                                        <div class="inline-flex items-center gap-2">
                                            <span class="text-xs font-bold text-amber-600">{{ $e->status }}</span>
                                            <button type="button"
                                                title="Reactivate device"
                                                @click='$store.deviceMgmt.openReactivate(@json($reactivatePayload))'
                                                class="text-gray-500 hover:text-blue-600">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="7" class="p-6 text-center text-gray-400">No expired subscriptions.</td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- ===================== INACTIVE DEVICES ===================== --}}
                <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full">
                    <div class="px-6 py-4 border-b border-gray-100 bg-red-50 flex items-center justify-between">
                        <h3 class="text-xl font-bold text-gray-800">Inactive Devices</h3>
                        <span class="text-sm font-semibold text-red-700">{{ $inactiveDevices->count() }} vehicles</span>
                    </div>
                    <div class="overflow-x-auto overflow-y-auto max-h-96">
                        <table class="w-full text-left text-sm border-collapse">
                            <thead class="bg-gray-50 border-b border-gray-200 font-bold text-gray-700 sticky top-0 z-10">
                                <tr>
                                    <th class="p-3">Customer ID</th>
                                    <th class="p-3">Customer Name</th>
                                    <th class="p-3">Vehicle Number</th>
                                    <th class="p-3">Model</th>
                                    <th class="p-3">GPS Device</th>
                                    <th class="p-3">Vehicle ID</th>
                                    <th class="p-3 text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @forelse($inactiveDevices as $v)
                                @php
                                $activatePayload = [
                                'vehicle_id' => $v->vehicle_id,
                                'customer_name' => $v->customer_name,
                                'vehicle_number' => $v->vehicle_number,
                                ];
                                @endphp
                                <tr>
                                    <td class="p-3 font-mono text-xs" title="{{ $v->customer_id }}">{{ $v->customer_id }}</td>
                                    <td class="p-3 font-semibold">{{ $v->customer_name }}</td>
                                    <td class="p-3">{{ $v->vehicle_number }}</td>
                                    <td class="p-3">{{ $v->model }}</td>
                                    <td class="p-3">{{ $v->has_gps_device ? 'Yes' : 'No' }}</td>
                                    <td class="p-3 font-mono text-xs" title="{{ $v->vehicle_id }}">{{ $v->vehicle_id }}</td>
                                    <td class="p-3 text-center">
                                        <div class="inline-flex items-center gap-2">
                                            <span class="text-xs font-bold text-red-600">Not Activated</span>
                                            <button type="button"
                                                title="Activate device"
                                                @click='$store.deviceMgmt.openActivate(@json($activatePayload))'
                                                class="text-gray-500 hover:text-blue-600">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="7" class="p-6 text-center text-gray-400">No inactive devices.</td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

            </main>
        </div>
    </div>

    {{-- ===================== SHARED ACTIVATE / EDIT / REACTIVATE MODAL ===================== --}}
    <div x-show="$store.deviceMgmt.open"
        x-cloak
        class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
        @keydown.escape.window="$store.deviceMgmt.close()">
        <div @click.outside="$store.deviceMgmt.close()"
            class="bg-white rounded-xl shadow-lg w-full max-w-md max-h-[90vh] overflow-y-auto">

            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-bold text-gray-800" x-text="$store.deviceMgmt.modalTitle"></h3>
                    <p class="text-xs text-gray-500" x-text="$store.deviceMgmt.vehicleLabel"></p>
                </div>
                <button type="button" @click="$store.deviceMgmt.close()" class="text-gray-400 hover:text-gray-600">&#10005;</button>
            </div>

            <form :action="$store.deviceMgmt.actionUrl" method="POST" enctype="multipart/form-data" class="p-5 space-y-4">
                @csrf
                <input type="hidden" name="_method" :value="$store.deviceMgmt.methodField">

                <template x-if="$store.deviceMgmt.mode === 'reactivate'">
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 text-xs text-gray-600 space-y-1">
                        <p><span class="font-semibold text-gray-700">IMEI:</span> <span x-text="$store.deviceMgmt.form.imei_number"></span></p>
                        <p><span class="font-semibold text-gray-700">SIM:</span> <span x-text="$store.deviceMgmt.form.sim_number"></span></p>
                        <p><span class="font-semibold text-gray-700">Category:</span> <span x-text="$store.deviceMgmt.form.device_category"></span></p>
                    </div>
                </template>

                <template x-if="$store.deviceMgmt.mode !== 'reactivate'">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">IMEI Number</label>
                            <template x-if="$store.deviceMgmt.mode === 'edit'">
                                <div>
                                    <input type="text" name="imei_number" x-model="$store.deviceMgmt.form.imei_number" readonly data-imei-locked
                                        class="w-full rounded-lg border-gray-300 bg-gray-50 text-sm shadow-sm text-gray-600">
                                    <p class="text-[11px] text-gray-400 mt-1">Payment and subscription belong to this device, so its IMEI can't be changed.</p>
                                </div>
                            </template>
                            <template x-if="$store.deviceMgmt.mode !== 'edit'">
                                <select name="imei_number" x-model="$store.deviceMgmt.form.imei_number" @change="$store.deviceMgmt.onImeiChange()" required
                                    class="w-full rounded-lg border-gray-300 text-sm shadow-sm">
                                    <option value="" disabled>-- Select IMEI --</option>
                                    <template x-for="opt in $store.deviceMgmt.imeiOptions" :key="opt.imei_number">
                                        <option :value="opt.imei_number" x-text="$store.deviceMgmt.optionLabel(opt)"></option>
                                    </template>
                                </select>
                            </template>
                            <p x-show="$store.deviceMgmt.soldToWarning" x-text="$store.deviceMgmt.soldToWarning" data-sold-to-warning
                                class="text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-2 py-1.5 mt-1.5"></p>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">SIM Number</label>
                            <select name="sim_number" x-model="$store.deviceMgmt.form.sim_number" required
                                class="w-full rounded-lg border-gray-300 text-sm shadow-sm">
                                <option value="" disabled>-- Select SIM --</option>
                                <template x-for="sim in $store.deviceMgmt.simOptions" :key="sim">
                                    <option :value="sim" x-text="sim"></option>
                                </template>
                            </select>
                            <p class="text-[11px] text-gray-400 mt-1">Auto-suggested from the selected IMEI — you can change it.</p>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">Device Category</label>
                            <select name="device_category" x-model="$store.deviceMgmt.form.device_category" required
                                class="w-full rounded-lg border-gray-300 text-sm shadow-sm">
                                <option value="" disabled>-- Select Category --</option>
                                @foreach($deviceCategories as $cat)
                                <option value="{{ $cat }}">{{ $cat }}</option>
                                @endforeach
                            </select>
                            <p class="text-[11px] text-gray-400 mt-1">Auto-suggested from the selected IMEI — you can change it.</p>
                        </div>
                    </div>
                </template>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Payment Status</label>
                    <select name="payment_status" x-model="$store.deviceMgmt.form.payment_status" required
                        class="w-full rounded-lg border-gray-300 text-sm shadow-sm">
                        <option value="not-Paid">not-Paid</option>
                        <option value="Paid">Paid</option>
                    </select>
                    <p x-show="$store.deviceMgmt.form.payment_status === 'Paid'" class="text-[11px] text-gray-400 mt-1">
                        Paid needs a subscription model, a start date and a new bank invoice number.
                    </p>
                </div>

                <template x-if="$store.deviceMgmt.form.payment_status === 'Paid'">
                    <div class="space-y-4 border-t border-gray-100 pt-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">Subscription Model</label>
                            <select name="subscription_model" x-model="$store.deviceMgmt.form.subscription_model"
                                :required="$store.deviceMgmt.form.payment_status === 'Paid'"
                                class="w-full rounded-lg border-gray-300 text-sm shadow-sm">
                                <option value="" disabled>-- Select Subscription --</option>
                                <option value="3 Months">3 Months</option>
                                <option value="6 Months">6 Months</option>
                                <option value="1 Year">1 Year</option>
                                <option value="2 Year">2 Year</option>
                                <option value="3 Year">3 Year</option>
                                <option value="6 Year">6 Year</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">Subscription Start Date</label>
                            <input type="date" name="subscription_start_date" x-model="$store.deviceMgmt.form.subscription_start_date"
                                :required="$store.deviceMgmt.form.payment_status === 'Paid'"
                                class="w-full rounded-lg border-gray-300 text-sm shadow-sm">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">Subscription / Warranty Ending Date <span class="font-normal text-gray-400">(calculated)</span></label>
                            <input type="date" disabled data-end-preview
                                :value="$store.deviceMgmt.endPreview || $store.deviceMgmt.subscriptionEndDate"
                                class="w-full rounded-lg border-gray-300 bg-gray-100 text-sm shadow-sm cursor-not-allowed">
                        </div>

                        <template x-if="$store.deviceMgmt.mode === 'reactivate'">
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1.5">Expired Date</label>
                                <input type="date" disabled x-model="$store.deviceMgmt.expiredDate"
                                    class="w-full rounded-lg border-gray-300 bg-gray-100 text-sm shadow-sm cursor-not-allowed">
                            </div>
                        </template>

                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">Bank Invoice</label>
                            <input type="text" name="bank_invoice" x-model="$store.deviceMgmt.form.bank_invoice"
                                :required="$store.deviceMgmt.form.payment_status === 'Paid'"
                                class="w-full rounded-lg border-gray-300 text-sm shadow-sm">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">Bank Slip (optional)</label>
                            <input type="file" name="bank_slip" accept="image/*"
                                @change="$store.deviceMgmt.onSlipChange($event)"
                                class="w-full text-gray-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 transition-all border border-gray-200 rounded-lg p-1">
                            <p x-show="$store.deviceMgmt.slipError" x-text="$store.deviceMgmt.slipError" data-slip-error
                                class="text-[11px] text-red-600 font-semibold mt-1"></p>
                            <template x-if="$store.deviceMgmt.currentBankSlipUrl">
                                <a :href="$store.deviceMgmt.currentBankSlipUrl" target="_blank" class="text-[11px] text-blue-600 underline mt-1 inline-block">View current bank slip</a>
                            </template>
                        </div>
                    </div>
                </template>

                <template x-if="$store.deviceMgmt.mode !== 'reactivate'">
                    <div class="space-y-4 border-t border-gray-100 pt-4" data-install-fields>
                        <p class="text-xs font-bold text-gray-700">Installation</p>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">Installed on</label>
                            <input type="date" name="installed_on" x-model="$store.deviceMgmt.form.installed_on"
                                :max="$store.deviceMgmt.todayString()"
                                class="w-full rounded-lg border-gray-300 text-sm shadow-sm">
                            <p class="text-[11px] text-gray-400 mt-1">The day the device was fitted to the vehicle. Warranty is the subscription period.</p>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">Installed by</label>
                            <input type="text" name="installed_by" x-model="$store.deviceMgmt.form.installed_by" maxlength="100"
                                placeholder="Technician or dealer name"
                                class="w-full rounded-lg border-gray-300 text-sm shadow-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">Install notes (optional)</label>
                            <textarea name="install_notes" x-model="$store.deviceMgmt.form.install_notes" maxlength="500" rows="2"
                                class="w-full rounded-lg border-gray-300 text-sm shadow-sm"></textarea>
                        </div>
                    </div>
                </template>

                <div class="pt-2">
                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold px-4 py-2.5 rounded-lg text-sm transition-colors">
                        <span x-text="$store.deviceMgmt.submitLabel"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>


    {{-- ===================== REPLACE FAULTY DEVICE MODAL ===================== --}}
    <div x-show="$store.deviceMgmt.replaceOpen" x-cloak data-replace-modal
        class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
        @keydown.escape.window="$store.deviceMgmt.closeReplace()">
        <div @click.outside="$store.deviceMgmt.closeReplace()" class="bg-white rounded-xl shadow-lg w-full max-w-md max-h-[90vh] overflow-y-auto">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-bold text-gray-800">Replace Faulty Device</h3>
                    <p class="text-xs text-gray-500" x-text="$store.deviceMgmt.replaceLabel"></p>
                </div>
                <button type="button" @click="$store.deviceMgmt.closeReplace()" class="text-gray-400 hover:text-gray-600">&#10005;</button>
            </div>
            <form :action="$store.deviceMgmt.replaceUrl" method="POST" class="p-5 space-y-4">
                @csrf
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 text-xs text-gray-600">
                    Current device: <span class="font-mono font-semibold text-gray-800" x-text="$store.deviceMgmt.replaceOldImei"></span>.
                    It becomes <span class="font-semibold">Broken Device</span>. Payment and the subscription end date carry over to the new device.
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">New device (IMEI)</label>
                    <select name="new_imei_number" x-model="$store.deviceMgmt.replaceNewImei" required
                        class="w-full rounded-lg border-gray-300 text-sm shadow-sm">
                        <option value="" disabled>-- Select IMEI --</option>
                        <template x-for="opt in $store.deviceMgmt.notActivated" :key="opt.imei_number">
                            <option :value="opt.imei_number" x-text="$store.deviceMgmt.optionLabel(opt)"></option>
                        </template>
                    </select>
                    <p x-show="!$store.deviceMgmt.notActivated.length" class="text-[11px] text-amber-700 mt-1">No device is available to bind. Activate one on Cancel Device first.</p>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Reason</label>
                    <input type="text" name="reason" x-model="$store.deviceMgmt.replaceReason" maxlength="255" required
                        placeholder="e.g. faulty, water damage"
                        class="w-full rounded-lg border-gray-300 text-sm shadow-sm">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">New device fitted on</label>
                        <input type="date" name="installed_on" x-model="$store.deviceMgmt.replaceInstalledOn"
                            :max="$store.deviceMgmt.todayString()"
                            class="w-full rounded-lg border-gray-300 text-sm shadow-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">Fitted by</label>
                        <input type="text" name="installed_by" x-model="$store.deviceMgmt.replaceInstalledBy" maxlength="100"
                            class="w-full rounded-lg border-gray-300 text-sm shadow-sm">
                    </div>
                </div>
                <p class="text-[11px] text-gray-400 -mt-2">The install record moves to the new device. The old install stays in the audit log.</p>
                <button type="submit" class="w-full bg-amber-600 hover:bg-amber-700 text-white font-semibold px-4 py-2.5 rounded-lg text-sm transition-colors">
                    Replace Device
                </button>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            const activateUrlTemplate = "{{ route('admin.customer-device-management.activate', ['vehicleId' => '__ID__']) }}";
            const updateUrlTemplate = "{{ route('admin.customer-device-management.update', ['activatedDevice' => '__ID__']) }}";
            const replaceUrlTemplate = "{{ route('admin.customer-device-management.replace', ['activatedDevice' => '__ID__']) }}";
            const reactivateUrlTemplate = "{{ route('admin.customer-device-management.reactivate', ['expiredDevice' => '__ID__']) }}";

            // Local calendar date (toISOString() is UTC and gives yesterday between 00:00 and 05:30 in Sri Lanka).
            const pad2 = n => String(n).padStart(2, '0');
            const localDateString = d => d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
            const todayDateString = () => localDateString(new Date());

            // Same day counts as CustomerDeviceManagementController::SUBSCRIPTION_DAYS (30 days per month).
            const SUBSCRIPTION_DAYS = {
                '3 Months': 90,
                '6 Months': 180,
                '1 Year': 360,
                '2 Year': 720,
                '3 Year': 1080,
                '6 Year': 2160
            };

            Alpine.store('deviceMgmt', {
                open: false,
                mode: 'activate', // activate | edit | reactivate
                actionUrl: '',
                methodField: 'POST',
                vehicleLabel: '',
                currentBankSlipUrl: null,
                editExtraOption: null,
                subscriptionEndDate: '',
                expiredDate: '',
                slipError: '',
                vehicleCustomer: '',
                replaceOpen: false,
                replaceUrl: '',
                replaceLabel: '',
                replaceOldImei: '',
                replaceNewImei: '',
                replaceReason: '',
                replaceInstalledOn: '',
                replaceInstalledBy: '',
                notActivated: @json($notActivatedDevices),
                form: {
                    imei_number: '',
                    sim_number: '',
                    device_category: '',
                    payment_status: 'not-Paid',
                    subscription_model: '',
                    subscription_start_date: '',
                    bank_invoice: '',
                    installed_on: '',
                    installed_by: '',
                    install_notes: '',
                },

                todayString() {
                    return todayDateString();
                },

                get modalTitle() {
                    if (this.mode === 'edit') return 'Edit Device';
                    if (this.mode === 'reactivate') return 'Reactivate Device';
                    return 'Activate Device';
                },

                get submitLabel() {
                    if (this.mode === 'edit') return 'Save Changes';
                    if (this.mode === 'reactivate') return 'Activate Device';
                    return 'Activate Device';
                },

                // What the server will store: start date + the model's days. Shown live so the admin
                // sees the result before saving.
                get endPreview() {
                    const days = SUBSCRIPTION_DAYS[this.form.subscription_model];
                    const start = this.form.subscription_start_date;
                    if (!days || !/^\d{4}-\d{2}-\d{2}$/.test(start || '')) return '';
                    const [y, m, d] = start.split('-').map(Number);
                    return localDateString(new Date(y, m - 1, d + days));
                },

                // Bank slip limit is 2 MB (same as the server rule). Checked here so the admin sees a
                // message in the form instead of a raw server error page.
                onSlipChange(event) {
                    const file = event.target.files[0];
                    const limit = 2 * 1024 * 1024;
                    if (file && file.size > limit) {
                        this.slipError = 'This image is ' + (file.size / 1048576).toFixed(1) + ' MB. The limit is 2 MB. Choose a smaller photo.';
                        event.target.value = '';
                        return;
                    }
                    this.slipError = '';
                },

                // "IMEI — sold by <dealer> to <customer>" for dealer-sold devices.
                optionLabel(opt) {
                    return opt.sold_to
                        ? opt.imei_number + ' — sold by ' + (opt.sold_by || 'a dealer') + ' to ' + opt.sold_to
                        : opt.imei_number;
                },

                // Amber warning when the device was sold to a different person than the vehicle's owner.
                get soldToWarning() {
                    if (this.mode !== 'activate') return '';
                    const opt = this.notActivated.find(o => o.imei_number === this.form.imei_number);
                    if (!opt || !opt.sold_to) return '';
                    const norm = v => (v || '').toLowerCase().replace(/\s+/g, ' ').trim();
                    if (norm(opt.sold_to) === norm(this.vehicleCustomer)) return '';
                    return 'This device was sold by ' + (opt.sold_by || 'a dealer') + ' to ' + opt.sold_to
                        + ', but this vehicle belongs to ' + (this.vehicleCustomer || 'another customer') + '. Check before binding.';
                },

                get imeiOptions() {
                    // Editing is locked to the row's own device; only a new binding chooses from the list.
                    if (this.mode === 'edit') {
                        return this.editExtraOption ? [this.editExtraOption] : [];
                    }
                    return [...this.notActivated];
                },

                get simOptions() {
                    const sims = this.imeiOptions.map(o => o.sim_number).filter(Boolean);
                    return [...new Set(sims)];
                },

                onImeiChange() {
                    const match = this.imeiOptions.find(o => o.imei_number === this.form.imei_number);
                    if (match) {
                        this.form.sim_number = match.sim_number || '';
                        this.form.device_category = match.device_category || '';
                    }
                },

                openActivate(vehicle) {
                    this.mode = 'activate';
                    this.slipError = '';
                    this.actionUrl = activateUrlTemplate.replace('__ID__', vehicle.vehicle_id);
                    this.methodField = 'POST';
                    this.vehicleLabel = vehicle.customer_name + ' — ' + vehicle.vehicle_number;
                    this.vehicleCustomer = vehicle.customer_name || '';
                    this.editExtraOption = null;
                    this.currentBankSlipUrl = null;
                    this.subscriptionEndDate = '';
                    this.expiredDate = '';
                    this.form = {
                        imei_number: '',
                        sim_number: '',
                        device_category: '',
                        payment_status: 'not-Paid',
                        subscription_model: '',
                        subscription_start_date: todayDateString(),
                        bank_invoice: '',
                        installed_on: todayDateString(),
                        installed_by: '',
                        install_notes: '',
                    };
                    this.open = true;
                },

                openEdit(device) {
                    this.mode = 'edit';
                    this.slipError = '';
                    this.actionUrl = updateUrlTemplate.replace('__ID__', device.activated_device_id);
                    this.methodField = 'PATCH';
                    this.vehicleLabel = device.customer_name + ' — ' + device.vehicle_number;
                    this.editExtraOption = {
                        imei_number: device.imei_number,
                        sim_number: device.sim_number,
                        device_category: device.device_category,
                    };
                    this.currentBankSlipUrl = device.bank_slip_url;
                    this.subscriptionEndDate = device.subscription_end_date || '';
                    this.expiredDate = '';
                    this.form = {
                        imei_number: device.imei_number,
                        sim_number: device.sim_number,
                        device_category: device.device_category,
                        payment_status: device.payment_status,
                        subscription_model: device.subscription_model || '',
                        subscription_start_date: device.subscription_start_date || todayDateString(),
                        bank_invoice: device.bank_invoice || '',
                        installed_on: device.installed_on || '',
                        installed_by: device.installed_by || '',
                        install_notes: device.install_notes || '',
                    };
                    this.open = true;
                },

                openReactivate(device) {
                    this.mode = 'reactivate';
                    this.slipError = '';
                    this.actionUrl = reactivateUrlTemplate.replace('__ID__', device.expired_device_id);
                    this.methodField = 'POST';
                    this.vehicleLabel = device.customer_name + ' — ' + device.vehicle_number;
                    this.editExtraOption = null;
                    this.currentBankSlipUrl = null;
                    this.subscriptionEndDate = '';
                    this.expiredDate = device.expired_date || '';
                    this.form = {
                        imei_number: device.imei_number,
                        sim_number: device.sim_number,
                        device_category: device.device_category,
                        payment_status: 'Paid',
                        subscription_model: '',
                        subscription_start_date: todayDateString(),
                        bank_invoice: '',
                        installed_on: '',
                        installed_by: '',
                        install_notes: '',
                    };
                    this.open = true;
                },

                openReplace(device) {
                    this.replaceUrl = replaceUrlTemplate.replace('__ID__', device.activated_device_id);
                    this.replaceLabel = device.customer_name + ' — ' + device.vehicle_number;
                    this.replaceOldImei = device.imei_number;
                    this.replaceNewImei = '';
                    this.replaceReason = '';
                    this.replaceInstalledOn = todayDateString();
                    this.replaceInstalledBy = '';
                    this.replaceOpen = true;
                },

                closeReplace() {
                    this.replaceOpen = false;
                },

                close() {
                    this.open = false;
                },
            });
        });
    </script>

</body>

</html>