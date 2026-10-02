<?php

namespace App\Http\Controllers\Admin\Customer;

use App\Services\Audit;
use App\Enums\DeviceStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivatedDevice;
use App\Models\ExpiredDevice;
use App\Models\SetupShalotrackDevice;
use App\Models\VehicleAd;
use App\Traits\PushesDeviceToApi;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Barryvdh\DomPDF\Facade\Pdf;

class CustomerDeviceManagementController extends Controller
{
    use PushesDeviceToApi;

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

        // FIX 2026-09-29: expired_devices is now a permanent append-only log
        // (devices:expire-subscriptions no longer deletes the live
        // activated_devices row -- see that command for why). Showing every
        // historical row here would list a device forever, even after the
        // customer already renewed via Edit, with a "Reactivate" button
        // that -- now that the live row still exists -- would create a
        // second activated_devices row for the same vehicle. Only show a
        // log entry when that vehicle's current activated_devices row is
        // still un-renewed, and collapse to one row per vehicle (the most
        // recent lapse) since a vehicle can accumulate one log row per
        // renewal cycle over time.
        $vehiclesStillNeedingRenewal = ActivatedDevice::where('payment_status', '!=', 'Paid')
            ->pluck('vehicle_id');

        $expiredDevices = ExpiredDevice::whereIn('vehicle_id', $vehiclesStillNeedingRenewal)
            ->orderByDesc('expired_device_id')
            ->get()
            ->unique('vehicle_id')
            ->values();

        // Devices an admin may bind to a customer vehicle (name kept for the view):
        // company-held activated devices, plus devices a dealer has sold.
        // A dealer-sold device carries who sold it and to whom, so the bind form can warn when the
        // chosen vehicle belongs to someone else.
        $bindable = SetupShalotrackDevice::bindableByAdmin()
            ->orderBy('imei_number')
            ->get(['shdevice_id', 'imei_number', 'sim_number', 'device_category', 'dealer_id', 'assigned_customer_id']);

        $soldTo = DB::table('dealer_customer_ads')
            ->whereIn('id', $bindable->pluck('assigned_customer_id')->filter()->unique())
            ->pluck('name', 'id');
        $soldBy = DB::table('dealers')
            ->whereIn('id', $bindable->pluck('dealer_id')->filter()->unique())
            ->pluck('full_name', 'id');

        $notActivatedDevices = $bindable->map(fn ($d) => [
            'shdevice_id'     => $d->shdevice_id,
            'imei_number'     => $d->imei_number,
            'sim_number'      => $d->sim_number,
            'device_category' => $d->device_category,
            'sold_to'         => $d->assigned_customer_id ? ($soldTo[$d->assigned_customer_id] ?? null) : null,
            'sold_by'         => $d->dealer_id ? ($soldBy[$d->dealer_id] ?? null) : null,
        ])->values();

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

        $validated = $this->validateDeviceForm($request, customerId: $vehicle->customer_id);

