<?php

namespace App\Http\Controllers\Admin\Customer;

use App\Http\Controllers\Controller;
use App\Models\ActivatedDevice;
use App\Models\SetupShalotrackDevice;
use App\Models\VehicleAd;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CustomerDeviceManagementController extends Controller
{
    private const SUBSCRIPTION_MODELS = ['3 Months', '6 Months', '1 Year', '2 Year', '3 Year'];

    public function index()
    {
        $activatedVehicleIds = ActivatedDevice::pluck('vehicle_id');

        $inactiveDevices = VehicleAd::whereNotIn('vehicle_id', $activatedVehicleIds)
            ->orderBy('customer_name')
            ->get();

        $activeDevices = ActivatedDevice::orderByDesc('activated_device_id')->get();

        $notActivatedDevices = SetupShalotrackDevice::where('status', 'Not Activated')
            ->orderBy('imei_number')
            ->get(['shdevice_id', 'imei_number', 'sim_number', 'device_category']);

        $deviceCategories = SetupShalotrackDevice::whereNotNull('device_category')
            ->distinct()
            ->orderBy('device_category')
            ->pluck('device_category');

        return view('admin.customer.customer_device_management', compact(
            'inactiveDevices',
            'activeDevices',
            'notActivatedDevices',
            'deviceCategories'
        ));
    }

    public function activate(Request $request, string $vehicleId)
    {
        $vehicle = VehicleAd::findOrFail($vehicleId);

        if (ActivatedDevice::where('vehicle_id', $vehicle->vehicle_id)->exists()) {
            return redirect()->route('admin.customer-device-management')
                ->withErrors(['vehicle' => "A device is already activated for {$vehicle->vehicle_number}."]);
        }

        $validated = $this->validateDeviceForm($request);

        DB::transaction(function () use ($validated, $vehicle, $request) {
            $device = SetupShalotrackDevice::where('imei_number', $validated['imei_number'])
                ->where('status', 'Not Activated')
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->hasFile('bank_slip')) {
                $validated['bank_slip'] = $request->file('bank_slip')->store('bank_slips', 'public');
            }

            ActivatedDevice::create([
                'vehicle_id'         => $vehicle->vehicle_id,
                'customer_id'        => $vehicle->customer_id,
                'customer_name'      => $vehicle->customer_name,
                'vehicle_number'     => $vehicle->vehicle_number,
                'model'              => $vehicle->model,
                'has_gps_device'     => $vehicle->has_gps_device,
                'imei_number'        => $validated['imei_number'],
                'sim_number'         => $validated['sim_number'],
                'device_category'    => $validated['device_category'],
                'payment_status'     => $validated['payment_status'],
                'subscription_model' => $validated['subscription_model'] ?? null,
                'bank_invoice'       => $validated['bank_invoice'] ?? null,
                'bank_slip'          => $validated['bank_slip'] ?? null,
                'status'             => 'Activated',
            ]);

            $device->status = 'Activated';
            $device->save();
        });

        return redirect()->route('admin.customer-device-management')
            ->with('success', "Device activated for {$vehicle->vehicle_number}.");
    }

    public function update(Request $request, ActivatedDevice $activatedDevice)
    {
        $validated = $this->validateDeviceForm($request, $activatedDevice);

        DB::transaction(function () use ($validated, $activatedDevice, $request) {
            $oldImei = $activatedDevice->imei_number;
            $newImei = $validated['imei_number'];

            if ($newImei !== $oldImei) {
                SetupShalotrackDevice::where('imei_number', $oldImei)->update(['status' => 'Not Activated']);
                SetupShalotrackDevice::where('imei_number', $newImei)->update(['status' => 'Activated']);
            }

            if ($request->hasFile('bank_slip')) {
                if ($activatedDevice->bank_slip) {
                    Storage::disk('public')->delete($activatedDevice->bank_slip);
                }
                $validated['bank_slip'] = $request->file('bank_slip')->store('bank_slips', 'public');
            } else {
                unset($validated['bank_slip']);
            }

            $activatedDevice->update($validated);
        });

        return redirect()->route('admin.customer-device-management')
            ->with('success', "Device {$activatedDevice->imei_number} updated.");
    }

    private function validateDeviceForm(Request $request, ?ActivatedDevice $activatedDevice = null): array
    {
        return $request->validate([
            'imei_number' => [
                'required',
                'string',
                Rule::exists('setup_shalotrack_devices', 'imei_number')->where(function ($query) use ($activatedDevice) {
                    $query->where('status', 'Not Activated');

                    if ($activatedDevice) {
                        $query->orWhere('imei_number', $activatedDevice->imei_number);
                    }
                }),
            ],
            'sim_number'         => ['required', 'string'],
            'device_category'    => ['required', 'string'],
            'payment_status'     => ['required', Rule::in(['Paid', 'not-Paid'])],
            'subscription_model' => ['nullable', 'required_if:payment_status,Paid', Rule::in(self::SUBSCRIPTION_MODELS)],
            'bank_invoice'       => ['nullable', 'required_if:payment_status,Paid', 'string'],
            'bank_slip'          => ['nullable', 'image', 'max:4096'],
        ]);
    }
}
