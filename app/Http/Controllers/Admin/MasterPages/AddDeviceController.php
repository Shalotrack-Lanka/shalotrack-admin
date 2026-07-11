<?php

namespace App\Http\Controllers\Admin\MasterPages;

use App\Http\Controllers\Controller;
use App\Models\SetupShalotrackDevice;
use App\Models\DeviceType;
use Illuminate\Http\Request;

class AddDeviceController extends Controller
{
    public function index()
    {
        $devices    = SetupShalotrackDevice::latest('shdevice_id')->get();
        $deviceTypes = DeviceType::all();

        return view('admin.master_pages.add_device', compact('devices', 'deviceTypes'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_category' => 'required|string|max:255',
            'imei_number'      => [
            'required',
            'digits:15',                                   // exactly 15 numeric digits, no letters/spaces
            'unique:setup_shalotrack_devices,imei_number',
        ]], [
        'imei_number.digits' => 'IMEI number must be exactly 15 digits.',
        'imei_number.unique' => 'This IMEI number is already registered.',
    ]);

        SetupShalotrackDevice::create($validated);

        return redirect()->route('admin.add-device')
            ->with('success', 'Device Setup Completed Successfully!');
    }

    public function list()
    {
        $devices = SetupShalotrackDevice::latest('shdevice_id')
            ->get(['shdevice_id', 'device_category', 'imei_number', 'sim_number', 'created_at']);

        return response()->json($devices);
    }
}