        DB::transaction(function () use ($validated, $vehicle, $request) {
            $device = SetupShalotrackDevice::bindableByAdmin()
                ->where('imei_number', $validated['imei_number'])
                ->lockForUpdate()
                ->first();

            // Someone else can activate/assign this IMEI between the form
            // validation above and this locked read. Say so, instead of a bare 404.
            if (! $device) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'imei_number' => "This device is no longer available to activate: it may not be activated yet, already on a customer account, sitting in a dealer's unsold stock, or removed. Refresh the page and choose another device.",
                ]);
            }

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
                'status'             => DeviceStatus::Activated->value,
                'installed_on'       => $validated['installed_on'] ?? null,
                'installed_by'       => $validated['installed_by'] ?? null,
                'install_notes'      => $validated['install_notes'] ?? null,
                ...$this->subscriptionFields($validated),
            ]);

            $device->status = DeviceStatus::Activated->value;
            $device->save();
        });

        Audit::record('device.activated', 'vehicle', $vehicle->vehicle_id, $vehicle->vehicle_number, [], [
            'imei'               => $validated['imei_number'],
            'payment_status'     => $validated['payment_status'],
            'subscription_model' => $validated['subscription_model'] ?? null,
            'start'              => $validated['subscription_start_date'] ?? null,
            'installed_on'       => $validated['installed_on'] ?? null,
            'installed_by'       => $validated['installed_by'] ?? null,
        ]);

        return redirect()->route('admin.customer-device-management')
            ->with('success', "Device activated for {$vehicle->vehicle_number}.");
    }

    public function update(Request $request, ActivatedDevice $activatedDevice)
    {
        $validated = $this->validateDeviceForm($request, $activatedDevice, customerId: $activatedDevice->customer_id);

        $auditKeys = ['payment_status', 'subscription_model', 'subscription_start_date', 'subscription_end_date', 'bank_invoice', 'sim_number', 'device_category'];
        $auditBefore = $activatedDevice->only($auditKeys);
        $installBefore = $this->installSnapshot($activatedDevice);

        DB::transaction(function () use ($validated, $activatedDevice, $request) {
            if ($request->hasFile('bank_slip')) {
                if ($activatedDevice->bank_slip) {
                    Storage::disk('public')->delete($activatedDevice->bank_slip);
                }
                $validated['bank_slip'] = $request->file('bank_slip')->store('bank_slips', 'public');
            } else {
                unset($validated['bank_slip']);
            }

            $activatedDevice->update([
                'sim_number'       => $validated['sim_number'],
                'device_category'  => $validated['device_category'],
                'installed_on'     => $validated['installed_on'] ?? null,
                'installed_by'     => $validated['installed_by'] ?? null,
                'install_notes'    => $validated['install_notes'] ?? null,
                ...$this->subscriptionFields($validated, keepBankSlip: !$request->hasFile('bank_slip'), current: $activatedDevice),
            ]);
        });

        $installChanges = Audit::diff($installBefore, $this->installSnapshot($activatedDevice->fresh()), self::INSTALL_KEYS);
        if ($installChanges !== []) {
            Audit::record('install.updated', 'vehicle', $activatedDevice->vehicle_id, $activatedDevice->vehicle_number, $installChanges, [
                'imei' => $activatedDevice->imei_number,
            ]);
        }

        $auditChanges = Audit::diff($auditBefore, $activatedDevice->fresh()->only($auditKeys), $auditKeys);
        if ($auditChanges !== [] || $request->hasFile('bank_slip')) {
            Audit::record('subscription.updated', 'vehicle', $activatedDevice->vehicle_id, $activatedDevice->vehicle_number, $auditChanges, [
                'imei'             => $activatedDevice->imei_number,
                'bank_slip_replaced' => $request->hasFile('bank_slip'),
            ]);
        }

        return redirect()->route('admin.customer-device-management')
            ->with('success', "Device {$activatedDevice->imei_number} updated.");
    }

    /**
     * Replaces a faulty bound device with another one. Payment and subscription belong to the
     * vehicle's paid service, so the payment fields are left exactly as they are; only the physical
     * device changes. The faulty one becomes Broken Device.
     */
    public function replace(Request $request, ActivatedDevice $activatedDevice)
    {
        $validated = $request->validate([
            'new_imei_number' => [
                'required',
                'string',
                Rule::exists('setup_shalotrack_devices', 'imei_number')->where(function ($query) {
                    $query->where(function ($q) {
                        SetupShalotrackDevice::applyBindableConstraint($q);
                    });
                }),
            ],
            'reason' => ['required', 'string', 'max:255'],
            'installed_on' => ['nullable', 'date', 'before_or_equal:' . now()->local()->toDateString()],
            'installed_by' => ['nullable', 'string', 'max:100'],
        ], [
            'new_imei_number.exists' => "This device is not available to bind: it may not be activated yet, already on a customer account, sitting in a dealer's unsold stock, or removed. Refresh the page and choose another device.",
            'reason.required' => 'Say why the device is being replaced (for example: faulty, water damage).',
        ]);

        $oldImei = $activatedDevice->imei_number;
        $installBefore = $this->installSnapshot($activatedDevice);

        [$old, $new] = DB::transaction(function () use ($validated, $activatedDevice, $oldImei) {
            $new = SetupShalotrackDevice::bindableByAdmin()
                ->where('imei_number', $validated['new_imei_number'])
                ->lockForUpdate()
                ->first();

            if (! $new) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'new_imei_number' => 'This device is no longer available. Refresh the page and choose another device.',
                ]);
            }

            $old = SetupShalotrackDevice::where('imei_number', $oldImei)->lockForUpdate()->first();
            $old?->update(['status' => DeviceStatus::BrokenDevice->value]);

            $new->update(['status' => DeviceStatus::Activated->value]);

            $activatedDevice->update([
                'imei_number'     => $new->imei_number,
                'sim_number'      => $new->sim_number,
                'device_category' => $new->device_category,
                // The new hardware was fitted on this date; the old install is kept in the audit trail.
                'installed_on'    => $validated['installed_on'] ?? now()->local()->toDateString(),
                'installed_by'    => $validated['installed_by'] ?? null,
                'install_notes'   => null,
            ]);

            return [$old, $new];
        });

        Log::info('device_replaced', [
            'admin'         => auth()->id(),
            'vehicle_id'    => $activatedDevice->vehicle_id,
            'old_imei'      => $oldImei,
            'new_imei'      => $new->imei_number,
            'reason'        => $validated['reason'],
            'payment_status'=> $activatedDevice->payment_status,
        ]);

        Audit::record('device.replaced', 'vehicle', $activatedDevice->vehicle_id, $activatedDevice->vehicle_number,
            ['imei' => ['from' => $oldImei, 'to' => $new->imei_number]]
                + Audit::diff($installBefore, $this->installSnapshot($activatedDevice->fresh()), self::INSTALL_KEYS),
            ['reason' => $validated['reason'], 'payment_status' => $activatedDevice->payment_status]);

        $synced = $this->syncReplacementToApi($old, $new->fresh(), $activatedDevice->fresh(), $oldImei);

        $message = "Device replaced for {$activatedDevice->vehicle_number}: {$oldImei} is now Broken Device and {$new->imei_number} took over. The subscription carried over.";
        if (! $synced) {
            $message .= " The mobile API could not be fully updated, so the app may still show the old device. Ask a developer to run: php artisan devices:push-replacement {$oldImei} {$new->imei_number}";
        }

        return redirect()->route('admin.customer-device-management')->with('success', $message);
    }

    /**
     * Tells the API about both devices, then moves the vehicle to the new device there, then sends
     * the subscription status (non-fatal; the change is already saved here). Order matters: the API
     * refuses the move unless the new device is already registered there as Activated.
     */
    private function syncReplacementToApi(?SetupShalotrackDevice $old, SetupShalotrackDevice $new, ActivatedDevice $row, string $oldImei): bool
    {
        $ok = true;
        if ($old) {
            $ok = $this->pushDeviceToApi($old) && $ok;
        }
        $newPushed = $this->pushDeviceToApi($new);
        $ok = $newPushed && $ok;

        // Without the new device on the API side the move would be refused, so skip it (and report failure).
        $ok = ($newPushed ? $this->pushReplacementToApi($oldImei, $new->imei_number) : false) && $ok;

        try {
            $response = Http::timeout(15)
                ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
                ->acceptJson()
                ->post(config('services.shalotrack_api.base_url') . '/api/internal/subscription-status-sync', [
                    'devices' => array_values(array_filter([
                        $old ? ['imei' => $old->imei_number, 'isActive' => false, 'expiresAt' => null] : null,
                        [
                            'imei'      => $row->imei_number,
                            'isActive'  => $row->payment_status === 'Paid',
                            'expiresAt' => optional($row->subscription_end_date)->toIso8601String(),
                        ],
                    ])),
                ]);
            $ok = $response->successful() && $ok;
        } catch (\Throwable $e) {
            Log::error('Replacement subscription sync failed', ['error' => $e->getMessage()]);
            $ok = false;
        }

        return $ok;
    }

    /**
     * Streams a bank slip to a signed-in admin or finance user. Slips are payment documents, so they
     * are not served from a public /storage URL.
     */
    public function bankSlip(ActivatedDevice $activatedDevice)
    {
        abort_unless(in_array(auth()->user()?->role, ['ADMIN', 'FINANCE'], true), 403);

        $path = $activatedDevice->bank_slip;
        abort_unless($path && Storage::disk('public')->exists($path), 404, 'This bank slip is no longer stored on the server.');

        Audit::record('bank_slip.viewed', 'vehicle', $activatedDevice->vehicle_id, $activatedDevice->vehicle_number, [], ['imei' => $activatedDevice->imei_number]);

        return Storage::disk('public')->response($path, null, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, max-age=300',
        ]);
    }

    /**
     * Move an expired_devices row back into activated_devices. IMEI, SIM and
     * device category are carried over from the expired record itself — they
     * are not editable from this form.
     */
    public function reactivate(Request $request, ExpiredDevice $expiredDevice)
    {
        // FIX 2026-09-29: under the old (buggy) expiry behavior, a lapsed
        // device's activated_devices row was deleted, so this path was the
        // only way back. Now that expiry keeps the live row (see
        // devices:expire-subscriptions), that row still exists for any
        // *current* lapse -- creating another one here would double-bind
        // the vehicle. This should now only ever fire on legacy expired_devices
        // rows left over from before this fix, where no live row exists.
        // Real, current renewals go through Edit (update()) on the
        // existing activated_devices row instead.
        if (ActivatedDevice::where('vehicle_id', $expiredDevice->vehicle_id)->exists()) {
            return redirect()->route('admin.customer-device-management')
                ->withErrors(['vehicle' => "{$expiredDevice->vehicle_number} already has an active device record -- use Edit on it to process the renewal instead of Reactivate."]);
        }

        $validated = $this->validateDeviceForm($request, forReactivation: true, customerId: $expiredDevice->customer_id);

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
                'status'             => DeviceStatus::Activated->value,
                ...$this->subscriptionFields($validated),
            ]);

            $expiredDevice->delete();
        });

        Audit::record('device.reactivated', 'vehicle', $expiredDevice->vehicle_id, $expiredDevice->vehicle_number, [], [
            'imei'           => $expiredDevice->imei_number,
            'payment_status' => $validated['payment_status'],
            'start'          => $validated['subscription_start_date'] ?? null,
        ]);

        return redirect()->route('admin.customer-device-management')
            ->with('success', "Device {$expiredDevice->imei_number} reactivated for {$expiredDevice->vehicle_number}.");
    }

    private const INSTALL_KEYS = ['installed_on', 'installed_by', 'install_notes'];

    /** Install details as plain strings, so the audit trail compares dates by value. */
    private function installSnapshot(ActivatedDevice $device): array
    {
        return [
            'installed_on'  => $device->installed_on?->format('Y-m-d'),
            'installed_by'  => $device->installed_by,
            'install_notes' => $device->install_notes,
        ];
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

    private function validateDeviceForm(Request $request, ?ActivatedDevice $activatedDevice = null, bool $forReactivation = false, ?string $customerId = null): array
    {
        $rules = [
            'payment_status'          => ['required', Rule::in(['Paid', 'not-Paid'])],
            'subscription_model'      => ['nullable', 'required_if:payment_status,Paid', Rule::in(self::SUBSCRIPTION_MODELS)],
            'subscription_start_date' => ['nullable', 'required_if:payment_status,Paid', 'date'],
            'bank_invoice'            => [
                'nullable',
                'required_if:payment_status,Paid',
                'string',
                // One payment per device is the norm, but an owner may pay several of their own devices
                // with one bank invoice. The same invoice on a different owner's device is still refused.
                Rule::unique('activated_devices', 'bank_invoice')
                    ->ignore($activatedDevice?->activated_device_id, 'activated_device_id')
                    ->where(function ($query) use ($customerId) {
                        if ($customerId) {
                            $query->where(function ($q) use ($customerId) {
                                $q->whereNull('customer_id')->orWhere('customer_id', '!=', $customerId);
                            });
                        }
                    }),
            ],
            'bank_slip' => ['nullable', 'image', 'max:2048'],
        ];

        if (! $forReactivation) {
            // Date compared against Sri Lanka's today: the app clock is UTC and would reject a valid local date early in the morning.
            $rules['installed_on']  = ['nullable', 'date', 'before_or_equal:' . now()->local()->toDateString()];
            $rules['installed_by']  = ['nullable', 'string', 'max:100'];
            $rules['install_notes'] = ['nullable', 'string', 'max:500'];
        }

        if (!$forReactivation) {
            // Payment and subscription belong to the physical device (one activated_devices row
            // per IMEI), so a bound row's IMEI can never be swapped from the edit form.
            $rules['imei_number'] = $activatedDevice
                ? ['required', 'string', Rule::in([$activatedDevice->imei_number])]
                : [
                    'required',
                    'string',
                    Rule::exists('setup_shalotrack_devices', 'imei_number')->where(function ($query) {
                        $query->where(function ($q) {
                            SetupShalotrackDevice::applyBindableConstraint($q);
                        });
                    }),
                ];
            $rules['sim_number']      = ['required', 'string'];
            $rules['device_category'] = ['required', 'string'];
        }

        return $request->validate($rules, [
            'bank_invoice.unique' => 'This bank invoice number is already used for another customer. One invoice can only cover devices of the same owner.',
            'installed_on.before_or_equal' => 'The install date cannot be in the future.',
            'imei_number.in' => "A device's IMEI cannot be changed here: its payment and subscription belong to this device.",
            'imei_number.exists' => "This device is no longer available to activate: it may not be activated yet, already on a customer account, sitting in a dealer's unsold stock, or removed. Refresh the page and choose another device.",
        ]);
    }

    public function generateReport(Request $request)
    {
        
        // 1. Sweep lapsed subscriptions into expired_devices
    \Artisan::call('devices:expire-subscriptions');

    // 2. Fetch Data for All 3 Tables
    $activatedVehicleIds = ActivatedDevice::pluck('vehicle_id');
    $expiredVehicleIds   = ExpiredDevice::pluck('vehicle_id');

    $activeDevices = ActivatedDevice::orderByDesc('activated_device_id')->get();
    $expiredDevices = ExpiredDevice::orderByDesc('expired_device_id')->get();
    $inactiveDevices = VehicleAd::whereNotIn('vehicle_id', $activatedVehicleIds->merge($expiredVehicleIds))
        ->orderBy('customer_name')
        ->get();

    $title = 'CUSTOMER DEVICE MANAGEMENT ALL-IN-ONE REPORT';

    // Watermark Logo
    $logoPath = public_path('images/logo.png');
    $logoBase64 = '';
    if (file_exists($logoPath)) {
        $typeImg = pathinfo($logoPath, PATHINFO_EXTENSION);
        $dataImg = file_get_contents($logoPath);
        $logoBase64 = 'data:image/' . $typeImg . ';base64,' . base64_encode($dataImg);
    }

    $pdf = Pdf::loadView('admin.customer.device_report_pdf', compact(
        'activeDevices',
        'expiredDevices',
        'inactiveDevices',
        'title',
        'logoBase64'
    ))->setPaper('a4', 'landscape'); // Horizontal view for clear tables

    return $pdf->stream('device_report.pdf');
    }
}