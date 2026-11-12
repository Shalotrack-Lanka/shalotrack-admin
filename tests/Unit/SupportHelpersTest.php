<?php

namespace Tests\Unit;

use App\Support\ClientIp;
use App\Support\ErrorClassifier;
use App\Support\UserAgent;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Tests\TestCase;

/**
 * THE THREE SMALL HELPERS BEHIND THE AUDIT LOG AND THE ERROR PAGE (no database needed):
 *
 *   ClientIp         the visitor's real address for the audit log, believed ONLY when the request really came
 *                    through Cloudflare (a direct caller can type any CF-Connecting-IP they like)
 *   UserAgent        "Chrome 141 on Windows 10/11, Desktop" from the browser text; never echoes raw text
 *   ErrorClassifier  turns an exception into a category and a one-line hint for support
 */
class SupportHelpersTest extends TestCase
{
    // ================================================================== ClientIp

    private function request(array $headers = [], string $remote = '10.0.1.20'): Request
    {
        $server = ['REMOTE_ADDR' => $remote];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create('/', 'GET', [], [], [], $server);
    }

    public function test_no_request_gives_no_address(): void
    {
        $this->assertNull(ClientIp::resolve(null));
    }

    public function test_without_a_cloudflare_header_the_connection_address_is_used(): void
    {
        $this->assertSame('203.0.113.7', ClientIp::resolve($this->request([], '203.0.113.7')));
    }

    public function test_the_header_is_believed_when_the_nearest_public_hop_is_cloudflare(): void
    {
        $r = $this->request(['CF-Connecting-IP' => '198.51.100.23', 'X-Forwarded-For' => '198.51.100.23, 172.64.10.5']);

        $this->assertSame('198.51.100.23', ClientIp::resolve($r));
    }

    public function test_our_own_private_load_balancer_hop_is_skipped_to_find_the_cloudflare_hop(): void
    {
        $r = $this->request(['CF-Connecting-IP' => '198.51.100.24', 'X-Forwarded-For' => '198.51.100.24, 104.18.5.5, 10.0.2.9, 192.168.1.1']);

        $this->assertSame('198.51.100.24', ClientIp::resolve($r));
    }

    public function test_an_ipv6_visitor_and_an_ipv6_cloudflare_hop_work(): void
    {
        $r = $this->request(['CF-Connecting-IP' => '2001:db8::1', 'X-Forwarded-For' => '2001:db8::1, 2606:4700::1111']);

        $this->assertSame('2001:db8::1', ClientIp::resolve($r));
    }

    public function test_a_direct_caller_cannot_forge_the_header(): void
    {
        // The load balancer saw 203.0.113.9 connect (not Cloudflare), so the header is just text the caller typed.
        $r = $this->request(['CF-Connecting-IP' => '8.8.8.8', 'X-Forwarded-For' => '203.0.113.9'], '10.0.1.20');

        $this->assertNotSame('8.8.8.8', ClientIp::resolve($r));
    }

    public function test_a_forged_cloudflare_hop_to_the_left_does_not_help_the_attacker(): void
    {
        // The attacker adds a Cloudflare-looking address; the nearest PUBLIC hop (from our side) is still theirs.
        $r = $this->request(['CF-Connecting-IP' => '8.8.8.8', 'X-Forwarded-For' => '172.64.1.1, 203.0.113.9']);

        $this->assertNotSame('8.8.8.8', ClientIp::resolve($r));
    }

    public static function notIps(): array
    {
        return ['text' => ['not-an-ip'], 'with port' => ['1.2.3.4:80'], 'two addresses' => ['1.2.3.4, 5.6.7.8'], 'script' => ['<script>'], 'empty' => ['']];
    }

    #[DataProvider('notIps')]
    public function test_a_header_that_is_not_one_address_is_ignored(string $value): void
    {
        $r = $this->request(['CF-Connecting-IP' => $value, 'X-Forwarded-For' => '198.51.100.1, 172.64.10.5'], '203.0.113.50');

        $this->assertSame('203.0.113.50', ClientIp::resolve($r));
    }

    public function test_garbage_or_a_missing_forwarded_chain_means_the_header_is_not_believed(): void
    {
        foreach (['', 'garbage', '172.64.1.1, garbage', ' , , '] as $xff) {
            $headers = ['CF-Connecting-IP' => '198.51.100.30'];
            if ($xff !== '') {
                $headers['X-Forwarded-For'] = $xff;
            }

            $this->assertNotSame('198.51.100.30', ClientIp::resolve($this->request($headers, '203.0.113.51')), "chain: '{$xff}'");
        }
    }

