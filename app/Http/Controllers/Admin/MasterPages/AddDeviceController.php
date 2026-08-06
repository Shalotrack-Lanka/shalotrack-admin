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
        $devices = SetupShalotrackDevice::with('deviceType')
            ->latest('shdevice_id')
            ->get();

        $deviceTypes = DeviceType::orderBy('device_category')
            ->orderBy('model')
            ->get();

        return view(
            'admin.master_pages.add_device',
            compact('devices', 'deviceTypes')
        );
    }

    public function store(Request $request)
    {
        // Validate submitted data
        $validated = $request->validate([
            'device_type_id' => [
                'required',
                'exists:device_types,id',
            ],

            'imei_number' => [
                'required',
                'digits:15',
                'unique:setup_shalotrack_devices,imei_number',
            ],

            'sim_number' => [
                'nullable',
                'digits:10',
            ],
        ], [
            'device_type_id.required' =>
                'Please select a device type.',

            'device_type_id.exists' =>
                'The selected device type is invalid.',

            'imei_number.digits' =>
                'IMEI number must be exactly 15 digits.',

            'imei_number.unique' =>
                'This IMEI number is already registered.',

            'sim_number.digits' =>
                'SIM number must be exactly 10 digits.',
        ]);

        // Get the selected Device Type
        $deviceType = DeviceType::findOrFail(
            $validated['device_type_id']
        );

        // Automatically create the readable category
        // Example: SIM + dialog = "SIM with dialog"
        $deviceCategory =
            $deviceType->device_category .
            ' with ' .
            $deviceType->model;

        // Register physical device
        SetupShalotrackDevice::create([
            'device_type_id' => $deviceType->id,

            'device_category' => $deviceCategory,

            'imei_number' => $validated['imei_number'],

            'sim_number' => $validated['sim_number'] ?? null,

            'status' => 'Not Activated',

            'dealer_id' => null,
        ]);

        return redirect()
            ->route('admin.add-device')
            ->with(
                'success',
                'Device Setup Completed Successfully!'
            );
    }

    public function list()
    {
        $devices = SetupShalotrackDevice::with('deviceType')
            ->latest('shdevice_id')
            ->get([
                'shdevice_id',
                'device_type_id',
                'device_category',
                'imei_number',
                'sim_number',
                'status',
                'dealer_id',
                'created_at',
            ]);

        return response()->json($devices);
    }
}