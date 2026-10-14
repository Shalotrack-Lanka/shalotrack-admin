<?php

namespace App\Http\Middleware;

use App\Services\Audit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Automatic activity record for the whole portal (all roles).
 *
 * For every request a signed-in user makes that CHANGES something (POST / PUT / PATCH / DELETE) or
 * DOWNLOADS a file (CSV, PDF ...), one audit entry is written, unless the controller already wrote a
 * detailed one for that request (Audit::record flags it). So screens with their own entry keep their
 * rich "from -> to" detail, and everything else is still covered: who, when, which screen, what was
 * submitted, and whether it went through.
 *
 *  - Runs in terminate(): AFTER the response has been sent, so it can never slow down or break a page.
 *  - Never stores secrets: Audit::clean() redacts passwords, tokens, national ID numbers and the like;
 *    long values are cut; uploaded files are recorded by name and size only, never their content.
 *  - Plain page views are NOT recorded (the sidebar polls every second; it would drown the table).
 */
class AuditAdminActivity
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** Never recorded here: handled by AuthAudit, or a read-only lookup that happens to use POST. */
    private const SKIP_PATHS = [
        'login', 'logout', 'forgot-password', 'reset-password',
        'admin/master-pages/setup-device/scan/check',
    ];

    private const MAX_FIELDS = 40;
    private const MAX_INPUT_BYTES = 4000;

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $this->record($request, $response);
        } catch (Throwable $e) {
            Log::error('Activity audit failed', ['error' => $e->getMessage()]);
        }
    }

    private function record(Request $request, Response $response): void
    {
        if (! auth()->check() || $request->attributes->get('audit.recorded')) {
            return;
        }

        $path = trim($request->path(), '/');
        if (in_array($path, self::SKIP_PATHS, true)) {
            return;
        }

        $isWrite = in_array($request->method(), self::WRITE_METHODS, true);
        $disposition = (string) $response->headers->get('Content-Disposition', '');
        $isDownload = $request->isMethod('GET') && $response->isSuccessful()
            && str_starts_with(strtolower($disposition), 'attachment');

        if (! $isWrite && ! $isDownload) {
            return;
        }

        $meta = ['status' => $response->getStatusCode()];

        if ($isWrite) {
            $meta['outcome'] = $this->outcome($request, $response);

            $input = $this->input($request);
            if ($input !== []) {
                $meta['input'] = $input;
            }

            $files = $this->files($request);
            if ($files !== []) {
                $meta['files'] = $files;
            }
        } else {
            if (preg_match('/filename="?([^";]+)"?/i', $disposition, $m)) {
                $meta['file'] = mb_substr($m[1], 0, 120);
            }
            if ($request->query() !== []) {
                $meta['filters'] = array_slice($request->query(), 0, self::MAX_FIELDS, true);
            }
        }

        Audit::record(
            $isWrite ? 'request.write' : 'data.downloaded',
            $request->method(),
            $request->route()?->getName(),
            $path,
            [],
            $meta,
        );
    }

    private function outcome(Request $request, Response $response): string
    {
        $status = $response->getStatusCode();

        return match (true) {
            $status >= 500 => 'failed (server error)',
            $status === 403 => 'blocked (no permission)',
            $status === 419 => 'blocked (expired form)',
            $status === 422 => 'refused (validation)',
            $status >= 400 => 'failed',
            // A redirect back to the form with messages: the screen refused it.
            $request->hasSession() && $request->session()->has('errors') => 'refused (message shown to the user)',
            default => 'done',
        };
    }

    /** @return array<string,mixed> submitted fields, minus the form token; Audit::record() redacts the rest */
    private function input(Request $request): array
    {
        $data = $request->input();
        unset($data['_token'], $data['_method']);

        if ($data === []) {
            return [];
        }

        $encoded = json_encode($data);
        if ($encoded !== false && strlen($encoded) > self::MAX_INPUT_BYTES) {
            return ['fields' => array_slice(array_keys($data), 0, self::MAX_FIELDS), 'note' => 'too large to store; field names only'];
        }

        if (count($data) > self::MAX_FIELDS) {
            $extra = count($data) - self::MAX_FIELDS;
            $data = array_slice($data, 0, self::MAX_FIELDS, true);
            $data['…'] = "{$extra} more fields not shown";
        }

        return $data;
    }

    /** @return array<int,string> "name.csv (12 KB)" for each uploaded file; never the content */
    private function files(Request $request): array
    {
        $out = [];
        foreach (Arr::flatten($request->allFiles()) as $file) {
            if (! method_exists($file, 'getClientOriginalName')) {
                continue;
            }
            try {
                $kb = max(1, (int) round(((int) $file->getSize()) / 1024));
            } catch (Throwable) {
                $kb = 0; // the temp file is already gone (it was stored or moved)
            }
            $out[] = mb_substr((string) $file->getClientOriginalName(), 0, 100) . ($kb > 0 ? " ({$kb} KB)" : '');
        }

        return array_slice($out, 0, 10);
    }
}