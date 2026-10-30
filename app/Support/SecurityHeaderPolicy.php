<?php

namespace App\Support;

/**
 * Builds the security header values. Plain PHP on purpose (no framework calls) so the rules can be unit
 * tested and reasoned about on their own; App\Http\Middleware\SecurityHeaders only wires them to responses.
 */
final class SecurityHeaderPolicy
{
    public const MODE_REPORT_ONLY = 'report-only';
    public const MODE_ENFORCE     = 'enforce';
    public const MODE_OFF         = 'off';

    /** A fresh random nonce for one response (base64, as the CSP spec expects). */
    public static function nonce(): string
    {
        return base64_encode(random_bytes(16));
    }

    /** Anything unknown falls back to report-only: never silently enforcing, never silently off. */
    public static function mode(mixed $value): string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($value, [self::MODE_ENFORCE, self::MODE_OFF], true) ? $value : self::MODE_REPORT_ONLY;
    }

    /**
     * @param  array<string, list<string>>  $directives  e.g. ['script-src' => ["'self'", "'nonce-{nonce}'"]]
     */
    public static function csp(array $directives, string $nonce, bool $allowUnsafeEval, ?string $reportUri): string
    {
        $parts = [];

        foreach ($directives as $name => $sources) {
            $sources = array_map(static fn (string $s): string => str_replace('{nonce}', $nonce, $s), array_values($sources));

            if ($name === 'script-src' && $allowUnsafeEval) {
                $sources[] = "'unsafe-eval'";
            }

            $parts[] = trim($name . ' ' . implode(' ', $sources));
        }

        if ($reportUri !== null && $reportUri !== '') {
            $parts[] = 'report-uri ' . $reportUri;
        }

        return implode('; ', $parts);
    }

    /** Strict-Transport-Security value, or null when disabled (max-age 0 or negative). */
    public static function hsts(int $maxAge): ?string
    {
        return $maxAge > 0 ? 'max-age=' . $maxAge : null;
    }
}
