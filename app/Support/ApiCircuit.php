<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Keeps the portal fast when the mobile (C#) API is down.
 *
 * The admin sidebar asks the API for the complaint count on EVERY page, and the dashboard asks it twice
 * more. With the API down, each of those calls waited up to 5 seconds, so every page was slow and the
 * dashboard could run past PHP's time limit and show a 500 error, exactly when an admin needs it.
 *
 * Now: a call that fails to CONNECT (refused, timed out, no route) marks the API as unreachable for
 * 30 seconds. During that time the portal does not try again; callers get ApiRecentlyUnreachable at once
 * and show "unavailable" or 0, as they already do for a failed call. After 30 seconds one call tries again.
 * An API that ANSWERS with an error (HTTP 4xx or 5xx) is reachable, so it does not open this.
 * The connect timeout is also short (2 seconds): the API lives in the same network, a slow connect means down.
 */
final class ApiCircuit
{
    public const CONNECT_TIMEOUT_SECONDS = 2;

    public const COOLDOWN_SECONDS = 30;

    private const KEY = 'outside:mobile-api:unreachable';

    /** HTTP client for the mobile API: short connect timeout and the shared key header. */
    public static function http(int $timeoutSeconds = 5): PendingRequest
    {
        return Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout($timeoutSeconds)
            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')]);
    }

    /**
     * Run an API call. Throws ApiRecentlyUnreachable without calling when the API was just found unreachable;
     * a connection failure opens the circuit and is re-thrown. Anything else passes through untouched.
     */
    public static function run(callable $call): mixed
    {
        if (self::isOpen()) {
            throw new ApiRecentlyUnreachable('The mobile API was unreachable a moment ago; not trying again for ' . self::COOLDOWN_SECONDS . ' seconds.');
        }

        try {
            return $call();
        } catch (ConnectionException $e) {
            self::open();

            throw $e;
        }
    }

    public static function isOpen(): bool
    {
        try {
            return (bool) Cache::get(self::KEY, false);
        } catch (\Throwable) {
            return false; // a broken cache must never make the portal worse: try the call
        }
    }

    public static function reset(): void
    {
        try {
            Cache::forget(self::KEY);
        } catch (\Throwable) {
        }
    }

    private static function open(): void
    {
        try {
            Cache::put(self::KEY, true, self::COOLDOWN_SECONDS);
        } catch (\Throwable) {
        }
    }
}
