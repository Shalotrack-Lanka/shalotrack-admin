<?php

namespace App\Http\Controllers\Admin\CancelRequests;

use App\Enums\DeviceStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivatedDevice;
use App\Models\SetupShalotrackDevice;
use App\Models\Dealer;
use App\Models\Sim;
use App\Traits\PushesDeviceToApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Barryvdh\DomPDF\Facade\Pdf;

class CancelDeviceController extends Controller
{
    use PushesDeviceToApi;

    public function index()
    {
        $activatedDevices = SetupShalotrackDevice::with('dealer')
            ->whereIn('status', DeviceStatus::inServiceValues())
            ->latest('shdevice_id')
            ->get();

        // with('dealer'): the page now shows who holds each device (or that it
        // is still in company stock) so a device already transferred to a
        // dealer isn't mistaken for unassigned stock.
        $notActivatedDevices = SetupShalotrackDevice::with('dealer')
            ->where('status', DeviceStatus::NotActivated->value)
            ->latest('shdevice_id')
            ->get();

        $dealers = Dealer::orderBy('full_name')->get();

        // Activated no longer means "has a customer": it also covers devices the company has
        // enabled but not sold. A device is bound to a customer only when an activated_devices
        // row exists for its IMEI, so show that customer (or "Unsold") next to each one.
        $boundCustomers = ActivatedDevice::whereIn('imei_number', $activatedDevices->pluck('imei_number'))
            ->get(['imei_number', 'customer_name', 'vehicle_number'])
            ->keyBy('imei_number');

        // Spare Activated SIMs (a SIM leaves this pool the moment it is put in a device). Used to
        // give a SIM to a device that was registered without one.
        $spareSims = Sim::where('sim_status', 'Activated')->orderBy('sim_number')->get(['sim_number', 'sim_type']);

        return view('admin.cancel_requests.cancel_device', compact('activatedDevices', 'notActivatedDevices', 'dealers', 'boundCustomers', 'spareSims'));
    }

    public function update(Request $request, SetupShalotrackDevice $device)
    {
        $validated = $request->validate([
            'status'        => ['required', Rule::in(DeviceStatus::adminSelectableValues())],
            'cancel_reason' => 'nullable|required_if:status,' . DeviceStatus::TemporarilyStopped->value . '|string|max:500',
            'dealer_id'     => 'nullable|exists:dealers,id',
        ], [
            'cancel_reason.required_if' => 'Please provide a reason for stopping this device.',
        ]);

        if ($validated['status'] === $device->status) {
            return redirect()->back()->withErrors([
                'status' => "Device #{$device->shdevice_id} is already \"{$device->status}\". Pick a different status before saving.",
            ]);
        }

        // Transition rules live in DeviceStatus::adminNext(); anything not
        // listed is rejected whatever the dropdown offered. A stored status
        // the enum doesn't know (bad data) allows no move at all.
        $current = DeviceStatus::tryFrom((string) $device->status);
        $target  = DeviceStatus::from($validated['status']);

        if (!$current || !$current->canAdminMoveTo($target)) {
            return redirect()->back()->withErrors([
                'status' => "Cannot move a device from \"{$device->status}\" to \"{$validated['status']}\" directly.",
            ]);
        }

        $device->status        = $validated['status'];
        $device->cancel_reason = $validated['status'] === DeviceStatus::TemporarilyStopped->value ? $validated['cancel_reason'] : null;
        $device->canceled_date = $validated['status'] === DeviceStatus::TemporarilyStopped->value ? now() : null;

        if ($validated['status'] === DeviceStatus::Activated->value && $request->filled('dealer_id')) {
            $device->dealer_id = $validated['dealer_id'];
        }

        $device->save();

        // NEW: push this device's updated state to the API
        $synced = $this->pushDeviceToApi($device);

        // 💡 මෙතන තමයි පරණ Route එක තිබුණේ, එය admin.cancel_device.index ලෙස නිවැරදි කර ඇත.
        $redirect = redirect()->route('admin.cancel_device.index')
            ->with('success', "Device #{$device->shdevice_id} updated to \"{$device->status}\".");

        return $synced
            ? $redirect
            : $redirect->with('warning', 'Saved, but the new status could not be synced to the app server just now. Ask your developer to re-run the device sync (devices:backfill).');
    }

