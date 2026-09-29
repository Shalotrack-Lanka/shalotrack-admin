<?php

namespace App\Http\Controllers\Admin\CancelRequests;

use App\Enums\DeviceStatus;
use App\Http\Controllers\Controller;
use App\Models\SetupShalotrackDevice;
use App\Models\Dealer;
use App\Traits\PushesDeviceToApi;
use Illuminate\Http\Request;
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

        return view('admin.cancel_requests.cancel_device', compact('activatedDevices', 'notActivatedDevices', 'dealers'));
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

        if ($current === DeviceStatus::NotActivated && $target === DeviceStatus::Activated) {
            return redirect()->back()->withErrors([
                'status' => "Device #{$device->shdevice_id} is activated from Customer Device Management, where the customer and subscription are attached. It cannot be activated from this page.",
            ]);
        }

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
