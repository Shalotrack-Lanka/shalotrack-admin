<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Reads the CSP violation log and prints what the browsers reported, most frequent first.
 *
 *   php artisan csp:summary              today and yesterday
 *   php artisan csp:summary --days=7
 *
 * One row per distinct (directive, blocked, source). "Seen" counts logged reports, and identical reports are
 * logged at most once per hour, so it is "in how many hours" rather than "how many page views".
 * An empty result after a week of normal use on every role's pages means the policy is ready to enforce.
 */
class CspSummary extends Command
{
    protected $signature = 'csp:summary {--days=2 : How many days of log files to read} {--limit=60 : Maximum rows to print} {--path= : Log directory (default storage/logs)}';

    protected $description = 'Summarise Content-Security-Policy violation reports from the csp log';

    public function handle(): int
    {
        $dir   = rtrim((string) ($this->option('path') ?: storage_path('logs')), '/\\');
        $days  = max(1, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));

        $files = [];
        for ($i = 0; $i < $days; $i++) {
            $file = $dir . '/csp-' . now()->subDays($i)->format('Y-m-d') . '.log';
            if (is_file($file)) {
                $files[] = $file;
            }
        }

        if ($files === []) {
            $this->info('No CSP log files found for the last ' . $days . ' day(s).');

            return self::SUCCESS;
        }

        $groups = [];

        foreach ($files as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (! preg_match('/csp-violation (\{.*\})/', $line, $m)) {
                    continue;
                }
                $row = json_decode($m[1], true);
                if (! is_array($row) || ! isset($row['directive'])) {
                    continue;
                }

                $where = ($row['source'] ?? '') !== '' ? ($row['source'] . ':' . ($row['line'] ?? 0)) : ($row['document'] ?? '');
                $key   = ($row['directive'] ?? '') . '|' . ($row['blocked'] ?? '') . '|' . $where;

                $groups[$key] ??= [
                    'directive' => (string) $row['directive'],
                    'blocked'   => (string) ($row['blocked'] ?? ''),
                    'where'     => (string) $where,
                    'seen'      => 0,
                ];
                $groups[$key]['seen']++;
            }
        }

        if ($groups === []) {
            $this->info('CSP log files exist but contain no violations.');

            return self::SUCCESS;
        }

        usort($groups, fn (array $a, array $b) => $b['seen'] <=> $a['seen']);

        $this->table(
            ['Directive', 'Blocked', 'Where', 'Seen'],
            array_map(fn (array $g) => [$g['directive'], $g['blocked'] ?: '(inline)', $g['where'], $g['seen']], array_slice($groups, 0, $limit))
        );

        $this->line(count($groups) . ' distinct violation(s) in ' . count($files) . ' file(s).');

        return self::SUCCESS;
    }
}
