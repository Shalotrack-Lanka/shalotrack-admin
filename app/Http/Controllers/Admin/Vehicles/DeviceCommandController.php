<?php

namespace App\Http\Controllers\Admin\Vehicles;

use App\Http\Controllers\Controller;
use App\Models\VehicleAd;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeviceCommandController extends Controller
{
    private string $gatewayUrl;
    private string $apiBaseUrl;
    private string $apiSyncKey;

    public function __construct()
    {
        $this->gatewayUrl  = config('services.gateway.command_url', 'http://gateway.shalotrack.internal:8001');
        $this->apiBaseUrl  = config('services.shalotrack_api.base_url', 'https://api.shalotrack.com');
        $this->apiSyncKey  = config('services.shalotrack_api.sync_key', '');
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

    /**
     * Fetch command history for a vehicle from the C# API internal endpoint.
     * Uses X-Admin-Sync-Key — no Firebase JWT needed.
     */
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