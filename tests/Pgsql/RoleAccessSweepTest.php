<?php

namespace Tests\Pgsql;

use App\Models\Dealer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * ROLE-ACCESS SWEEP: every signed-in URL of the portal, tried as every role.
 *
 * Idea in simple words: the people who built the portal decided WHO may open each page. This test keeps
 * its own written copy of that decision (POLICY below) and then checks the real route list against it:
 *
 *   1. The route table matches the policy (a new page with no role rule, or the wrong one, is caught here).
 *   2. A logged-out visitor is sent to the login page for every one of those URLs (never a page, never a 500).
 *   3. For each role, every URL that role must NOT use answers 403 (all methods: GET, POST, PUT, PATCH, DELETE).
 *   4. For each role, every plain page (GET without ids) that role MAY use opens: no 403 and no crash.
 *
 * When something is wrong, the test lists ALL the problems in one go instead of stopping at the first.
 * Nothing is saved: the denied requests stop at the role check, and everything is rolled back anyway.
 */
class RoleAccessSweepTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    private const ALL_ROLES = ['ADMIN', 'DEALER', 'DISTRIBUTOR', 'FINANCE', 'TECHNICIAN', 'SUPPLIER'];

    /** Any signed-in role may use these (own password, home redirect, logout). */
    private const OPEN_TO_EVERY_ROLE = ['', 'account/password', 'dashboard', 'logout'];

    /**
     * Who may use which URL. The FIRST matching pattern wins, so the exceptions come before the general rules.
     * This is our own written copy of the rules, it is NOT read from the routes.
     */
    private const POLICY = [
        [['admin/customer/device-management/*/bank-slip'], ['ADMIN', 'FINANCE']],
        [['admin/dealer/device-commands', 'dealer/device-commands', 'dealer/device-commands/*'], ['ADMIN', 'DEALER']],
        [['admin/supplier/dashboard', 'admin/supplier/profile'], ['SUPPLIER']],
        [[
            'admin/dealer/profile', 'admin/dealer/customers', 'admin/dealer/customer-ad', 'admin/dealer/customer-ad/*',
            'admin/dealer/assign-device', 'admin/dealer/unassign-device/*', 'admin/dealer/dealer/*',
        ], ['DEALER']],
        [['dealer/*'], ['DEALER']],
        [['distributor/*'], ['DISTRIBUTOR']],
        [['finance/*'], ['FINANCE']],
        [['technician/*'], ['TECHNICIAN']],
        [['admin/*'], ['ADMIN']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake(['*' => Http::response([], 200)]);
    }

    // ------------------------------------------------------------------ helpers

    /** The roles our policy allows for this URL, or null when every signed-in role may use it. */
    private function policyRoles(string $uri): ?array
    {
        $uri = trim($uri, '/');

        if (in_array($uri, self::OPEN_TO_EVERY_ROLE, true)) {
            return null;
        }

        foreach (self::POLICY as [$patterns, $roles]) {
            foreach ($patterns as $pattern) {
                if (Str::is($pattern, $uri)) {
                    return $roles;
                }
            }
        }

        return [];   // matches nothing: no role may use it
    }

    /**
     * Every URL behind the login, one entry per HTTP method.
     * @return array<int, array{method:string, uri:string, route:Route, roles:?array, middleware:array}>
     */
    private function loginRoutes(): array
    {
        $found = [];

        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
            $behindLogin = false;
            foreach ($middleware as $m) {
                if ($m === 'auth' || str_starts_with($m, 'auth:')) {
                    $behindLogin = true;
                }
            }
            if (! $behindLogin) {
                continue;
            }

            // The role rule written on the route, e.g. role:ADMIN or role:ADMIN,DEALER
            $actual = null;
            foreach ($middleware as $m) {
                if (str_starts_with($m, 'role:')) {
                    $set = explode(',', substr($m, 5));
                    $actual = $actual === null ? $set : array_values(array_intersect($actual, $set));
                }
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $found[] = [
                    'method'     => $method,
                    'uri'        => $route->uri(),
                    'route'      => $route,
                    'roles'      => $actual,
                    'middleware' => $middleware,
                ];
            }
        }

        return $found;
    }

    /** A real-looking URL for a route that has {ids}: tries a few values until the router matches the same route. */
    private function urlFor(Route $route, string $method): ?string
    {
        foreach (['1', (string) Str::uuid(), '2026-09', 'x'] as $value) {
            $url = '/' . ltrim(preg_replace('/\{[^}]+\}/', $value, $route->uri()), '/');
            try {
                $hit = RouteFacade::getRoutes()->match(Request::create($url, $method));
                if ($hit->uri() === $route->uri()) {
                    return $url;
                }
            } catch (\Throwable $e) {
                // try the next value
            }
        }

        return null;
    }

    private function send(string $method, string $url)
    {
        Cache::flush();   // the throttle counter is shared by all throttled URLs of one user, start clean each time

        return $this->call($method, $url);
    }

    private function line(string $method, string $uri, string $what): string
    {
        return sprintf('%-6s /%s  ->  %s', $method, ltrim($uri, '/'), $what);
    }

    private function makeDistributorRecord(): Dealer
    {
        return Dealer::create([
            'full_name'     => 'Autotest Distributor Sweep',
            'dealer_status' => 'distributor',
            'region'        => 'colombo',
            'country'       => 'Sri Lanka',
            'contact_email' => 'autotest+dist-sweep@example.test',
            'status'        => 'active',
            'created_by'    => 'autotest',
        ]);
    }

    public static function roles(): array
    {
        return array_map(fn ($r) => [$r], self::ALL_ROLES);
    }

    // ------------------------------------------------------------------ 1. the route table matches the policy

    public function test_every_login_url_has_exactly_the_role_rule_we_intend(): void
    {
        $problems = [];
        $seen = [];

        foreach ($this->loginRoutes() as $r) {
            $key = $r['method'] . ' ' . $r['uri'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $expected = $this->policyRoles($r['uri']);

            if ($expected === null) {
                continue;   // open to every signed-in role, nothing to compare
            }
            if ($r['roles'] === null) {
                $problems[] = $this->line($r['method'], $r['uri'], 'has NO role rule (any signed-in role could open it)');
                continue;
            }

            $actual = $r['roles'];
            sort($actual);
            sort($expected);

            if ($actual !== $expected) {
                $problems[] = $this->line($r['method'], $r['uri'], 'route allows [' . implode(',', $actual) . '] but the policy says [' . implode(',', $expected) . ']');
            }
        }

        $this->assertGreaterThan(200, count($seen), 'The sweep should be looking at the whole portal (200+ URLs).');
        $this->assertSame([], $problems, "Role rules that do not match the policy:\n" . implode("\n", $problems));
    }

    // ------------------------------------------------------------------ 2. logged-out visitors

    public function test_a_logged_out_visitor_is_sent_to_login_for_every_url(): void
    {
        $problems = [];

        foreach ($this->loginRoutes() as $r) {
            $url = $this->urlFor($r['route'], $r['method']);
            if ($url === null) {
                $problems[] = $this->line($r['method'], $r['uri'], 'could not build a test URL');
                continue;
            }

            $response = $this->send($r['method'], $url);

            if ($response->getStatusCode() !== 302 || ! str_contains((string) $response->headers->get('Location'), '/login')) {
                $problems[] = $this->line($r['method'], $r['uri'], 'logged-out visitor got ' . $response->getStatusCode() . ' (expected a redirect to /login)');
            }
        }

        $this->assertSame([], $problems, "Logged-out visitors were not sent to login:\n" . implode("\n", $problems));
    }

    // ------------------------------------------------------------------ 3. the wrong role is always refused

    #[DataProvider('roles')]
    public function test_a_role_is_refused_with_403_on_every_url_it_may_not_use(string $role): void
    {
        $this->actingAs($this->makeAdmin($role));

        $problems = [];
        $tried = 0;

        foreach ($this->loginRoutes() as $r) {
            $allowed = $this->policyRoles($r['uri']);
            if ($allowed === null || in_array($role, $allowed, true)) {
                continue;   // this role may use it, checked in the next test
            }

            $url = $this->urlFor($r['route'], $r['method']);
            if ($url === null) {
                $problems[] = $this->line($r['method'], $r['uri'], 'could not build a test URL');
                continue;
            }

            $tried++;
            $status = $this->send($r['method'], $url)->getStatusCode();

            if ($status !== 403) {
                $problems[] = $this->line($r['method'], $r['uri'], "$role got $status (expected 403)");
            }
        }

        $this->assertGreaterThan(20, $tried, "$role should be tried against many forbidden URLs.");
        $this->assertSame([], $problems, "$role was NOT refused on these URLs ($tried tried):\n" . implode("\n", $problems));
    }

    // ------------------------------------------------------------------ 4. the right role can open its pages

    #[DataProvider('roles')]
    public function test_a_role_can_open_every_plain_page_it_is_allowed_to_use(string $role): void
    {
        // A distributor login only works when it points at an active distributor record (the portal refuses it otherwise).
        $overrides = [];
        if ($role === 'DISTRIBUTOR') {
            $overrides['dealer_id'] = $this->makeDistributorRecord()->id;
        }

        $this->actingAs($this->makeAdmin($role, $overrides));

        $problems = [];
        $tried = 0;

        foreach ($this->loginRoutes() as $r) {
            // Only plain pages: GET, no {ids}, and not a developer-only page (needs the developer account).
            if ($r['method'] !== 'GET' || str_contains($r['uri'], '{') || in_array('developer', $r['middleware'], true)) {
                continue;
            }
            $allowed = $this->policyRoles($r['uri']);
            if ($allowed === null || ! in_array($role, $allowed, true)) {
                continue;
            }

            $tried++;
            $status = $this->send('GET', '/' . ltrim($r['uri'], '/'))->getStatusCode();

            if ($status === 403 || $status >= 500) {
                $problems[] = $this->line('GET', $r['uri'], "$role got $status");
            }
        }

        $this->assertGreaterThan(0, $tried, "$role should have at least one plain page.");
        $this->assertSame([], $problems, "$role could not open these pages ($tried tried):\n" . implode("\n", $problems));
    }

    // ------------------------------------------------------------------ 5. a distributor login with no distributor behind it

    public function test_a_distributor_login_that_points_at_no_active_distributor_sees_nothing(): void
    {
        $ghost = $this->makeAdmin('DISTRIBUTOR');   // no dealer_id at all
        $this->actingAs($ghost);

        foreach (['dashboard', 'dealers', 'stock', 'transfer', 'transfers', 'devices', 'customers', 'billing'] as $page) {
            $this->get('/distributor/' . $page)->assertForbidden();
        }
    }
}