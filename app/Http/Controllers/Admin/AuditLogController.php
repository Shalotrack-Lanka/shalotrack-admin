<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\RenewalsReport;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only viewer for the audit trail (ADMIN only; the route carries role:ADMIN).
 * Nothing here can create, edit or delete an entry.
 */
class AuditLogController extends Controller
{
    private const PER_PAGE = 50;
    private const EXPORT_LIMIT = 5000;

    public function index(Request $request)
    {
        $f = $this->filters($request);

        if ($request->query('export') === 'csv') {
            return $this->csv($this->query($f));
        }

        $rows = $this->query($f)->paginate(self::PER_PAGE)->withQueryString();

        return view('admin.audit-log.index', [
            'rows'       => $rows,
            'f'          => $f,
            'categories' => AuditLog::CATEGORIES,
            'actions'    => AuditLog::ACTIONS,
        ]);
    }

    /** @return array{category:?string,action:?string,actor:string,q:string,from:?string,to:?string} */
    private function filters(Request $request): array
    {
        $category = $request->query('category');
        $action = $request->query('action');

        return [
            'category' => is_string($category) && isset(AuditLog::CATEGORIES[$category]) ? $category : null,
            'action'   => is_string($action) && isset(AuditLog::ACTIONS[$action]) ? $action : null,
            'actor'    => $this->text($request->query('actor')),
            'q'        => $this->text($request->query('q')),
            'from'     => $this->date($request->query('from')),
            'to'       => $this->date($request->query('to')),
        ];
    }

    /** A short trimmed string; anything else (an array from ?q[]=x, say) counts as empty. */
    private function text(mixed $value): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, 100) : '';
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->format('Y-m-d') === $value ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function query(array $f)
    {
        $tz = config('app.display_timezone', 'Asia/Colombo');
        $q = AuditLog::query()->orderByDesc('occurred_at')->orderByDesc('id');

        if ($f['category']) {
            $q->where('category', $f['category']);
        }
        if ($f['action']) {
            $q->where('action', $f['action']);
        }
        if ($f['actor'] !== '') {
            $like = '%' . $this->escapeLike(mb_strtolower($f['actor'])) . '%';
            $q->where(fn ($w) => $w
                ->whereRaw("LOWER(actor_name) LIKE ? ESCAPE '\\'", [$like])
                ->orWhereRaw("LOWER(actor_id) LIKE ? ESCAPE '\\'", [$like]));
        }
        if ($f['q'] !== '') {
            $like = '%' . $this->escapeLike(mb_strtolower($f['q'])) . '%';
            $q->where(fn ($w) => $w
                ->whereRaw("LOWER(subject_label) LIKE ? ESCAPE '\\'", [$like])
                ->orWhereRaw("LOWER(subject_id) LIKE ? ESCAPE '\\'", [$like]));
        }
        // Days are Sri Lanka days; rows are stored in UTC.
        if ($f['from']) {
            $q->where('occurred_at', '>=', Carbon::parse($f['from'], $tz)->startOfDay()->utc());
        }
        if ($f['to']) {
            $q->where('occurred_at', '<', Carbon::parse($f['to'], $tz)->addDay()->startOfDay()->utc());
        }

        return $q;
    }

    private function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    private function csv($query): StreamedResponse
    {
        $name = 'audit-log-' . now()->local()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['When (Sri Lanka)', 'Who', 'Role', 'Action', 'Subject', 'Changes', 'Details', 'IP']);

            foreach ($query->limit(self::EXPORT_LIMIT)->cursor() as $r) {
                fputcsv($out, [
                    $r->occurred_at->copy()->local()->format('Y-m-d H:i:s'),
                    RenewalsReport::csvSafe($r->actor_name ?: ($r->actor_id ?: 'system / not signed in')),
                    RenewalsReport::csvSafe($r->actor_role),
                    RenewalsReport::csvSafe(AuditLog::labelFor($r->action)),
                    RenewalsReport::csvSafe(trim(($r->subject_type ? $r->subject_type . ' ' : '') . ($r->subject_label ?: $r->subject_id))),
                    RenewalsReport::csvSafe($r->changes ? json_encode($r->changes, JSON_UNESCAPED_UNICODE) : ''),
                    RenewalsReport::csvSafe($r->meta ? json_encode($r->meta, JSON_UNESCAPED_UNICODE) : ''),
                    RenewalsReport::csvSafe($r->ip),
                ]);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}