<?php

namespace App\Http\Controllers\Admin\MasterPages;

use App\Http\Controllers\Controller;
use App\Models\DeviceType;
use App\Models\SetupShalotrackDevice;
use App\Models\Sim;
use App\Services\DeviceRegistrationService;
use App\Traits\PushesDeviceToApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Barcode-scanner intake for device registration.
 *
 * The scanner (USB/Bluetooth HID, or later the phone camera) only ever
 * produces text; ALL trust lives here. The browser's own checks are for
 * speed of feedback — every rule is re-checked on the server at commit.
 */
class ScanDeviceController extends Controller
{
    use PushesDeviceToApi;

    private const MAX_ROWS_PER_COMMIT = 200;

    public function index()
    {
        $deviceTypes = DeviceType::orderBy('device_category')->orderBy('model')->get();

        return view('admin.master_pages.scan_device', compact('deviceTypes'));
    }

    /**
     * Instant per-scan feedback. Read-only, no side effects.
     * Body: { imei?: "15 digits", iccid?: "19-20 digits" }
     */
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            'imei'  => ['nullable', 'string', 'max:32'],
            'iccid' => ['nullable', 'string', 'max:32'],
        ]);

        $out = [];

        if (! empty($data['imei'])) {
            $imei = $data['imei'];
            if (! self::isValidImei($imei)) {
                $out['imei'] = ['ok' => false, 'message' => 'Not a valid IMEI (must be 15 digits with a correct check digit) — rescan.'];
            } elseif (SetupShalotrackDevice::where('imei_number', $imei)->exists()) {
                $out['imei'] = ['ok' => false, 'message' => 'This IMEI is already registered.'];
            } else {
                $out['imei'] = ['ok' => true];
            }
        }

        if (! empty($data['iccid'])) {
            $out['iccid'] = $this->checkIccid($data['iccid']);
        }

        return response()->json($out);
    }

    /**
     * Commit a reviewed batch. One device type per batch, up to 200 rows.
     * Each row is its own transaction (DeviceRegistrationService), so one bad
     * row never aborts or corrupts the others; the response reports each row.
     *
     * Body: { device_type_id, rows: [ { imei, iccid? } ... ] }
     */
    public function commit(Request $request, DeviceRegistrationService $service): JsonResponse
    {
        $data = $request->validate([
            'device_type_id'  => ['required', 'integer', 'exists:device_types,id'],
            'rows'            => ['required', 'array', 'min:1', 'max:' . self::MAX_ROWS_PER_COMMIT],
            'rows.*.imei'     => ['required', 'string', 'digits:15'],
            'rows.*.iccid'    => ['nullable', 'string', 'digits_between:19,20'],
        ]);

        $adminId = $request->user()?->getAuthIdentifier();

        // Duplicate IMEIs / ICCIDs inside the same submission: reject the repeats.
        $seenImei = [];
        $seenIccid = [];
        $results = [];
        $created = [];

        foreach ($data['rows'] as $i => $row) {
            $imei  = $row['imei'];
            $iccid = $row['iccid'] ?? null;

            if (! self::isValidImei($imei)) {
                $results[] = $this->fail($i, $imei, 'Invalid IMEI check digit — likely a misread. Rescan it.');
                continue;
            }
            if (isset($seenImei[$imei])) {
                $results[] = $this->fail($i, $imei, 'Duplicate IMEI within this batch.');
                continue;
            }
            $seenImei[$imei] = true;

            if ($iccid !== null && $iccid !== '') {
                if (isset($seenIccid[$iccid])) {
                    $results[] = $this->fail($i, $imei, 'Duplicate SIM (ICCID) within this batch.');
                    continue;
                }
                $seenIccid[$iccid] = true;
            }

            try {
                $device = $service->register(
                    (int) $data['device_type_id'],
                    $imei,
                    $iccid,
                    'scan',
                    $adminId !== null ? (string) $adminId : null
                );

                $created[] = $device;
                $results[] = ['index' => $i, 'imei' => $imei, 'ok' => true, 'id' => $device->shdevice_id];
            } catch (ValidationException $e) {
                $results[] = $this->fail($i, $imei, collect($e->errors())->flatten()->first());
            } catch (\Illuminate\Database\QueryException $e) {
                // Most likely the UNIQUE index catching a concurrent registration.
                Log::warning('Scan intake row rejected by database', ['imei' => $imei, 'error' => $e->getMessage()]);
                $results[] = $this->fail($i, $imei, 'Could not be saved (already registered by someone else just now?).');
            }
        }

        // Audit trail: one line per batch; per-device attribution is on the row itself.
        Log::info('Device scan intake committed', [
            'admin_id'  => $adminId,
            'type_id'   => $data['device_type_id'],
            'submitted' => count($data['rows']),
            'created'   => count($created),
            'ip'        => $request->ip(),
        ]);

        // Same non-fatal sync to the API that manual setup and bulk import do.
        foreach ($created as $device) {
            $this->pushDeviceToApi($device);
        }

        return response()->json([
            'created' => count($created),
            'failed'  => count($results) - count($created),
            'results' => $results,
        ]);
    }

    private function checkIccid(string $iccid): array
    {
        if (! preg_match('/^\d{19,20}$/', $iccid)) {
            return ['ok' => false, 'message' => 'Not a valid SIM ICCID (19–20 digits) — rescan.'];
        }

        $sim = Sim::where('iccid', $iccid)->first();

        if (! $sim) {
            // Either already consumed by a device, or never added to the SIM master.
            $onDevice = SetupShalotrackDevice::where('iccid', $iccid)->exists();

            return ['ok' => false, 'message' => $onDevice
                ? 'This SIM is already attached to a registered device.'
                : 'This SIM is not registered. Add it under Add SIM first.'];
        }

        if ($sim->sim_status !== 'Activated') {
            return ['ok' => false, 'message' => 'This SIM is registered but not Activated yet.'];
        }

        return ['ok' => true, 'sim_number' => $sim->sim_number];
    }

    private function fail(int $index, string $imei, ?string $message): array
    {
        return ['index' => $index, 'imei' => $imei, 'ok' => false, 'message' => $message ?? 'Rejected.'];
    }

    /** 15 digits + Luhn check digit (catches virtually every scanner misread). */
    public static function isValidImei(string $imei): bool
    {
        if (! preg_match('/^\d{15}$/', $imei)) {
            return false;
        }

        $sum = 0;
        foreach (array_reverse(str_split($imei)) as $i => $ch) {
            $d = (int) $ch;
            if ($i % 2 === 1) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }

        return $sum % 10 === 0;
    }
}