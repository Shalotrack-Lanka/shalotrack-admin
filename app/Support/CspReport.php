<?php

namespace App\Support;

/**
 * Turns the body a browser POSTs to /api/csp-report into small, safe rows for the log.
 *
 * The body comes from an anonymous request, so it is treated as hostile: only a fixed list of fields is kept,
 * every value is cut to a short length, control characters are removed (no forged log lines), and the query
 * string / fragment of every URL is dropped (a URL can carry a token or an id). The page's text is never
 * kept: the policy does not ask the browser for script samples.
 *
 * Two body shapes exist: the classic report-uri one {"csp-report": {...}} (what Chrome, Firefox and Safari
 * send for the report-uri directive) and the Reporting API one [{"type":"csp-violation","body":{...}}].
 */
final class CspReport
{
    private const MAX_BODY_BYTES = 16384;
    private const MAX_ROWS       = 10;
    private const MAX_FIELD      = 300;

    /**
     * @return list<array{directive: string, blocked: string, document: string, source: string, line: int, disposition: string}>
     */
    public static function parse(string $raw): array
    {
        if ($raw === '' || strlen($raw) > self::MAX_BODY_BYTES) {
            return [];
        }

        $data = json_decode($raw, true, 8);

        if (! is_array($data)) {
            return [];
        }

        $reports = [];

        if (isset($data['csp-report']) && is_array($data['csp-report'])) {
            $r = $data['csp-report'];
            $reports[] = [
                'directive'   => $r['effective-directive'] ?? $r['violated-directive'] ?? null,
                'blocked'     => $r['blocked-uri'] ?? null,
                'document'    => $r['document-uri'] ?? null,
                'source'      => $r['source-file'] ?? null,
                'line'        => $r['line-number'] ?? 0,
                'disposition' => $r['disposition'] ?? null,
            ];
        } elseif (array_is_list($data)) {
            foreach (array_slice($data, 0, self::MAX_ROWS) as $entry) {
                if (! is_array($entry) || ($entry['type'] ?? null) !== 'csp-violation' || ! is_array($entry['body'] ?? null)) {
                    continue;
                }
                $b = $entry['body'];
                $reports[] = [
                    'directive'   => $b['effectiveDirective'] ?? $b['violatedDirective'] ?? null,
                    'blocked'     => $b['blockedURL'] ?? null,
                    'document'    => $b['documentURL'] ?? null,
                    'source'      => $b['sourceFile'] ?? null,
                    'line'        => $b['lineNumber'] ?? 0,
                    'disposition' => $b['disposition'] ?? null,
                ];
            }
        }

        $rows = [];

        foreach ($reports as $r) {
            $directive = self::clean($r['directive'], 60);

            if ($directive === '') {
                continue; // not a usable violation report
            }

            $rows[] = [
                'directive'   => $directive,
                'blocked'     => self::clean($r['blocked'], self::MAX_FIELD, true),
                'document'    => self::clean($r['document'], self::MAX_FIELD, true),
                'source'      => self::clean($r['source'], self::MAX_FIELD, true),
                'line'        => is_numeric($r['line']) ? max(0, min(10000000, (int) $r['line'])) : 0,
                'disposition' => self::clean($r['disposition'], 12),
            ];
        }

        return $rows;
    }

    private static function clean(mixed $value, int $max, bool $isUrl = false): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $s = (string) $value;

        if ($isUrl) {
            $s = preg_replace('/[?#].*$/s', '', $s) ?? '';
        }

        $s = preg_replace('/[\x00-\x1F\x7F]/', '', $s) ?? '';

        return mb_substr($s, 0, $max);
    }
}
