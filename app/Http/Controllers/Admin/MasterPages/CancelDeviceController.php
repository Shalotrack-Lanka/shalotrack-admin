<?php

namespace App\Http\Controllers\Admin\MasterPages;

use App\Http\Controllers\Controller;
use App\Models\SetupShalotrackDevice;
use Illuminate\Http\Request;

class CancelDeviceController extends Controller
{
    public function index()
    {
        $manageableDevices = SetupShalotrackDevice::whereIn('status', ['Activated', 'Canceled'])
            ->latest('shdevice_id')
            ->get();

        $notActivatedDevices = SetupShalotrackDevice::where('status', 'Not Activated')
            ->latest('shdevice_id')
            ->get();

        return view('admin.master_pages.cancel_device', compact('manageableDevices', 'notActivatedDevices'));
    }

    public function update(Request $request, SetupShalotrackDevice $device)
    {
        $validated = $request->validate([
            'status'        => 'required|in:Activated,Canceled',
            'cancel_reason' => 'nullable|required_if:status,Canceled|string|max:500',
        ], [
            'cancel_reason.required_if' => 'Please provide a reason for cancelling this device.',
        ]);

        $device->status        = $validated['status'];
        $device->cancel_reason = $validated['status'] === 'Canceled' ? $validated['cancel_reason'] : null;
        $device->canceled_date = $validated['status'] === 'Canceled' ? now() : null;
        $device->save();

        return response()->json([
            'success'       => true,
            'status'        => $device->status,
            'cancel_reason' => $device->cancel_reason,
            'canceled_date' => optional($device->canceled_date)->format('Y-m-d H:i'),
        ]);
    }
}