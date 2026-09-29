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
        // (string) casts: config() returns null (not the default) when the key
        // exists but its env var is unset, and assigning null to these typed
        // properties would 500 every request to this controller.
        $this->gatewayUrl = (string) config('services.gateway.command_url', 'http://gateway.shalotrack.internal:8001');
        $this->apiBaseUrl = (string) config('services.shalotrack_api.base_url', 'https://api.shalotrack.com');
        $this->apiSyncKey = (string) config('services.shalotrack_api.sync_key', '');
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
        $dealer = $this->resolveDealer(auth()->user());

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

        // Only vehicles of customers this dealer registered (same rule that
        // sendCommand/deviceStatus/commandHistory enforce server-side).
        $vehicles = $this->dealerVehicles($dealer);

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

        // Server-side ownership check. The UI only lists a dealer's own devices,
        // but the endpoint is reachable by any signed-in user, so it must decide.
        if ($denied = $this->denyUnlessMayControl($validated['imei'], null)) {
            return $denied;
        }

        Log::info('Device command requested', [
            'user_id' => auth()->id(),
            'role'    => auth()->user()?->role,
            'imei'    => $validated['imei'],
            'command' => $validated['command'],
            'ip'      => $request->ip(),
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
        if ($denied = $this->denyUnlessMayControl($imei, null)) {
            return $denied;
        }

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
        if ($denied = $this->denyUnlessMayControl(null, $vehicleId)) {
            return $denied;
        }

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

    private function resolveDealer($user): ?\App\Models\Dealer
    {
        if (!$user) {
            return null;
        }

        return $user->dealer ?? \App\Models\Dealer::find($user->dealer_id);
    }

    /**
     * Vehicles (with a device IMEI) belonging to customers this dealer registered.
     * Single source of truth for both the dealer's page and the ownership checks.
     */
    private function dealerVehicles(\App\Models\Dealer $dealer)
    {
        return app(\App\Services\DealerCustomerScope::class)->gpsVehicles($dealer);
    }

    /**
     * Who may command / inspect a device? ADMIN: any. DEALER: only devices of
     * their own customers. Every other role (FINANCE, TECHNICIAN, SUPPLIER…): none.
     * Returns null when allowed, otherwise the 403 JSON response to send back.
     * Comparison is strict on purpose: "0123" must never match "123".
     */
    private function denyUnlessMayControl(?string $imei, ?string $vehicleId): ?\Illuminate\Http\JsonResponse
    {
        $user = auth()->user();
        $role = $user?->role;

        if ($role === 'ADMIN') {
            return null;
        }

        if ($role === 'DEALER' && ($dealer = $this->resolveDealer($user))) {
            $owned = $this->dealerVehicles($dealer);

            $ok = $owned->contains(function ($v) use ($imei, $vehicleId) {
                return ($imei !== null && (string) $v->imei === $imei)
                    || ($vehicleId !== null && (string) $v->vehicle_id === $vehicleId);
            });

            if ($ok) {
                return null;
            }
        }

        Log::warning('Device command access denied', [
            'user_id'    => auth()->id(),
            'role'       => $role,
            'imei'       => $imei,
            'vehicle_id' => $vehicleId,
            'ip'         => request()->ip(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'You do not have access to that device.',
            'history' => [],
            'count'   => 0,
        ], 403);
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