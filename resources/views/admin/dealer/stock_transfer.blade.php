@extends('layouts.admin')

@section('title', 'Setuped Device Transfered to Dealers')

@section('content')
<div class="space-y-6">

    @if(session('success'))
        <div class="p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-xs font-bold">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-xs font-bold">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full">
        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50">
            <h3 class="text-base font-bold text-gray-800">Transfer Stock to Dealer</h3>
        </div>
        <div class="p-6">
            <form action="{{ route('admin.dealer.stock_transfer.store') }}" method="POST" id="transfer_form" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end text-sm">
                @csrf

                <div class="md:col-span-1">
                    <label class="block mb-1 font-semibold text-gray-700">Device Category / Type</label>
                    <select id="device_category" name="device_category" required
                        class="w-full rounded-lg border-gray-300 h-10 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500">

                        <option value="" disabled selected>
                            -- Select Device Category / Type --
                        </option>

                        @forelse($deviceCategories as $category)
                            <option value="{{ $category }}">{{ $category }}</option>
                        @empty
                            <option value="" disabled>No device categories available</option>
                        @endforelse

                    </select>
                </div>

                <div class="md:col-span-1">
                    <label class="block mb-1 font-semibold text-gray-700">
                        Select Dealer
                    </label>

                    <select id="dealer_id" name="dealer_id" required
                        class="w-full rounded-lg border-gray-300 h-10 focus:ring-blue-500 focus:border-blue-500 text-xs">

                        <option value="" selected disabled>
                            -- Select Dealer --
                        </option>

                        @foreach($dealers as $dealer)
                            <option value="{{ $dealer->id }}">
                                {{ $dealer->full_name }}
                            </option>
                        @endforeach

                    </select>
                </div>

                <div class="md:col-span-1">
                    <label class="block mb-1 font-semibold text-gray-700">Sim_number</label>

                    <select id="sim_numbers" name="sim_numbers[]" multiple required
                        class="w-full rounded-lg border-gray-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500 h-24">
                        <option value="" disabled>-- Select Device Category / Type first --</option>
                    </select>
                    <p class="text-[11px] text-gray-400 mt-1">Hold Ctrl (Cmd on Mac) and click to select multiple SIM numbers.</p>
                </div>

                <div class="md:col-span-1">
                    <label class="block mb-1 font-semibold text-gray-700">Nu of Devices</label>
                    <input type="text" id="nu_of_devices" class="w-full rounded-lg border-gray-300 h-10 bg-gray-100" value="0" readonly>
                </div>

                <div class="md:col-span-4 flex gap-2 justify-end">
                    <button type="button" id="reset_btn" class="px-6 bg-gray-100 hover:bg-gray-200 text-gray-700 h-10 rounded-lg font-bold transition">Reset</button>
                    <button type="submit" id="transfer_btn" class="px-8 bg-[#17a2b8] hover:bg-[#138496] text-white h-10 rounded-lg font-bold shadow-sm transition disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-[#17a2b8]" disabled>Transfer</button>
                </div>
            </form>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full">
        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50">
            <h3 class="text-base font-bold text-gray-800">Transferred Device History</h3>
        </div>
        <div class="p-6">
            <div class="border border-gray-200 rounded-lg overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-gray-50 border-b border-gray-200 text-xs text-gray-700 uppercase">
                        <tr>
                            <th class="p-4">Date & time</th>
                            <th class="p-4">Dealer</th>
                            <th class="p-4">Device Category / Type</th>
                            <th class="p-4 text-center">Nu of Devices</th>
                            <th class="p-4 text-center">Delete & Edit</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white text-sm font-medium text-gray-700">
                        @forelse($transfers as $transfer)
                            <tr class="hover:bg-gray-50 transition">
                                <td class="p-4 text-xs text-gray-500">{{ $transfer->created_at->format('Y-m-d h:i A') }}</td>
                                <td class="p-4 font-bold">{{ $transfer->dealer->full_name ?? '-' }}</td>
                                <td class="p-4">{{ $transfer->device_category }}</td>
                                <td class="p-4 text-center font-bold text-blue-600 bg-blue-50">{{ $transfer->quantity }}</td>
                                <td class="p-4 text-center">
                                    <div class="inline-flex items-center gap-2">
                                        <button type="button"
                                            class="edit-transfer-btn px-3 py-1.5 rounded-lg bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold"
                                            data-id="{{ $transfer->id }}"
                                            data-category="{{ $transfer->device_category }}">
                                            Edit
                                        </button>

                                        <form action="{{ route('admin.dealer.stock_transfer.destroy', $transfer) }}" method="POST"
                                              onsubmit="return confirm('Delete this transfer history record? The devices will remain assigned to the dealer.')" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="px-3 py-1.5 rounded-lg bg-red-50 hover:bg-red-100 text-red-600 font-bold">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="p-8 text-center text-gray-400">No stock transfers found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ===================== TRANSFERRED IMEI / DEVICES ===================== --}}
    <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden w-full">
        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
            <div>
                <h3 class="text-base font-bold text-gray-800">Transferred IMEI / Devices</h3>
                <p class="text-xs text-gray-400 mt-1">Individual physical devices actually allocated to a dealer</p>
            </div>
            <span class="text-xs text-gray-400 font-semibold">{{ $allocatedDevices->count() }} device(s)</span>
        </div>
        <div class="p-6">
            <div class="border border-gray-200 rounded-lg overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-gray-50 border-b border-gray-200 text-xs text-gray-700 uppercase">
                        <tr>
                            <th class="p-4">IMEI Number</th>
                            <th class="p-4">SIM Number</th>
                            <th class="p-4">Device Type</th>
                            <th class="p-4">Dealer Name</th>
                            <th class="p-4">Status</th>
                            <th class="p-4">Allocation Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white text-sm font-medium text-gray-700">
                        @forelse($allocatedDevices as $device)
                            <tr class="hover:bg-gray-50 transition">
                                <td class="p-4 font-mono text-xs">{{ $device->imei_number }}</td>
                                <td class="p-4">{{ $device->sim_number ?? '-' }}</td>
                                <td class="p-4">
                                    {{ $device->deviceType->device_category ?? $device->device_category ?? '-' }}
                                    @if($device->deviceType?->model)
                                        <span class="text-gray-400">— {{ $device->deviceType->model }}</span>
                                    @endif
                                </td>
                                <td class="p-4 font-bold">{{ $device->dealer->full_name ?? '-' }}</td>
                                <td class="p-4">
                                    <span class="px-2 py-1 rounded-full bg-blue-50 text-blue-700 border border-blue-200 text-[10px] font-bold">
                                        {{ $device->status }}
                                    </span>
                                </td>
                                <td class="p-4 text-xs text-gray-500">
                                    @if($device->allocated_at)
                                        {{ $device->allocated_at->format('d M Y, h:i A') }}
                                    @else
                                        <span class="text-gray-400 italic">Not recorded</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="p-8 text-center text-gray-400">No devices have been transferred to a dealer yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- ===================== EDIT TRANSFER MODAL ===================== --}}