    public static function cloudflareEdges(): array
    {
        return ['172.64 start' => ['172.64.0.1', true], '172.71 end of /13' => ['172.71.255.254', true], '172.72 just outside' => ['172.72.0.1', false],
            '104.16 start' => ['104.16.0.1', true], '104.23 end' => ['104.23.255.254', true], '104.24 /14' => ['104.24.0.1', true], '104.27 end' => ['104.27.255.254', true], '104.28 outside' => ['104.28.0.1', false],
            '173.245.48' => ['173.245.48.10', true], '173.245.63 end of /20' => ['173.245.63.254', true], '173.245.64 outside' => ['173.245.64.1', false], '162.158 /15' => ['162.158.5.5', true], '162.160 outside' => ['162.160.0.1', false],
            '131.0.72' => ['131.0.72.9', true], '131.0.76 outside' => ['131.0.76.1', false]];
    }

    #[DataProvider('cloudflareEdges')]
    public function test_the_published_cloudflare_ranges_are_matched_exactly(string $edge, bool $isCloudflare): void
    {
        $r = $this->request(['CF-Connecting-IP' => '198.51.100.77', 'X-Forwarded-For' => "198.51.100.77, {$edge}"], '203.0.113.60');

        $this->assertSame($isCloudflare ? '198.51.100.77' : '203.0.113.60', ClientIp::resolve($r));
    }

    // ================================================================== UserAgent

