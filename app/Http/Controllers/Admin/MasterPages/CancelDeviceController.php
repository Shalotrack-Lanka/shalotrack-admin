<?php

namespace App\Http\Controllers\Admin\MasterPages;

use App\Http\Controllers\Controller;
use App\Models\SetupShalotrackDevice;
use App\Models\Dealer;
use Illuminate\Http\Request;

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

    return redirect()->route('admin.cancel-device')
        ->with('success', "Device #{$device->shdevice_id} updated to \"{$device->status}\".");
}
}