<div id="edit_modal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg">
        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
            <h3 class="text-base font-bold text-gray-800">Edit Transfer Record</h3>
            <button type="button" id="edit_modal_close" class="text-gray-400 hover:text-gray-600 font-bold text-lg leading-none">&times;</button>
        </div>
        <form id="edit_form" method="POST" class="p-6 space-y-4 text-sm">
            @csrf
            @method('PUT')

            <div>
                <label class="block mb-1 font-semibold text-gray-700">Device Category / Type</label>
                <input type="text" id="edit_device_category" class="w-full rounded-lg border-gray-300 h-10 bg-gray-100 text-xs" readonly>
            </div>

            <div>
                <label class="block mb-1 font-semibold text-gray-700">Select Dealer</label>
                <select id="edit_dealer_id" name="dealer_id" required
                    class="w-full rounded-lg border-gray-300 h-10 text-xs">
                    @foreach($dealers as $dealer)
                        <option value="{{ $dealer->id }}">{{ $dealer->full_name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block mb-1 font-semibold text-gray-700">Sim_number</label>
                <select id="edit_sim_numbers" name="sim_numbers[]" multiple required
                    class="w-full rounded-lg border-gray-300 text-xs h-32"></select>
                <p class="text-[11px] text-gray-400 mt-1">Hold Ctrl (Cmd on Mac) and click to select multiple SIM numbers.</p>
            </div>

            <div>
                <label class="block mb-1 font-semibold text-gray-700">Nu of Devices</label>
                <input type="text" id="edit_nu_of_devices" class="w-full rounded-lg border-gray-300 h-10 bg-gray-100" value="0" readonly>
            </div>

            <div class="flex gap-2 justify-end pt-2 border-t border-gray-100">
                <button type="button" id="edit_modal_cancel" class="px-6 bg-gray-100 hover:bg-gray-200 text-gray-700 h-10 rounded-lg font-bold transition">Cancel</button>
                <button type="submit" class="px-8 bg-[#17a2b8] hover:bg-[#138496] text-white h-10 rounded-lg font-bold shadow-sm transition">Save Changes</button>
            </div>
        </form>
    </div>
</div>
@endsection

<script>
document.addEventListener("DOMContentLoaded", function () {

    const deviceCategory = document.getElementById("device_category");
    const dealer = document.getElementById("dealer_id");
    const simNumbers = document.getElementById("sim_numbers");
    const nuOfDevices = document.getElementById("nu_of_devices");
    const transferBtn = document.getElementById("transfer_btn");
    const resetBtn = document.getElementById("reset_btn");
    const transferForm = document.getElementById("transfer_form");

    function updateNuOfDevices(selectEl, outputEl, btnEl) {
        const count = Array.from(selectEl.selectedOptions).filter(o => o.value).length;
        outputEl.value = count;
        if (btnEl) {
            btnEl.disabled = count === 0;
        }
        return count;
    }

    function resetTransferFields() {
        simNumbers.innerHTML = '<option value="" disabled>-- Select Device Category / Type first --</option>';
        nuOfDevices.value = 0;
        transferBtn.disabled = true;
    }

    function loadSimNumbers(category, selectEl, callback) {
        selectEl.innerHTML = '<option value="" disabled>Loading...</option>';

        fetch('/admin/dealer/device-categories/' + encodeURIComponent(category) + '/sim-numbers')
            .then(response => {
                if (!response.ok) {
                    throw new Error("HTTP " + response.status);
                }
                return response.json();
            })
            .then(data => {
                selectEl.innerHTML = '';

                if (data.length === 0) {
                    selectEl.innerHTML = '<option value="" disabled>No SIM numbers available</option>';
                    return;
                }

                data.forEach(function (sim) {
                    const opt = document.createElement('option');
                    opt.value = sim;
                    opt.textContent = sim;
                    selectEl.appendChild(opt);
                });

                if (callback) callback();
            })
            .catch(error => {
                console.error(error);
                selectEl.innerHTML = '<option value="" disabled>Failed to load SIM numbers</option>';
            });
    }

    // -------------------------------
    // Create form
    // -------------------------------
    deviceCategory.addEventListener("change", function () {
        resetTransferFields();
        if (this.value) {
            loadSimNumbers(this.value, simNumbers);
        }
    });

    simNumbers.addEventListener("change", function () {
        updateNuOfDevices(simNumbers, nuOfDevices, transferBtn);
    });

    resetBtn.addEventListener("click", function () {
        transferForm.reset();
        resetTransferFields();
    });

    // -------------------------------
    // Edit modal
    // -------------------------------
    const editModal = document.getElementById("edit_modal");
    const editForm = document.getElementById("edit_form");
    const editDeviceCategory = document.getElementById("edit_device_category");
    const editDealer = document.getElementById("edit_dealer_id");
    const editSimNumbers = document.getElementById("edit_sim_numbers");
    const editNuOfDevices = document.getElementById("edit_nu_of_devices");

    function openEditModal(id) {
        editForm.action = '/admin/dealer/stock-transfer/' + id;

        fetch('/admin/dealer/stock-transfer/' + id + '/edit-data')
            .then(response => response.json())
            .then(data => {
                editDeviceCategory.value = data.device_category;
                editDealer.value = data.dealer_id;

                editSimNumbers.innerHTML = '';
                data.sim_numbers.forEach(function (sim) {
                    const opt = document.createElement('option');
                    opt.value = sim;
                    opt.textContent = sim;
                    opt.selected = data.selected.includes(sim);
                    editSimNumbers.appendChild(opt);
                });

                updateNuOfDevices(editSimNumbers, editNuOfDevices, null);
                editModal.classList.remove("hidden");
            })
            .catch(error => console.error(error));
    }

    function closeEditModal() {
        editModal.classList.add("hidden");
    }

    document.querySelectorAll(".edit-transfer-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            openEditModal(this.dataset.id);
        });
    });

    editSimNumbers.addEventListener("change", function () {
        updateNuOfDevices(editSimNumbers, editNuOfDevices, null);
    });

    document.getElementById("edit_modal_close").addEventListener("click", closeEditModal);
    document.getElementById("edit_modal_cancel").addEventListener("click", closeEditModal);

});
</script>
