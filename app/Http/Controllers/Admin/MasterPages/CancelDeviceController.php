<?php

namespace App\Http\Controllers\Admin\MasterPages;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\Request;

class CancelDeviceController extends Controller
{
    public function index()
    {
        $devices = Device::latest()->get();

        return view('admin.master_pages.cancel_device', compact('devices'));
    }

    public function update(Request $request, Device $device)
    {
        $validated = $request->validate([
            'status'      => 'required|in:Available,Temporarily Stopped,Canceled',
            'description' => 'nullable|string|max:1000',
        ]);

        // Set canceled_date only when moving INTO Canceled.
        // Clear it if the status moves back out of Canceled — a device
        // that's Available again was never "canceled" as far as the record shows.
        $device->canceled_date = $validated['status'] === 'Canceled'
            ? now()
            : null;

        $device->status      = $validated['status'];
        $device->description = $validated['description'];
        $device->save();

        return response()->json([
            'success'       => true,
            'status'        => $device->status,
            'canceled_date' => optional($device->canceled_date)->format('Y-m-d H:i'),
        ]);
    }
}