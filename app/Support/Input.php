<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * One place that turns raw query-string / form input into a value of a known type.
 *
 * Use it for every value that is NOT run through $request->validate() (search boxes, filters, tabs, page
 * sizes, ?dealer=ID links). Whatever a browser or an attacker sends, the caller always gets a plain,
 * bounded value of the expected type, so:
 *   - ?search[]=x (an array) cannot turn into the string "Array" or throw a 500 that prints a stack trace;
 *   - ?dealer_id=abc cannot reach a bigint column and make the database error out (SQL in the error message);
 *   - LIKE wildcards typed by a user (% and _) search for themselves instead of matching everything.
 *
 * It is NOT an output filter. Values are never HTML-escaped here (that would corrupt names like "O'Brien &
 * Sons" and still not stop injection); Blade's {{ }} escapes on output and the query builder binds on input.
 */
final class Input
{
    /** A short, trimmed, single value. Arrays, objects and null become ''. Control characters are removed. */
    public static function text(mixed $value, int $max = 100): string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return '';
        }

        $clean = self::stripControl((string) $value);

        return mb_substr(trim($clean), 0, max(1, $max));
    }

    /** Escapes % _ and \ so a typed value is matched literally inside LIKE / ILIKE (default escape char is \). */
    public static function like(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** "%value%" ready for a bound LIKE / ILIKE parameter. */
    public static function contains(string $value): string
    {
        return '%' . self::like($value) . '%';
    }

    /** A positive whole number (a database id), or null for anything else (abc, -1, 1.5, [], 99999999999999999999). */
    public static function id(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^[1-9]\d{0,17}$/', $value)) {
            return (int) $value;
        }

        return null;
    }

    /** One of the allowed strings, otherwise the default. Strict comparison; arrays never match. */
    public static function choice(mixed $value, array $allowed, ?string $default = null): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    /** A whole number clamped into [min, max]; anything that is not a number gives the default. */
    public static function int(mixed $value, int $min, int $max, int $default): int
    {
        if (is_int($value)) {
            $n = $value;
        } elseif (is_string($value) && preg_match('/^-?\d{1,9}$/', $value)) {
            $n = (int) $value;
        } else {
            return $default;
        }

        return max($min, min($max, $n));
    }

    /** A real calendar date as Y-m-d, or null ("2026-02-30", "2026-1-1", arrays and junk all give null). */
    public static function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', $value)->format('Y-m-d') === $value ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** A 15 digit IMEI, or null. */
    public static function imei(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{15}$/', $value) ? $value : null;
    }

    /** A UUID in canonical form (lower-cased), or null. */
    public static function uuid(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value)
            ? strtolower($value)
            : null;
    }

    /** Removes NUL and other control characters (keeps tab, newline and carriage return). */
    public static function stripControl(string $value): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    }
}