    public static function browsers(): array
    {
        return [
            'Chrome on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'Chrome 141 on Windows 10/11, Desktop'],
            'Edge on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', 'Edge 141 on Windows 10/11, Desktop'],
            'old Edge' => ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0 Safari/537.36 Edge/18.19041', 'Edge 18 on Windows 10/11, Desktop'],
            'Opera' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36 OPR/105.0.0.0', 'Opera 105 on Windows 10/11, Desktop'],
            'Firefox on Linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0', 'Firefox 130 on Linux, Desktop'],
            'Safari on Mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15', 'Safari 17 on macOS, Desktop'],
            'Safari on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1', 'Safari 17 on iOS 17 (iPhone), Mobile'],
            'Safari on iPad' => ['Mozilla/5.0 (iPad; CPU OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1', 'Safari 16 on iOS 16 (iPad), Tablet'],
            'Chrome on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/141.0.0.0 Mobile/15E148 Safari/604.1', 'Chrome 141 on iOS 17 (iPhone), Mobile'],
            'Firefox on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/130.0 Mobile/15E148 Safari/605.1.15', 'Firefox 130 on iOS 17 (iPhone), Mobile'],
            'Chrome on Android, model hidden' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36', 'Chrome 141 on Android, Mobile'],
            'Samsung Internet shows the model' => ['Mozilla/5.0 (Linux; Android 13; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/23.0 Chrome/115.0.0.0 Mobile Safari/537.36', 'Samsung Internet 23 on Android (SM-S918B), Mobile'],
            'Android tablet' => ['Mozilla/5.0 (Linux; Android 12; SM-T870) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', 'Chrome 120 on Android (SM-T870), Tablet'],
            'ChromeOS' => ['Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'Chrome 141 on ChromeOS, Desktop'],
            'Internet Explorer 11' => ['Mozilla/5.0 (Windows NT 6.1; WOW64; Trident/7.0; AS; rv:11.0) like Gecko', 'Internet Explorer 11 on Windows 7, Desktop'],
            'Windows 8.1' => ['Mozilla/5.0 (Windows NT 6.3; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/100.0 Safari/537.36', 'Chrome 100 on Windows 8.1, Desktop'],
            'unknown Windows' => ['Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/49.0 Safari/537.36', 'Chrome 49 on Windows, Desktop'],
            'Android web view' => ['Mozilla/5.0 (Linux; Android 12; Pixel 6 Build/SD1A.210817.036; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/110.0.0.0 Mobile Safari/537.36', 'Android WebView on Android, Mobile'],
            'Facebook in-app' => ['Mozilla/5.0 (Linux; Android 12; SM-A525F) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/110.0.0.0 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/400.0.0.0;]', 'Facebook in-app browser on Android (SM-A525F), Mobile'],
        ];
    }

    #[DataProvider('browsers')]
    public function test_a_browser_text_becomes_a_short_label(string $ua, string $label): void
    {
        $this->assertSame($label, UserAgent::label($ua));
    }

    public static function tools(): array
    {
        return [
            'curl' => ['curl/8.4.0', 'curl 8'], 'Python' => ['python-requests/2.31.0', 'Python script 2'], 'Postman' => ['PostmanRuntime/7.36.0', 'Postman 7'],
            'Go' => ['Go-http-client/2.0', 'Go client 2'], 'OkHttp' => ['okhttp/4.12.0', 'OkHttp client 4'], 'wget' => ['Wget/1.21', 'Wget 1'],
        ];
    }

    #[DataProvider('tools')]
    public function test_scripts_and_tools_are_named_as_such(string $ua, string $browser): void
    {
        $p = UserAgent::parse($ua);

        $this->assertSame($browser, $p['browser']);
        $this->assertSame('Script / tool', $p['device']);
        $this->assertNull($p['model']);
    }

    public static function bots(): array
    {
        return ['Google' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'], 'Bing' => ['Mozilla/5.0 (compatible; bingbot/2.0)'], 'crawler' => ['SomeCrawler/1.0'], 'spider' => ['my spider 1'], 'Yahoo' => ['Mozilla/5.0 (compatible; Yahoo! Slurp)']];
    }

    #[DataProvider('bots')]
    public function test_crawlers_are_labelled_as_bots_even_when_they_copy_a_browser(string $ua): void
    {
        $this->assertSame(['browser' => 'Search / web bot', 'os' => 'Unknown', 'device' => 'Bot', 'model' => null], UserAgent::parse($ua));
    }

    public function test_a_bot_that_copies_a_chrome_text_is_still_a_bot(): void
    {
        $this->assertSame('Bot', UserAgent::parse('Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141 Safari/537.36 (compatible; ExampleBot/1.0)')['device']);
    }

    public static function nothing(): array
    {
        return ['null' => [null], 'empty' => [''], 'spaces' => ['   '], 'random text' => ['xyz'], 'only symbols' => ['!!!???']];
    }

    #[DataProvider('nothing')]
    public function test_no_or_meaningless_text_says_unknown_device(?string $ua): void
    {
        $this->assertSame('Unknown device', UserAgent::label($ua));
    }

    public function test_raw_client_text_is_never_echoed_back(): void
    {
        $evil = '<script>alert(1)</script> "><img src=x onerror=alert(2)> Chrome/141.0 (Windows NT 10.0) Android 13; <b>MODEL</b>';

        $label = UserAgent::label($evil);

        $this->assertStringNotContainsString('<', $label);
        $this->assertStringNotContainsString('script', strtolower($label));
        $this->assertStringNotContainsString('onerror', $label);
        $this->assertNull(UserAgent::parse($evil)['model'], 'a model with markup characters is dropped');
    }

    public function test_a_very_long_text_is_handled_and_a_long_model_name_is_cut_to_40_characters(): void
    {
        $long = 'Mozilla/5.0 (Linux; Android 13; ' . str_repeat('A', 200) . ') Chrome/141.0 Mobile Safari/537.36';

        $model = UserAgent::parse($long)['model'];
        $this->assertLessThanOrEqual(40, strlen((string) $model));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9 _.\-]*$/', (string) $model);
        $this->assertIsString(UserAgent::label(str_repeat('x', 100000)));
    }

    public function test_the_model_is_shown_only_when_the_browser_volunteers_it(): void
    {
        $this->assertNull(UserAgent::parse('Mozilla/5.0 (Linux; Android 10; K) Chrome/141 Mobile Safari/537.36')['model']);
        $this->assertNull(UserAgent::parse('Mozilla/5.0 (Linux; Android 10; Mobile) Chrome/141 Mobile Safari/537.36')['model']);
        $this->assertSame('Pixel 7', UserAgent::parse('Mozilla/5.0 (Linux; Android 14; Pixel 7) Chrome/141 Mobile Safari/537.36')['model']);
    }

    // ================================================================== ErrorClassifier

    public static function exceptions(): array
    {
        $response = fn (int $status) => new ClientResponse(new \GuzzleHttp\Psr7\Response($status));

        return [
            'database' => [fn () => new QueryException('pgsql', 'select 1', [], new \Exception('x')), 'Database'],
            'api down' => [fn () => new ConnectionException('cannot connect'), 'External API — connection failed'],
            'api error' => [fn () => new RequestException($response(500)), 'External API — bad response'],
            'firebase' => [fn () => new FakeFirebaseAuthException('bad token'), 'Authentication'],
            'route' => [fn () => new RouteNotFoundException('Route [x] not defined.'), 'Routing / config'],
            'type error' => [fn () => new \TypeError('bad type'), 'Code bug'],
            'php error' => [fn () => new \ErrorException('Undefined variable'), 'Code bug'],
            'validation' => [fn () => ValidationException::withMessages(['a' => 'b']), 'Validation'],
            'anything else' => [fn () => new \RuntimeException('who knows'), 'Unclassified'],
            'model missing' => [fn () => new \Illuminate\Database\Eloquent\ModelNotFoundException('x'), 'Unclassified'],
        ];
    }

    #[DataProvider('exceptions')]
    public function test_each_kind_of_failure_gets_its_own_category_and_a_readable_hint(\Closure $make, string $category): void
    {
        $r = ErrorClassifier::classify($make());

        $this->assertSame($category, $r['category']);
        $this->assertNotSame('', trim($r['summary']));
        $this->assertEqualsCanonicalizing(['category', 'summary'], array_keys($r));
    }

    public function test_the_hint_never_contains_the_exception_message(): void
    {
        $r = ErrorClassifier::classify(new \RuntimeException('password=hunter2 SELECT * FROM admins'));

        $this->assertStringNotContainsString('hunter2', json_encode($r));
        $this->assertStringNotContainsString('SELECT', json_encode($r));
    }
}

/** A class with "Firebase" in its name, like the ones the Firebase library throws. */
class FakeFirebaseAuthException extends \RuntimeException
{
}