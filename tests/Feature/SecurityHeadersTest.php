<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

/**
 * Security regression: every response carries the browser-hardening headers, and the Content-Security-Policy
 * only REPORTS (never blocks) until CSP_MODE=enforce is chosen on purpose. See config/security.php.
 */
class SecurityHeadersTest extends TestCase
{
    private const CSP_REPORT_ONLY = 'Content-Security-Policy-Report-Only';
    private const CSP_ENFORCE     = 'Content-Security-Policy';

    protected function setUp(): void
    {
        parent::setUp();

        // Pin the settings so a developer's .env cannot change what these tests check.
        config([
            'security.headers.enabled'      => true,
            'security.headers.hsts_max_age' => 0,
            'security.csp.mode'             => 'report-only',
            'security.csp.allow_unsafe_eval' => true,
            'app.error_details'             => false,
        ]);

        Route::get('/_test/page', fn () => response('<html><body>ok</body></html>'));
        Route::get('/_test/json', fn () => response()->json(['ok' => true]));
        Route::get('/_test/nonce', fn () => response('<html><body>' . Vite::cspNonce() . '</body></html>'));
        Route::get('/_test/own-frame-header', fn () => response('<html></html>')->header('X-Frame-Options', 'SAMEORIGIN'));
        Route::middleware('web')->get('/_test/boom', function () {
            throw new \RuntimeException('boom');
        });
    }

    private function csp(string $uri = '/_test/page', string $header = self::CSP_REPORT_ONLY): string
    {
        return (string) $this->get($uri)->headers->get($header);
    }

    public function test_hardening_headers_are_sent_on_a_page(): void
    {
        $response = $this->get('/_test/page');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', config('security.headers.permissions_policy'));
    }

    public function test_csp_is_report_only_by_default_and_never_enforcing(): void
    {
        $response = $this->get('/_test/page');

        $response->assertHeader(self::CSP_REPORT_ONLY);
        $response->assertHeaderMissing(self::CSP_ENFORCE);
    }

    public function test_csp_policy_has_the_important_restrictions(): void
    {
        $csp = $this->csp();

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString('report-uri /api/csp-report', $csp);

        $script = collect(explode('; ', $csp))->first(fn ($d) => str_starts_with($d, 'script-src '));
        $this->assertNotNull($script);
        $this->assertStringNotContainsString("'unsafe-inline'", $script, 'inline scripts must be reported, not allowed');
    }

    public function test_unsafe_eval_can_be_switched_off(): void
    {
        $this->assertStringContainsString("'unsafe-eval'", $this->csp());

        config(['security.csp.allow_unsafe_eval' => false]);

        $this->assertStringNotContainsString("'unsafe-eval'", $this->csp());
    }

    public function test_nonce_is_new_for_every_request_and_matches_the_one_views_can_use(): void
    {
        preg_match("/'nonce-([^']+)'/", $this->csp(), $first);
        preg_match("/'nonce-([^']+)'/", $this->csp(), $second);

        $this->assertNotEmpty($first[1] ?? null);
        $this->assertNotSame($first[1], $second[1] ?? null);

        $response = $this->get('/_test/nonce');
        preg_match("/'nonce-([^']+)'/", (string) $response->headers->get(self::CSP_REPORT_ONLY), $inHeader);

        $response->assertSee($inHeader[1], false); // Vite::cspNonce() in a view is the nonce in the header
    }

    public function test_enforce_mode_sends_the_enforcing_header_only(): void
    {
        config(['security.csp.mode' => 'enforce']);

        $response = $this->get('/_test/page');

        $response->assertHeader(self::CSP_ENFORCE);
        $response->assertHeaderMissing(self::CSP_REPORT_ONLY);
    }

    public function test_csp_can_be_switched_off_without_losing_the_other_headers(): void
    {
        config(['security.csp.mode' => 'off']);

        $response = $this->get('/_test/page');

        $response->assertHeaderMissing(self::CSP_REPORT_ONLY);
        $response->assertHeaderMissing(self::CSP_ENFORCE);
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_an_unknown_csp_mode_falls_back_to_report_only(): void
    {
        config(['security.csp.mode' => 'enforcee']);

        $response = $this->get('/_test/page');

        $response->assertHeader(self::CSP_REPORT_ONLY);
        $response->assertHeaderMissing(self::CSP_ENFORCE);
    }

    public function test_hsts_follows_the_configured_max_age_and_is_off_at_zero(): void
    {
        $this->get('/_test/page')->assertHeaderMissing('Strict-Transport-Security');

        config(['security.headers.hsts_max_age' => 86400]);

        $response = $this->get('/_test/page');
        $response->assertHeader('Strict-Transport-Security', 'max-age=86400');
        $this->assertStringNotContainsString('includeSubDomains', (string) $response->headers->get('Strict-Transport-Security'));
        $this->assertStringNotContainsString('preload', (string) $response->headers->get('Strict-Transport-Security'));
    }

    public function test_json_responses_get_the_headers_but_no_csp(): void
    {
        $response = $this->getJson('/_test/json');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeaderMissing(self::CSP_REPORT_ONLY);
        $response->assertHeaderMissing(self::CSP_ENFORCE);
    }

    public function test_a_header_set_by_the_controller_is_not_overwritten(): void
    {
        $this->get('/_test/own-frame-header')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_unknown_urls_and_error_pages_are_covered_too(): void
    {
        $notFound = $this->get('/definitely/not/a/real/page');
        $notFound->assertStatus(404);
        $notFound->assertHeader('X-Frame-Options', 'DENY');
        $notFound->assertHeader('X-Content-Type-Options', 'nosniff');
        $notFound->assertHeader(self::CSP_REPORT_ONLY);

        $error = $this->get('/_test/boom');
        $error->assertStatus(500);
        $error->assertHeader('X-Frame-Options', 'DENY');
        $error->assertHeader(self::CSP_REPORT_ONLY);
    }

    public function test_the_master_switch_turns_everything_off(): void
    {
        config(['security.headers.enabled' => false]);

        $response = $this->get('/_test/page');

        $response->assertHeaderMissing('X-Frame-Options');
        $response->assertHeaderMissing('X-Content-Type-Options');
        $response->assertHeaderMissing(self::CSP_REPORT_ONLY);
    }
}
