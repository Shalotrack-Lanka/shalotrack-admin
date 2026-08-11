<?php

namespace App\Http\Controllers\Admin\MasterPages;

use App\Http\Controllers\Controller;
use App\Models\SetupShalotrackDevice;
use App\Models\DeviceType;
use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        // Company Available Stock must cover this setup — one unit of
        // physical stock is consumed per device setup, for this Device
        // Category / Type specifically.
        $device = DB::transaction(function () use ($deviceType, $deviceCategory, $validated) {
            $stock = Stock::where('device_type_id', $deviceType->id)->lockForUpdate()->first();

            if (!$stock || $stock->company_available_stock < 1) {
                throw ValidationException::withMessages([
                    'device_type_id' => 'No Company Available Stock left for this Device Category / Type.',
                ]);
            }

            $stock->decrement('company_available_stock');

            // Register physical device
            // FIX: was not capturing the created model, so pushDeviceToApi()
            // had nothing to push — this device never actually reached the API.
            return SetupShalotrackDevice::create([
                'device_type_id' => $deviceType->id,

                'device_category' => $deviceCategory,

                'imei_number' => $validated['imei_number'],

                'sim_number' => $validated['sim_number'] ?? null,

                'status' => 'Not Activated',

                'dealer_id' => null,
            ]);
        });

        // NEW: push this newly registered device to the API so the mobile
        // side knows about it for activation purposes.
        $this->pushDeviceToApi($device);

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