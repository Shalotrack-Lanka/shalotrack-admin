<?php

namespace App\Services;

use App\Models\DeviceSyncState;
use App\Models\SetupShalotrackDevice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the API's copy of every device (DB2 SetupShalotrackDevices) equal to this database.
 *
 *  - payload():       the one place the API payload is built (screens, backfill and the queue share it).
 *  - send():          one push; records success or failure in device_sync_state.
 *  - markPending():   "this device changed, send it" (called by the model observers).
 *  - processDue():    scheduled; sends pending devices, retrying with a growing delay.
 *  - scanDrift():     scheduled hourly; re-checks every device against what the API last accepted, so a
 *                     change made by a raw query, an import or an unbind can never be missed for long.
 *
 * Nothing here throws into a caller: the device is already saved, a sync problem must never undo or
 * block the office's work.
 */
class DeviceSync
{
    /** Minutes to wait after the 1st, 2nd, 3rd ... failure. The last value repeats. */
    public const BACKOFF_MINUTES = [1, 2, 5, 15, 60];

    /** After this many failures the dashboard shows a warning. */
    public const WARN_AFTER_ATTEMPTS = 5;

    private static ?bool $tableExists = null;

    private static ?bool $bindTable = null;

    private static function hasBindTable(): bool
    {
        return self::$bindTable ??= Schema::hasTable('activated_devices');
    }

    public static function enabled(): bool
    {
        return self::$tableExists ??= Schema::hasTable('device_sync_state');
    }

    /** For tests that create the table after the first check. */
    public static function forgetTableCheck(): void
    {
        self::$tableExists = null;
        self::$bindTable = null;
    }

    /**
     * @param array<string, string|null>|null $boundMap imei => bind time; pass it when building many payloads
     */
    public static function payload(SetupShalotrackDevice $d, ?array $boundMap = null): array
    {
        $boundAt = $boundMap !== null
            ? ($boundMap[$d->imei_number] ?? null)
            : (self::hasBindTable() ? DB::table('activated_devices')->where('imei_number', $d->imei_number)->min('created_at') : null);

        return [
            'id'             => $d->shdevice_id,
            'deviceCategory' => $d->device_category,
            'imeiNumber'     => $d->imei_number,
            'simNumber'      => $d->sim_number,
            'status'         => $d->status,
            'cancelReason'   => $d->cancel_reason,
            'canceledDate'   => $d->canceled_date,
            'dealerId'       => $d->dealer_id,
            'deviceTypeId'   => $d->device_type_id,
            'isBound'        => $boundAt !== null,
            'boundAt'        => $boundAt !== null ? Carbon::parse($boundAt, 'UTC')->utc()->toIso8601String() : null,
            'createdAt'      => $d->created_at,
            'updatedAt'      => $d->updated_at,
        ];
    }

    /** Fingerprint of what the API cares about (timestamps left out so touching a row does not re-send it). */
    public static function fingerprint(array $payload): string
    {
        unset($payload['createdAt'], $payload['updatedAt']);

        return sha1(json_encode($payload));
    }

    /** @return array<string, string> imei => first bind time */
    public static function boundMap(): array
    {
        return DB::table('activated_devices')
            ->whereNotNull('imei_number')
            ->groupBy('imei_number')
            ->selectRaw('imei_number, min(created_at) as bound_at')
            ->pluck('bound_at', 'imei_number')
            ->all();
    }

    // ---- sending -----------------------------------------------------------------------------

