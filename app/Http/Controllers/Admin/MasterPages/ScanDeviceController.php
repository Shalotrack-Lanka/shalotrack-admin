<?php

namespace App\Http\Controllers\Admin\MasterPages;

use App\Services\Audit;
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
        $simTypes = Sim::query()->distinct()->orderBy('sim_type')->pluck('sim_type');

        return view('admin.master_pages.scan_device', compact('deviceTypes', 'simTypes'));
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

        if ($created !== []) {
            Audit::record('device.intake_committed', 'device_type', $data['device_type_id'], null, [], [
                'submitted' => count($data['rows']),
                'created'   => count($created),
                'imeis'     => array_slice(array_map(fn ($d) => $d->imei_number, $created), 0, 50),
            ]);
        }

        // Same non-fatal sync to the API that manual setup and bulk import do.
        $syncFailed = 0;
        foreach ($created as $device) {
            if (!$this->pushDeviceToApi($device)) {
                $syncFailed++;
            }
        }

        return response()->json([
            'created' => count($created),
            'failed'  => count($results) - count($created),
            'sync_failed' => $syncFailed,
            'results' => $results,
        ]);
    }

    /**
     * One-off SIM registration from the scan page, for a scanned ICCID that
     * isn't in the SIM list yet. Same rules as AddSimController::store(), but
     * the SIM is saved as Activated, so the admin must explicitly confirm the
     * carrier has activated it (`confirm_activated`). For a full box of SIMs
     * the bulk import on the Add SIM page is the better route.
     */
    public function quickAddSim(Request $request): JsonResponse
    {
        $data = $request->validate([
            'iccid'             => ['required', 'digits_between:19,20', 'unique:sims,iccid'],
            'sim_number'        => ['required', 'digits:10', 'unique:sims,sim_number'],
            'imsi'              => ['required', 'digits:15', 'unique:sims,imsi'],
            'sim_type'          => ['required', 'string', 'max:255'],
            'confirm_activated' => ['accepted'],
        ], [
            'iccid.digits_between'      => 'ICCID must be 19 or 20 digits.',
            'iccid.unique'              => 'This ICCID is already registered.',
            'sim_number.digits'         => 'SIM number must be exactly 10 digits.',
            'sim_number.unique'         => 'This SIM number is already registered.',
            'imsi.digits'               => 'IMSI must be exactly 15 digits.',
            'imsi.unique'               => 'This IMSI is already registered.',
            'confirm_activated.accepted' => 'Confirm that the carrier has activated this SIM.',
        ]);

        try {
            $sim = Sim::create([
                'sim_number'          => $data['sim_number'],
                'sim_type'            => trim($data['sim_type']),
                'imsi'                => $data['imsi'],
                'iccid'               => $data['iccid'],
                'activation_required' => false,
                'sim_status'          => 'Activated',
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // The UNIQUE indexes are the real guard if someone else added it a moment ago.
            Log::warning('Scan intake SIM quick-add rejected by database', ['iccid' => $data['iccid'], 'error' => $e->getMessage()]);

            return response()->json([
                'message' => 'This SIM could not be saved.',
                'errors'  => ['iccid' => ['This SIM (or its number/IMSI) was just registered by someone else.']],
            ], 422);
        }

        Log::info('SIM registered from scan intake', [
            'admin_id' => $request->user()?->getAuthIdentifier(),
            'iccid'    => $sim->iccid,
            'ip'       => $request->ip(),
        ]);

        return response()->json(['ok' => true, 'sim_number' => $sim->sim_number, 'iccid' => $sim->iccid], 201);
    }

    private function checkIccid(string $iccid): array
    {
        if (! preg_match('/^\d{19,20}$/', $iccid)) {
            return ['ok' => false, 'reason' => 'invalid', 'message' => 'Not a valid SIM ICCID (19–20 digits) — rescan.'];
        }

        $sim = Sim::where('iccid', $iccid)->first();

        if (! $sim) {
            // Either already consumed by a device, or never added to the SIM master.
            $onDevice = SetupShalotrackDevice::where('iccid', $iccid)->exists();

            return $onDevice
                ? ['ok' => false, 'reason' => 'in_use', 'message' => 'This SIM is already attached to a registered device.']
                : ['ok' => false, 'reason' => 'not_registered', 'message' => 'This SIM is not registered yet.'];
        }

        if ($sim->sim_status !== 'Activated') {
            return ['ok' => false, 'reason' => 'not_activated', 'message' => 'This SIM is registered but not Activated yet.'];
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