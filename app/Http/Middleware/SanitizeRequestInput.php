<?php

namespace App\Http\Middleware;

use App\Support\Input;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline hygiene for every web request, before any controller sees it.
 *
 *  - Removes NUL and other control characters from every text value (query string, form fields, JSON body).
 *    NUL bytes in particular are a classic way to truncate a value in a downstream parser, file function or
 *    log line. Tab, newline and carriage return are kept (multi-line notes).
 *  - Refuses absurdly deep arrays (?a[b][c][d][e][f][g][h]=1): no screen needs more than a few levels, and
 *    deep nesting is a cheap way to burn CPU in validators and loops.
 *  - Leaves password fields exactly as typed. A password may legitimately contain any character and must
 *    never be altered, otherwise the stored hash would not match what the person typed.
 *
 * Laravel's TrimStrings and ConvertEmptyStringsToNull middleware already trim and null empty strings.
 * This does NOT HTML-escape anything: that would corrupt data ("O'Brien & Sons") without stopping any
 * injection. SQL injection is stopped by bound parameters, XSS by escaping on output, and the shape of each
 * value by validation (see App\Support\Input and the per-form validate() rules).
 */
class SanitizeRequestInput
{
    private const MAX_DEPTH = 6;

    /** Field names that are never touched. */
    private const UNTOUCHED = '/pass(word|wd)?|secret|^_token$/i';

    public function handle(Request $request, Closure $next): Response
    {
        $query = $request->query->all();
        $body = $request->request->all();
        $json = $request->isJson() ? $request->json()->all() : [];

        if ($this->tooDeep($query) || $this->tooDeep($body) || $this->tooDeep($json)) {
            abort(422, 'The request contains data nested too deeply.');
        }

        $request->query->replace($this->clean($query));
        $request->request->replace($this->clean($body));

        if ($request->isJson()) {
            $request->json()->replace($this->clean($json));
        }

        return $next($request);
    }

    private function clean(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::UNTOUCHED, $key)) {
                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->clean($value);
            } elseif (is_string($value)) {
                $data[$key] = Input::stripControl($value);
            }
        }

        return $data;
    }

    private function tooDeep(array $data, int $depth = 1): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return true;
        }

        foreach ($data as $value) {
            if (is_array($value) && $this->tooDeep($value, $depth + 1)) {
                return true;
            }
        }

        return false;
    }
}