    /**
     * Put a spare Activated SIM into a device that was registered without one.
     * A tracker without a SIM cannot report, and Stock Transfer and the customer bind form both
     * require a SIM, so this is the way to make such a device usable. Same rule as registering
     * with a SIM: the SIM leaves the spare pool and its ICCID/IMSI are copied onto the device.
     */
    public function attachSim(Request $request, SetupShalotrackDevice $device)
    {
        $validated = $request->validate([
            'sim_number' => ['required', 'string'],
        ]);

        try {
            DB::transaction(function () use ($validated, $device, $request) {
                $device = SetupShalotrackDevice::whereKey($device->getKey())->lockForUpdate()->firstOrFail();

                if ($device->sim_number !== null && $device->sim_number !== '') {
                    throw new \RuntimeException("Device {$device->imei_number} already has a SIM ({$device->sim_number}).");
                }

                if (! in_array($device->status, [DeviceStatus::NotActivated->value, DeviceStatus::Activated->value], true)) {
                    throw new \RuntimeException("A SIM can only be added to a Not Activated or Activated device, not \"{$device->status}\".");
                }

                if (ActivatedDevice::where('imei_number', $device->imei_number)->exists()) {
                    throw new \RuntimeException("Device {$device->imei_number} is already on a customer account.");
                }

                $sim = Sim::where('sim_number', $validated['sim_number'])->lockForUpdate()->first();

                if (! $sim) {
                    throw new \RuntimeException('That SIM is no longer in the spare pool. Refresh the page and pick another.');
                }
                if ($sim->sim_status !== 'Activated') {
                    throw new \RuntimeException("SIM {$sim->sim_number} is not Activated yet, so it cannot go into a device.");
                }
                if ($sim->iccid && SetupShalotrackDevice::where('iccid', $sim->iccid)->exists()) {
                    throw new \RuntimeException("SIM {$sim->sim_number} is already attached to another device.");
                }

                $device->sim_number = $sim->sim_number;
                $device->iccid      = $sim->iccid;
                $device->imsi       = $sim->imsi;
                $device->save();

                // The SIM is now inside a physical device: same as registration, it leaves the pool.
                $sim->delete();

                Log::info('sim_attached_to_device', [
                    'imei'       => $device->imei_number,
                    'sim_number' => $device->sim_number,
                    'admin_id'   => $request->user()?->getAuthIdentifier(),
                ]);
            });
        } catch (\RuntimeException $e) {
            return redirect()->route('admin.cancel_device.index')->withErrors(['sim_number' => $e->getMessage()]);
        }

        $device->refresh();
        $redirect = redirect()->route('admin.cancel_device.index')
            ->with('success', "SIM {$device->sim_number} attached to device {$device->imei_number}.");

        return $this->pushDeviceToApi($device)
            ? $redirect
            : $redirect->with('warning', 'Saved, but the device could not be synced to the app server just now. Ask your developer to re-run the device sync (devices:backfill).');
    }

    public function exportNotActivated()
    {
        $devices = SetupShalotrackDevice::where('status', DeviceStatus::NotActivated->value)
            ->latest('shdevice_id')
            ->get();

        $pdf = Pdf::loadView('admin.master_pages.reports.not_activated_devices_pdf', compact('devices'));

        $filename = 'not_activated_devices_' . now()->format('Y-m-d_His') . '.pdf';

        return $pdf->download($filename);
    }

    public function exportActivated()
    {
        $devices = SetupShalotrackDevice::with('dealer')
            ->whereIn('status', DeviceStatus::inServiceValues())
            ->latest('shdevice_id')
            ->get();

        $pdf = Pdf::loadView('admin.master_pages.reports.activated_devices_pdf', compact('devices'));

        $filename = 'activated_devices_' . now()->format('Y-m-d_His') . '.pdf';

        return $pdf->download($filename);
    }
}