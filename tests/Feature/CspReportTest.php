<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The CSP report collector is public (browsers send reports without cookies), so these tests check that it
 * logs real reports with only safe fields, ignores rubbish, and cannot be used to flood the log.
 */
class CspReportTest extends TestCase
{
    private string $logFile;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = sys_get_temp_dir() . '/csp-test-' . bin2hex(random_bytes(6)) . '.log';

        config([
            'logging.channels.csp'              => ['driver' => 'single', 'path' => $this->logFile, 'level' => 'debug'],
            'security.csp.report_max_per_minute' => 120,
            'security.csp.report_max_per_day'    => 20000,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
        foreach ($this->tempDirs as $dir) {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }

        parent::tearDown();
    }

    /** What Chrome / Firefox / Safari send for a report-uri directive. */
    private function report(array $overrides = []): array
    {
        return ['csp-report' => array_merge([
            'document-uri'        => 'https://erp.shalotrack.com/admin/dashboard?reset=TOKEN123',
            'effective-directive' => 'script-src-elem',
            'violated-directive'  => 'script-src-elem',
            'blocked-uri'         => 'inline',
            'source-file'         => 'https://erp.shalotrack.com/admin/dashboard',
            'line-number'         => 12,
            'disposition'         => 'report',
        ], $overrides)];
    }

    private function send(array|string $body, string $type = 'application/csp-report')
    {
        return $this->call(
            'POST',
            '/api/csp-report',
            [],
            [],
            [],
            ['CONTENT_TYPE' => $type, 'HTTP_ACCEPT' => '*/*'],
            is_array($body) ? json_encode($body) : $body
        );
    }

    private function logged(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    public function test_a_real_report_is_logged_with_only_safe_fields(): void
    {
        $response = $this->send($this->report());

        $response->assertNoContent();
        $this->assertSame([], $response->headers->getCookies(), 'the collector must not start a session');

        $log = $this->logged();
        $this->assertStringContainsString('csp-violation', $log);
        $this->assertStringContainsString('script-src-elem', $log);
        $this->assertStringContainsString('erp.shalotrack.com', $log);
        $this->assertStringNotContainsString('TOKEN123', $log, 'query strings can carry tokens and must be dropped');
        $this->assertStringNotContainsString('reset=', $log);
    }

    public function test_the_reporting_api_format_is_logged_too(): void
    {
        $this->send([
            ['type' => 'csp-violation', 'body' => [
                'effectiveDirective' => 'img-src',
                'blockedURL'         => 'https://evil.example.test/p.png?x=1',
                'documentURL'        => 'https://erp.shalotrack.com/admin/dashboard',
                'lineNumber'         => 0,
                'disposition'        => 'report',
            ]],
            ['type' => 'deprecation', 'body' => ['id' => 'ignored']],
        ], 'application/reports+json')->assertNoContent();

        $log = $this->logged();
        $this->assertStringContainsString('img-src', $log);
        $this->assertStringContainsString('evil.example.test', $log);
        $this->assertStringNotContainsString('x=1', $log);
        $this->assertSame(1, substr_count($log, 'csp-violation'));
    }

    public function test_rubbish_is_ignored_and_still_answers_204(): void
    {
        foreach (['', 'not json at all', '[]', '"text"', '{"csp-report":{}}', '{"csp-report":{"blocked-uri":"x"}}', str_repeat('x', 20000)] as $body) {
            $this->send($body)->assertNoContent();
        }

        $this->assertSame('', $this->logged());
    }

    public function test_a_forged_report_cannot_inject_extra_log_lines(): void
    {
        $this->send($this->report([
            'effective-directive' => "script-src\n[2026-01-01 00:00:00] production.CRITICAL: forged entry",
            'blocked-uri'         => "https://a.example.test/x\r\n[2026-01-01 00:00:00] production.CRITICAL: forged",
        ]))->assertNoContent();

        $lines = array_filter(explode("\n", $this->logged()), fn ($l) => trim($l) !== '');

        $this->assertCount(1, $lines, 'one report must be exactly one log line');
    }

    public function test_the_same_report_is_logged_only_once(): void
    {
        $this->send($this->report())->assertNoContent();
        $this->send($this->report())->assertNoContent();
        $this->send($this->report(['line-number' => 99]))->assertNoContent(); // a different script line is a different report

        $this->assertSame(2, substr_count($this->logged(), 'csp-violation'));
    }

    public function test_the_per_minute_cap_stops_a_flood(): void
    {
        config(['security.csp.report_max_per_minute' => 2]);

        foreach ([1, 2, 3, 4, 5] as $line) {
            $this->send($this->report(['line-number' => $line]))->assertNoContent();
        }

        $this->assertSame(2, substr_count($this->logged(), 'csp-violation'));
    }

    public function test_the_per_day_cap_stops_the_log_growing_forever(): void
    {
        config(['security.csp.report_max_per_day' => 1]);

        foreach ([1, 2, 3] as $line) {
            $this->send($this->report(['line-number' => $line]))->assertNoContent();
        }

        $this->assertSame(1, substr_count($this->logged(), 'csp-violation'));
    }

    public function test_summary_command_groups_and_ranks_violations(): void
    {
        $dir = sys_get_temp_dir() . '/csp-summary-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $this->tempDirs[] = $dir;

        $line = fn (array $row) => '[' . now()->format('Y-m-d H:i:s') . '] testing.WARNING: csp-violation ' . json_encode($row, JSON_UNESCAPED_SLASHES) . ' []';
        $a = ['directive' => 'script-src-elem', 'blocked' => 'inline', 'document' => 'https://erp.shalotrack.com/admin/dashboard', 'source' => 'https://erp.shalotrack.com/admin/dashboard', 'line' => 12, 'disposition' => 'report'];
        $b = ['directive' => 'img-src', 'blocked' => 'https://evil.example.test/p.png', 'document' => 'https://erp.shalotrack.com/admin/dashboard', 'source' => '', 'line' => 0, 'disposition' => 'report'];

        file_put_contents(
            $dir . '/csp-' . now()->format('Y-m-d') . '.log',
            implode("\n", [$line($a), $line($b), $line($a), 'a line that is not a violation', $line($a)]) . "\n"
        );

        $this->assertSame(0, Artisan::call('csp:summary', ['--path' => $dir]));
        $out = Artisan::output();

        $this->assertStringContainsString('script-src-elem', $out);
        $this->assertStringContainsString('evil.example.test', $out);
        $this->assertStringContainsString('2 distinct violation(s) in 1 file(s).', $out);
        $this->assertLessThan(strpos($out, 'img-src'), strpos($out, 'script-src-elem'), 'most frequent violation first');
    }

    public function test_summary_command_handles_a_missing_log(): void
    {
        $dir = sys_get_temp_dir() . '/csp-empty-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $this->tempDirs[] = $dir;

        $this->assertSame(0, Artisan::call('csp:summary', ['--path' => $dir]));
        $this->assertStringContainsString('No CSP log files found', Artisan::output());
    }
}
