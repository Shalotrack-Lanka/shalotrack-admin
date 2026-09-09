<?php

namespace App\Http\Controllers\Admin\Vehicles;

use App\Http\Controllers\Controller;
use App\Models\CustomerAd;
use App\Models\DealerCustomerAd;
use App\Models\VehicleAd;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeviceCommandController extends Controller
{
    private string $gatewayUrl;
    private string $apiBaseUrl;
    private string $apiSyncKey;

    public function __construct()
    {
        $this->gatewayUrl = config('services.gateway.command_url', 'http://gateway.shalotrack.internal:8001');
        $this->apiBaseUrl = config('services.shalotrack_api.base_url', 'https://api.shalotrack.com');
        $this->apiSyncKey = config('services.shalotrack_api.sync_key', '');
    }

    public function index()
    {
        $connectedDevices = collect();
        $gatewayOnline    = false;

        try {
            $response = Http::timeout(3)->get("{$this->gatewayUrl}/devices");
            if ($response->successful()) {
                $connectedDevices = collect($response->json('connected_devices') ?? []);
                $gatewayOnline    = true;
            }
        } catch (\Throwable $e) {
            Log::warning('Gateway /devices unreachable: ' . $e->getMessage());
        }

        $vehicles = VehicleAd::whereNotNull('imei')
            ->where('imei', '!=', '')
            ->orderBy('vehicle_number')
            ->get();

        $onlineImeis = $connectedDevices->pluck('imei')->toArray();
        $vehicles = $vehicles->map(function ($vehicle) use ($onlineImeis, $connectedDevices) {
            $vehicle->is_online = in_array($vehicle->imei, $onlineImeis);
            $device = $connectedDevices->firstWhere('imei', $vehicle->imei);
            $vehicle->last_seen = $device['last_seen'] ?? null;
            return $vehicle;
        });

        return view('admin.vehicles.device_command_center', compact(
            'vehicles',
            'connectedDevices',
            'gatewayOnline'
        ));
    }

    /**
     * Dealer-specific Device Command Center
     * Shows only devices/vehicles belonging to customers registered by the logged-in dealer.
     */
    public function dealerIndex()
    {
        $user = auth()->user();
        $dealer = $user->dealer ?? (\App\Models\Dealer::find($user->dealer_id) ?? null);

        if (!$dealer) {
            return redirect()->back()->with('error', 'Dealer profile not found.');
        }

        // Gateway Status Check
        $connectedDevices = collect();
        $gatewayOnline    = false;

        try {
            $response = Http::timeout(3)->get("{$this->gatewayUrl}/devices");
            if ($response->successful()) {
                $connectedDevices = collect($response->json('connected_devices') ?? []);
                $gatewayOnline    = true;
            }
        } catch (\Throwable $e) {
            Log::warning('Gateway /devices unreachable for dealer view: ' . $e->getMessage());
        }

        // 1. Dealer ගේ Customers ලාගේ Emails සහ Phone numbers ලබා ගැනීම
        $dealerLeads = DealerCustomerAd::where('dealer_id', $dealer->id)->get();

        $emails = $dealerLeads->pluck('email')->filter()->map(fn($e) => strtolower(trim($e)))->toArray();
        $phones = $dealerLeads->pluck('contact')->filter()->map(function($p) {
            $digits = preg_replace('/[^0-9]/', '', $p);
            return strlen($digits) >= 9 ? substr($digits, -9) : $digits;
        })->toArray();

        // 2. ඒ අයට අදාළ App Account Customer IDs සොයා ගැනීම
        $customerIds = CustomerAd::query()
            ->where(function ($q) use ($emails, $phones) {
                if (!empty($emails)) {
                    $q->whereIn(DB::raw('LOWER(email)'), $emails);
                }
                foreach ($phones as $phone) {
                    $q->orWhere('phone_number', 'LIKE', '%' . $phone);
                }
            })
            ->pluck('customer_id')
            ->toArray();

        // 3. Dealer ගේ Customers ලාගේ Vehicles පමණක් Fetch කර ගැනීම
        $vehicles = VehicleAd::whereIn('customer_id', $customerIds)
            ->whereNotNull('imei')
            ->where('imei', '!=', '')
            ->orderBy('vehicle_number')
            ->get();

        $onlineImeis = $connectedDevices->pluck('imei')->toArray();
        $vehicles = $vehicles->map(function ($vehicle) use ($onlineImeis, $connectedDevices) {
            $vehicle->is_online = in_array($vehicle->imei, $onlineImeis);
            $device = $connectedDevices->firstWhere('imei', $vehicle->imei);
            $vehicle->last_seen = $device['last_seen'] ?? null;
            return $vehicle;
        });

        return view('dealer.device_command_center', compact(
            'vehicles',
            'connectedDevices',
            'gatewayOnline'
        ));
    }

    public function sendCommand(Request $request)
    {
        $validated = $request->validate([
            'imei'    => ['required', 'string', 'regex:/^\d{15}$/'],
            'command' => ['required', 'string', 'in:' . implode(',', $this->allowedCommands())],
            'params'  => ['sometimes', 'array'],
        ]);

        $payload = [
            'imei'    => $validated['imei'],
            'command' => $validated['command'],
            'params'  => $validated['params'] ?? [],
        ];

        try {
            $response = Http::timeout(10)->post("{$this->gatewayUrl}/command", $payload);

            if ($response->successful()) {
                return response()->json([
                    'success' => true,
                    'message' => "Command '{$validated['command']}' sent to device successfully.",
                ]);
            }

            $error = $response->json('error') ?? 'Command could not be delivered.';

            if ($response->status() === 503) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device is currently offline.',
                ], 503);
            }

            return response()->json([
                'success' => false,
                'message' => $error,
            ], $response->status());

        } catch (\Throwable $e) {
            Log::error('Gateway command failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gateway is temporarily unavailable. Please try again.',
            ], 503);
        }
    }

    public function deviceStatus(string $imei)
    {
        try {
            $response = Http::timeout(3)->get("{$this->gatewayUrl}/devices");
            if ($response->successful()) {
                $devices = collect($response->json('connected_devices') ?? []);
                $device  = $devices->firstWhere('imei', $imei);
                return response()->json([
                    'online'    => $device !== null,
                    'last_seen' => $device['last_seen'] ?? null,
                    'ip'        => $device['ip'] ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Gateway status check failed: ' . $e->getMessage());
        }

        return response()->json(['online' => false, 'last_seen' => null]);
    }

    public function commandHistory(Request $request, string $vehicleId)
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders(['X-Admin-Sync-Key' => $this->apiSyncKey])
                ->get("{$this->apiBaseUrl}/api/internal/command-history", [
                    'vehicleId' => $vehicleId,
                    'limit'     => $request->input('limit', 20),
                ]);

            if ($response->successful()) {
                $data = $response->json('data', []);
                return response()->json($data);
            }

            return response()->json(['history' => [], 'count' => 0]);

        } catch (\Throwable $e) {
            Log::warning('Command history fetch failed: ' . $e->getMessage());
            return response()->json(['history' => [], 'count' => 0]);
        }
    }

    private function allowedCommands(): array
    {
        return [
            'where', 'status', 'version', 'imei', 'params', 'gprsset',
            'url', 'position', 'fence_query', 'moving_query', 'speed_query',
            'sos_query', 'timer_query', 'apn_query', 'server_query',
            'relay_on', 'sos_delete', 'timer', 'speed_alarm', 'moving_alarm',
            'fence_circle', 'sos_add', 'batalm', 'poweralm', 'distance', 'reset',
        ];
    }
}