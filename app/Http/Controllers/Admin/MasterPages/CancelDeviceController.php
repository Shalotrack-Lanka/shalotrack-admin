<?php

namespace App\Http\Controllers\Admin\MasterPages;

use App\Http\Controllers\Controller;
use App\Models\SetupShalotrackDevice;
use App\Models\Dealer;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;

class CancelDeviceController extends Controller
{
    // Mirrors your flow diagram exactly. Anything not listed here is rejected,
    // regardless of what the dropdown on the page allowed the user to pick.
    private const ALLOWED_TRANSITIONS = [
        'Not Activated'       => ['Activated'],
        'Activated'           => ['Temporarily Stopped'],
        'Temporarily Stopped' => ['Activated'],
    ];

    public function index()
    {
        $activatedDevices = SetupShalotrackDevice::with('dealer')
            ->whereIn('status', ['Activated', 'Temporarily Stopped'])
            ->latest('shdevice_id')
            ->get();

        $notActivatedDevices = SetupShalotrackDevice::where('status', 'Not Activated')
            ->latest('shdevice_id')
            ->get();

        $dealers = Dealer::orderBy('full_name')->get();

        return view('admin.master_pages.cancel_device', compact('activatedDevices', 'notActivatedDevices', 'dealers'));
    }

    public function update(Request $request, SetupShalotrackDevice $device)
    {
        $validated = $request->validate([
            'status'        => 'required|in:Not Activated,Activated,Temporarily Stopped',
            'cancel_reason' => 'nullable|required_if:status,Temporarily Stopped|string|max:500',
            'dealer_id'     => 'nullable|exists:dealers,id',
        ], [
            'cancel_reason.required_if' => 'Please provide a reason for stopping this device.',
        ]);

        if ($validated['status'] === $device->status) {
            return redirect()->back()->withErrors([
                'status' => "Device #{$device->shdevice_id} is already \"{$device->status}\". Pick a different status before saving.",
            ]);
        }

        $allowedNext = self::ALLOWED_TRANSITIONS[$device->status] ?? [];

        if (!in_array($validated['status'], $allowedNext, true)) {
            return redirect()->back()->withErrors([
                'status' => "Cannot move a device from \"{$device->status}\" to \"{$validated['status']}\" directly.",
            ]);
        }

        $device->status        = $validated['status'];
        $device->cancel_reason = $validated['status'] === 'Temporarily Stopped' ? $validated['cancel_reason'] : null;
        $device->canceled_date = $validated['status'] === 'Temporarily Stopped' ? now() : null;

        if ($validated['status'] === 'Activated' && $request->filled('dealer_id')) {
            $device->dealer_id = $validated['dealer_id'];
        }

        $device->save();

        // NEW: push this device's updated state to the API — status
        // changes (Activated / Temporarily Stopped) need to reach the
        // mobile side just as much as the initial registration does.
        $this->pushDeviceToApi($device);

        return redirect()->route('admin.cancel-device')
            ->with('success', "Device #{$device->shdevice_id} updated to \"{$device->status}\".");
    }

    public function exportNotActivated()
    {
        $devices = SetupShalotrackDevice::where('status', 'Not Activated')
            ->latest('shdevice_id')
            ->get();

        $pdf = Pdf::loadView('admin.master_pages.reports.not_activated_devices_pdf', compact('devices'));

        $filename = 'not_activated_devices_' . now()->format('Y-m-d_His') . '.pdf';

        return $pdf->download($filename);
    }

    public function exportActivated()
    {
        $devices = SetupShalotrackDevice::with('dealer')
            ->whereIn('status', ['Activated', 'Temporarily Stopped'])
            ->latest('shdevice_id')
            ->get();

        $pdf = Pdf::loadView('admin.master_pages.reports.activated_devices_pdf', compact('devices'));

        $filename = 'activated_devices_' . now()->format('Y-m-d_His') . '.pdf';

        return $pdf->download($filename);
    }

    private function pushDeviceToApi(\App\Models\SetupShalotrackDevice $device): void
    {
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
                ->acceptJson()
                ->post(config('services.shalotrack_api.base_url') . '/api/internal/setup-devices-sync', [
                    'id'             => $device->shdevice_id,
                    'deviceCategory' => $device->device_category,
                    'imeiNumber'     => $device->imei_number,
                    'simNumber'      => $device->sim_number,
                    'status'         => $device->status,
                    'cancelReason'   => $device->cancel_reason,
                    'canceledDate'   => $device->canceled_date,
                    'dealerId'       => $device->dealer_id,
                    'deviceTypeId'   => $device->device_type_id,
                    'createdAt'      => $device->created_at,
                    'updatedAt'      => $device->updated_at,
                ]);

            if (!$response->successful()) {
                \Illuminate\Support\Facades\Log::error('Device push to API failed', [
                    'imei'   => $device->imei_number,
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                // Deliberately non-fatal — the device is already saved locally
                // in Admin's own database; the mobile-side push failing shouldn't
                // block the Admin user's workflow. Worth a retry/alerting
                // mechanism later if this needs to be more reliable.
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Device push to API threw an exception', [
                'imei'  => $device->imei_number,
                'error' => $e->getMessage(),
            ]);
        }
    }
}