<?php

namespace App\Services;

use App\Models\AuditLog;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes audit-trail entries.
 *
 *  - NEVER breaks the request: a failed audit write is logged and swallowed. (Call it AFTER the
 *    business transaction commits: on Postgres a failed statement inside a transaction would poison it.)
 *  - NEVER stores secrets: values under sensitive-looking keys are replaced, long strings are cut.
 *  - Callers pass only what changed (see diff()), so the trail shows "from -> to", not whole rows.
 */
class Audit
{
    private const SENSITIVE_KEY = '/pass|token|secret|otp|api[_-]?key|authorization|cookie|session/i';
    private const MAX_STRING = 500;

    /**
     * @param array<string,mixed> $changes  {field: {from, to}} (use diff())
     * @param array<string,mixed> $meta
     */
    public static function record(
        string $action,
        ?string $subjectType = null,
        string|int|null $subjectId = null,
        ?string $subjectLabel = null,
        array $changes = [],
        array $meta = [],
    ): void {
        try {
            $user = auth()->user();
            $request = app()->bound('request') ? request() : null;

            AuditLog::create([
                'occurred_at'   => now(),
                'actor_id'      => $user?->admin_id !== null ? mb_substr((string) $user->admin_id, 0, 64) : null,
                'actor_name'    => $user ? mb_substr((string) ($user->full_name ?: $user->username), 0, 150) : null,
                'actor_role'    => $user?->role ? mb_substr((string) $user->role, 0, 20) : null,
                'category'      => AuditLog::ACTIONS[$action][0] ?? 'other',
                'action'        => mb_substr($action, 0, 60),
                'subject_type'  => $subjectType ? mb_substr($subjectType, 0, 40) : null,
                'subject_id'    => $subjectId !== null ? mb_substr((string) $subjectId, 0, 100) : null,
                'subject_label' => $subjectLabel !== null ? mb_substr($subjectLabel, 0, 150) : null,
                'changes'       => $changes === [] ? null : self::clean($changes),
                'meta'          => $meta === [] ? null : self::clean($meta),
                'ip'            => $request && ! app()->runningInConsole() ? mb_substr((string) $request->ip(), 0, 45) : null,
                'user_agent'    => $request && ! app()->runningInConsole() ? mb_substr((string) $request->userAgent(), 0, 200) : null,
            ]);
        } catch (Throwable $e) {
            Log::error('Audit write failed', ['action' => $action, 'subject' => $subjectId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Only the fields that really changed, as {field: {from, to}}.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @param array<int,string>   $keys   fields to compare
     * @return array<string,array{from:mixed,to:mixed}>
     */
    public static function diff(array $before, array $after, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $from = self::scalar($before[$key] ?? null);
            $to = self::scalar($after[$key] ?? null);
            if ($from !== $to) {
                $out[$key] = ['from' => $from, 'to' => $to];
            }
        }

        return $out;
    }

    /** Dates become ISO strings, enums their value, so "same moment" compares equal. */
    private static function scalar(mixed $v): mixed
    {
        return match (true) {
            $v instanceof DateTimeInterface => $v->format('Y-m-d H:i:s'),
            $v instanceof BackedEnum        => $v->value,
            is_bool($v) || is_int($v) || is_float($v) || $v === null => $v,
            default                          => (string) $v,
        };
    }

    private static function clean(mixed $value, ?string $key = null): mixed
    {
        // A true/false flag such as password_changed carries no secret, so it stays readable.
        if ($key !== null && ! is_bool($value) && $value !== null && preg_match(self::SENSITIVE_KEY, $key)) {
            return '[redacted]';
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::clean($v, is_string($k) ? $k : null);
            }

            return $out;
        }
        $value = self::scalar($value);

        return is_string($value) && mb_strlen($value) > self::MAX_STRING ? mb_substr($value, 0, self::MAX_STRING) . '…' : $value;
    }
}