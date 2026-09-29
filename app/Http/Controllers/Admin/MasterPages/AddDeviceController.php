<?php

namespace App\Http\Controllers\Admin\MasterPages;

use App\Http\Controllers\Controller;
use App\Models\SetupShalotrackDevice;
use App\Models\DeviceType;
use App\Models\Sim;
use App\Models\Stock;
use App\Imports\DevicesImport;
use App\Traits\PushesDeviceToApi;
use App\Exports\DeviceImportTemplateExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class AddDeviceController extends Controller
{
    use PushesDeviceToApi;

    private const SYNC_WARNING = 'Saved, but the device could not be synced to the app server just now. Nothing is lost: ask your developer to re-run the device sync (devices:backfill).';

    public function index()
    {
        // Once a device is transferred to a dealer (dealer_id set), it no
        // longer belongs in this "newly setup, not yet transferred" list.
        $devices = SetupShalotrackDevice::with('deviceType')
            ->whereNull('dealer_id')
            ->latest('shdevice_id')
            ->get();

        $deviceTypes = DeviceType::orderBy('device_category')
            ->orderBy('model')
            ->get();

        $activatedSimNumbers = Sim::where('sim_status', 'Activated')
            ->orderBy('sim_number')
            ->pluck('sim_number');

        return view(
            'admin.master_pages.add_device',
            compact('devices', 'deviceTypes', 'activatedSimNumbers')
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
                Rule::exists('sims', 'sim_number')->where('sim_status', 'Activated'),
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
            ' ' .
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
            $device = SetupShalotrackDevice::create([
                'device_type_id' => $deviceType->id,

                'device_category' => $deviceCategory,

                'imei_number' => $validated['imei_number'],

                'sim_number' => $validated['sim_number'] ?? null,

                'status' => 'Not Activated',

                'dealer_id' => null,
            ]);

            // The SIM is now attached to a physical device, so it no longer
            // belongs in the pool of Activated SIMs available for setup.
            if (!empty($validated['sim_number'])) {
                Sim::where('sim_number', $validated['sim_number'])
                    ->where('sim_status', 'Activated')
                    ->delete();
            }

            return $device;
        });

        // NEW: push this newly registered device to the API so the mobile
        // side knows about it for activation purposes.
        $synced = $this->pushDeviceToApi($device);

        $redirect = redirect()
            ->route('admin.setup-device')
            ->with(
                'success',
                'Device Setup Completed Successfully!'
            );

        return $synced ? $redirect : $redirect->with('warning', self::SYNC_WARNING);
    }

    public function list()
    {
        $devices = SetupShalotrackDevice::with('deviceType')
            ->whereNull('dealer_id')
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

    /**
     * Serves a blank .xlsx with the exact column headers DevicesImport
     * expects, plus one worked example row so the format is obvious
     * without needing separate documentation.
     */
    public function downloadImportTemplate()
    {
        return Excel::download(
            new DeviceImportTemplateExport(),
            'device_import_template.xlsx'
        );
    }

    /**
     * Bulk version of store() above — same rules, same stock consumption,
     * same SIM validation/consumption, same API push per device. The only
     * difference is one row's failure (bad IMEI, no stock left for that
     * device type, etc.) doesn't abort the rest of the file; it's
     * collected and reported back instead.
     */
    public function importDevices(Request $request)
    {
        $request->validate([
            'excel_file' => 'required|file|mimes:xlsx,csv|max:10240',
        ]);

        $import = new DevicesImport();

        Excel::import($import, $request->file('excel_file'));

        // Same push-to-API step store() already does for a single device,
        // just looped — bulk import shouldn't silently skip the sync step
        // just because it's now happening for many devices at once.
        $syncFailed = 0;
        foreach ($import->created as $device) {
            if (!$this->pushDeviceToApi($device)) {
                $syncFailed++;
            }
        }

        return redirect()
            ->route('admin.setup-device')
            ->with([
                'import_success_count' => count($import->created),
                'import_sync_failed'   => $syncFailed,
                'import_failures'      => $import->failures(),
                'import_errors'        => $import->errors(),
            ]);
    }
}