    /** Pushes one device right now. True = the API accepted it. */
    public static function send(SetupShalotrackDevice $device): bool
    {
        $startedAt = now();
        $payload = self::payload($device);

        try {
            $response = Http::timeout(10)
                ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
                ->acceptJson()
                ->post(config('services.shalotrack_api.base_url') . '/api/internal/setup-devices-sync', $payload);

            if ($response->successful()) {
                self::recordSuccess($device, $payload, $startedAt);

                return true;
            }

            $error = 'HTTP ' . $response->status();
            Log::error('Device push to API failed', [
                'imei' => $device->imei_number, 'status' => $response->status(), 'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 200);
            Log::error('Device push to API threw an exception', [
                'imei' => $device->imei_number, 'error' => $e->getMessage(),
            ]);
        }

        self::recordFailure($device, $error);

        return false;
    }

    private static function recordSuccess(SetupShalotrackDevice $device, array $payload, Carbon $startedAt): void
    {
        if (! self::enabled()) {
            return;
        }
        try {
            $row = self::row($device);
            // Only clear "pending" if nothing changed while the request was in flight.
            // pending_since is kept to the microsecond (see micro()); a later one means the device changed
            // while the request was in flight, so it must be sent again.
            $stillPending = $row->pending_since !== null && $row->pending_since > self::micro($startedAt);
            $row->forceFill([
                'pending'          => $stillPending,
                'attempts'         => 0,
                'next_attempt_at'  => $stillPending ? now() : null,
                'last_error'       => null,
                'last_synced_hash' => self::fingerprint($payload),
                'last_synced_at'   => now(),
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('device_sync_state write failed', ['error' => $e->getMessage()]);
        }
    }

    private static function recordFailure(SetupShalotrackDevice $device, string $error): void
    {
        if (! self::enabled()) {
            return;
        }
        try {
            $row = self::row($device);
            $attempts = $row->attempts + 1;
            $wait = self::BACKOFF_MINUTES[min($attempts, count(self::BACKOFF_MINUTES)) - 1];
            $row->forceFill([
                'pending'         => true,
                'pending_since'   => $row->pending_since ?? self::micro(now()),
                'attempts'        => $attempts,
                'next_attempt_at' => now()->addMinutes($wait),
                'last_error'      => mb_substr($error, 0, 300),
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('device_sync_state write failed', ['error' => $e->getMessage()]);
        }
    }

    /** Eloquent's date format drops microseconds, so pending_since is stored as a plain string with them. */
    private static function micro(Carbon $t): string
    {
        return $t->copy()->utc()->format('Y-m-d H:i:s.u');
    }

    private static function row(SetupShalotrackDevice $device): DeviceSyncState
    {
        return DeviceSyncState::firstOrNew(['shdevice_id' => $device->shdevice_id], [
            'imei_number' => $device->imei_number,
            'pending'     => true,
            'attempts'    => 0,
        ])->forceFill(['imei_number' => $device->imei_number]);
    }

    // ---- queue -------------------------------------------------------------------------------

    /** Marks a device as needing a push. Cheap, never throws. Retry counters restart because the data changed. */
    public static function markPending(int $shdeviceId, string $imei): void
    {
        if (! self::enabled()) {
            return;
        }
        try {
            $row = DeviceSyncState::firstOrNew(['shdevice_id' => $shdeviceId]);
            $row->forceFill([
                'imei_number'     => $imei,
                'pending'         => true,
                'pending_since'   => self::micro(now()),
                'attempts'        => 0,
                'next_attempt_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('device_sync_state mark failed', ['error' => $e->getMessage()]);
        }
    }

    public static function markPendingByImei(?string $imei): void
    {
        if (! self::enabled() || $imei === null || $imei === '') {
            return;
        }
        $device = SetupShalotrackDevice::where('imei_number', $imei)->first();
        if ($device) {
            self::markPending($device->shdevice_id, $device->imei_number);
        }
    }

    /**
     * Sends devices that are pending and due. Stops after $budgetSeconds so one run never overlaps the
     * next minute's run. @return array{sent:int, failed:int, remaining:int}
     */
    public static function processDue(int $budgetSeconds = 45): array
    {
        $sent = $failed = 0;
        if (! self::enabled()) {
            return ['sent' => 0, 'failed' => 0, 'remaining' => 0];
        }
        $deadline = microtime(true) + $budgetSeconds;

        $due = fn () => DeviceSyncState::where('pending', true)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('pending_since');

        foreach ($due()->limit(500)->get() as $state) {
            if (microtime(true) > $deadline) {
                break;
            }
            $device = SetupShalotrackDevice::find($state->shdevice_id);
            if (! $device) {
                // Device no longer exists here; nothing to send.
                $state->delete();
                continue;
            }
            self::send($device) ? $sent++ : $failed++;
        }

        return ['sent' => $sent, 'failed' => $failed, 'remaining' => $due()->count()];
    }

    /**
     * Compares every device with what the API last accepted and queues the ones that differ.
     * @return int how many devices were queued
     */
    public static function scanDrift(): int
    {
        if (! self::enabled()) {
            return 0;
        }
        $bound = self::boundMap();
        $known = DeviceSyncState::pluck('last_synced_hash', 'shdevice_id')->all();
        $queued = 0;

        SetupShalotrackDevice::query()->chunkById(500, function ($devices) use ($bound, $known, &$queued) {
            foreach ($devices as $d) {
                $hash = self::fingerprint(self::payload($d, $bound));
                if (($known[$d->shdevice_id] ?? null) !== $hash) {
                    self::markPending($d->shdevice_id, $d->imei_number);
                    $queued++;
                }
            }
        }, 'shdevice_id');

        return $queued;
    }

    // ---- dashboard ---------------------------------------------------------------------------

    /** Devices that keep failing to reach the API. */
    public static function stuckCount(): int
    {
        if (! self::enabled()) {
            return 0;
        }
        try {
            return DeviceSyncState::where('pending', true)->where('attempts', '>=', self::WARN_AFTER_ATTEMPTS)->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
