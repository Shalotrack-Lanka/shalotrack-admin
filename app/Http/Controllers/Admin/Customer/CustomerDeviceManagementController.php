<?php

namespace App\Http\Controllers\Admin\Customer;

use App\Http\Controllers\Controller;
use App\Models\ActivatedDevice;
use App\Models\ExpiredDevice;
use App\Models\SetupShalotrackDevice;
use App\Models\VehicleAd;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CustomerDeviceManagementController extends Controller
{
    private const SUBSCRIPTION_MODELS = ['3 Months', '6 Months', '1 Year', '2 Year', '3 Year'];

    // 30 days per month, per the spec.
    private const SUBSCRIPTION_DAYS = [
        '3 Months' => 90,
        '6 Months' => 180,
        '1 Year'   => 360,
        '2 Year'   => 720,
        '3 Year'   => 1080,
    ];

    public function index()
    {
        // Sweep lapsed subscriptions into expired_devices before rendering,
        // same pattern as CustomerSetupController's customers:sync call.
        Artisan::call('devices:expire-subscriptions');

        $activatedVehicleIds = ActivatedDevice::pluck('vehicle_id');
        $expiredVehicleIds   = ExpiredDevice::pluck('vehicle_id');

        $inactiveDevices = VehicleAd::whereNotIn('vehicle_id', $activatedVehicleIds->merge($expiredVehicleIds))
            ->orderBy('customer_name')
            ->get();

        $activeDevices = ActivatedDevice::orderByDesc('activated_device_id')->get();

        $expiredDevices = ExpiredDevice::orderByDesc('expired_device_id')->get();

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
            'expiredDevices',
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
                'status'             => 'Activated',
                ...$this->subscriptionFields($validated),
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

            $activatedDevice->update([
                'imei_number'      => $validated['imei_number'],
                'sim_number'       => $validated['sim_number'],
                'device_category'  => $validated['device_category'],
                ...$this->subscriptionFields($validated, keepBankSlip: !$request->hasFile('bank_slip'), current: $activatedDevice),
            ]);
        });

        return redirect()->route('admin.customer-device-management')
            ->with('success', "Device {$activatedDevice->imei_number} updated.");
    }

    /**
     * Move an expired_devices row back into activated_devices. IMEI, SIM and
     * device category are carried over from the expired record itself — they
     * are not editable from this form.
     */
    public function reactivate(Request $request, ExpiredDevice $expiredDevice)
    {
        $validated = $this->validateDeviceForm($request, forReactivation: true);

        DB::transaction(function () use ($validated, $expiredDevice) {
            ActivatedDevice::create([
                'vehicle_id'         => $expiredDevice->vehicle_id,
                'customer_id'        => $expiredDevice->customer_id,
                'customer_name'      => $expiredDevice->customer_name,
                'vehicle_number'     => $expiredDevice->vehicle_number,
                'model'              => $expiredDevice->model,
                'has_gps_device'     => $expiredDevice->has_gps_device,
                'imei_number'        => $expiredDevice->imei_number,
                'sim_number'         => $expiredDevice->sim_number,
                'device_category'    => $expiredDevice->device_category,
                'status'             => 'Activated',
                ...$this->subscriptionFields($validated),
            ]);

            $expiredDevice->delete();
        });

        return redirect()->route('admin.customer-device-management')
            ->with('success', "Device {$expiredDevice->imei_number} reactivated for {$expiredDevice->vehicle_number}.");
    }

    /**
     * Builds the payment_status / subscription_model / subscription_start_date /
     * subscription_end_date / bank_invoice / bank_slip columns from validated
     * input. When payment_status isn't "Paid", every subscription-related
     * column is forced to null so stale data can't linger on the row.
     */
    private function subscriptionFields(array $validated, bool $keepBankSlip = false, ?ActivatedDevice $current = null): array
    {
        if ($validated['payment_status'] !== 'Paid') {
            return [
                'payment_status'          => $validated['payment_status'],
                'subscription_model'      => null,
                'subscription_start_date' => null,
                'subscription_end_date'   => null,
                'bank_invoice'            => null,
                'bank_slip'               => $keepBankSlip && $current ? $current->bank_slip : ($validated['bank_slip'] ?? null),
            ];
        }

        return [
            'payment_status'          => 'Paid',
            'subscription_model'      => $validated['subscription_model'],
            'subscription_start_date' => Carbon::parse($validated['subscription_start_date'])->startOfDay(),
            'subscription_end_date'   => $this->calcSubscriptionEndDate($validated['subscription_start_date'], $validated['subscription_model']),
            'bank_invoice'            => $validated['bank_invoice'],
            'bank_slip'               => $keepBankSlip && $current ? $current->bank_slip : ($validated['bank_slip'] ?? null),
        ];
    }

    private function calcSubscriptionEndDate(string $startDate, string $subscriptionModel): Carbon
    {
        $days = self::SUBSCRIPTION_DAYS[$subscriptionModel] ?? 0;

        // "Gets over on 12 midnight of that subscription ending day" — the
        // stored end date IS that midnight (00:00:00 on the ending day).
        return Carbon::parse($startDate)->startOfDay()->addDays($days);
    }

    private function validateDeviceForm(Request $request, ?ActivatedDevice $activatedDevice = null, bool $forReactivation = false): array
    {
        $rules = [
            'payment_status'          => ['required', Rule::in(['Paid', 'not-Paid'])],
            'subscription_model'      => ['nullable', 'required_if:payment_status,Paid', Rule::in(self::SUBSCRIPTION_MODELS)],
            'subscription_start_date' => ['nullable', 'required_if:payment_status,Paid', 'date'],
            'bank_invoice'            => [
                'nullable',
                'required_if:payment_status,Paid',
                'string',
                Rule::unique('activated_devices', 'bank_invoice')->ignore($activatedDevice?->activated_device_id, 'activated_device_id'),
            ],
            'bank_slip' => ['nullable', 'image', 'max:4096'],
        ];

        if (!$forReactivation) {
            $rules['imei_number'] = [
                'required',
                'string',
                Rule::exists('setup_shalotrack_devices', 'imei_number')->where(function ($query) use ($activatedDevice) {
                    $query->where('status', 'Not Activated');

                    if ($activatedDevice) {
                        $query->orWhere('imei_number', $activatedDevice->imei_number);
                    }
                }),
            ];
            $rules['sim_number']      = ['required', 'string'];
            $rules['device_category'] = ['required', 'string'];
        }

        return $request->validate($rules);
    }
}
