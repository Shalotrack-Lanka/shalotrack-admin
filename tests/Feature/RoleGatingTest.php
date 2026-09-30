<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureRole;
use App\Models\Admin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Before this, ~55 data-changing endpoints only required "logged in", so a dealer, finance or
 * technician login could (for example) transfer stock, import devices, activate/stop devices,
 * mark subscriptions paid or download every dealer's customer list by typing the URL.
 */
class RoleGatingTest extends TestCase
{
    /** Routes any logged-in account may use (account plumbing, no business data). */
    private const ANY_LOGGED_IN = ['GET dashboard', 'POST logout'];

    private function roleMiddleware(LaravelRoute $route): ?array
    {
        foreach ($route->gatherMiddleware() as $m) {
            if (! is_string($m)) {
                continue;
            }
            if (str_starts_with($m, 'role:')) {
                return explode(',', substr($m, 5));
            }
            if (str_starts_with($m, EnsureRole::class . ':')) {
                return explode(',', substr($m, strlen(EnsureRole::class) + 1));
            }
        }

        return null;
    }

    private function routes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $r) {
            foreach ($r->methods() as $m) {
                if ($m !== 'HEAD') {
                    $out[] = [$m, $r->uri(), $r];
                }
            }
        }

        return $out;
    }

    public function test_every_logged_in_route_is_gated_to_a_role_unless_it_is_plain_account_plumbing(): void
    {
        $open = [];
        foreach ($this->routes() as [$method, $uri, $route]) {
            $mw = $route->gatherMiddleware();
            $needsLogin = in_array('auth', $mw, true) || in_array(\Illuminate\Auth\Middleware\Authenticate::class, $mw, true);
            if ($needsLogin && $this->roleMiddleware($route) === null && ! in_array("$method $uri", self::ANY_LOGGED_IN, true)) {
                $open[] = "$method $uri";
            }
        }

        $this->assertSame([], $open, "Logged-in routes with no role check:\n" . implode("\n", $open));
    }

    public function test_admin_urls_are_admin_only_and_portal_urls_match_their_role(): void
    {
        foreach ($this->routes() as [$method, $uri, $route]) {
            $roles = $this->roleMiddleware($route);
            if ($roles === null) {
                continue;
            }

            $expected = match (true) {
                $uri === 'admin/supplier/dashboard', $uri === 'admin/supplier/profile' => ['SUPPLIER'],
                $uri === 'admin/dealer/device-commands', str_starts_with($uri, 'dealer/device-commands') => ['ADMIN', 'DEALER'],
                in_array($uri, ['admin/dealer/profile', 'admin/dealer/customers'], true),
                (bool) preg_match('#^admin/dealer/customer-ad(/|$)#', $uri),
                in_array($uri, ['admin/dealer/unassign-device/{id}', 'admin/dealer/assign-device'], true),
                str_starts_with($uri, 'admin/dealer/dealer/') => ['DEALER'],
                str_starts_with($uri, 'dealer/') => ['DEALER'],
                $uri === 'admin/customer/device-management/{activatedDevice}/bank-slip' => ['ADMIN', 'FINANCE'],
                $uri === 'finance/dashboard', $uri === 'finance/profile' => ['FINANCE'],
                $uri === 'technician/dashboard', $uri === 'technician/profile' => ['TECHNICIAN'],
                default => ['ADMIN'],
            };

            $this->assertSame($expected, $roles, "$method $uri is open to " . implode(',', $roles) . ', expected ' . implode(',', $expected));
        }
    }

    public function test_no_route_is_shadowed_by_an_earlier_one(): void
    {
        $shadowed = [];
        foreach ($this->routes() as [$method, $uri, $route]) {
            $concrete = '/' . ltrim(preg_replace('/\{[^}]+\}/', '1', $uri), '/');
            $hit = Route::getRoutes()->match(\Illuminate\Http\Request::create($concrete, $method));
            if ($hit->getAction('uses') !== $route->getAction('uses')) {
                $shadowed[] = "$method /$uri is shadowed by /{$hit->uri()}";
            }
        }

        $this->assertSame([], $shadowed, implode("\n", $shadowed));
    }

    public function test_the_breeze_profile_routes_that_500d_or_let_users_delete_themselves_are_gone(): void
    {
        foreach (['profile.edit', 'profile.update', 'profile.destroy', 'password.update'] as $name) {
            $this->assertFalse(Route::has($name), "$name should not exist");
        }
    }

    private function makeAdmins(): void
    {
        Schema::create('Admins', function (Blueprint $t) {
            $t->string('admin_id')->primary();
            $t->string('username')->nullable();
            $t->string('password')->nullable();
            $t->string('full_name')->nullable();
            $t->string('email')->nullable();
            $t->string('phone_number')->nullable();
            $t->string('role');
            $t->string('status')->default('ACTIVE');
            $t->unsignedBigInteger('dealer_id')->nullable();
            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });
    }

    private function as(string $role): void
    {
        $this->actingAs(Admin::forceCreate(['admin_id' => 'u-' . strtolower($role), 'username' => $role, 'role' => $role, 'status' => 'ACTIVE']));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blockedProvider')]
    public function test_other_roles_get_403_on_admin_actions(string $role, string $method, string $url): void
    {
        $this->makeAdmins();
        $this->as($role);

        $this->call($method, $url)->assertForbidden();
    }

    public static function blockedProvider(): array
    {
        $cases = [];
        foreach (['DEALER', 'FINANCE', 'TECHNICIAN', 'SUPPLIER'] as $role) {
            foreach ([
                ['POST', '/admin/master-pages/stock-transfer'],
                ['POST', '/admin/master-pages/devices/import'],
                ['PATCH', '/admin/cancel-requests/cancel-device/1'],
                ['POST', '/admin/customer/device-management/1/reactivate'],
                ['PATCH', '/admin/customer/device-management/1'],
                ['POST', '/admin/stock/manage-stock'],
                ['POST', '/admin/supplier/supplier-management-invoice'],
                ['PATCH', '/admin/supplier/1/toggle-status'],
                ['PATCH', '/admin/dealer/1/toggle-status'],
                ['GET', '/admin/dealer/dealer-customers/report'],
                ['GET', '/admin/dashboard'],
                ['GET', '/admin/report/stock-in-report'],
            ] as [$m, $u]) {
                $cases["$role $m $u"] = [$role, $m, $u];
            }
        }
        // portal-only routes are closed to the wrong role too
        $cases['FINANCE POST dealer assign'] = ['FINANCE', 'POST', '/admin/dealer/assign-device'];
        $cases['DEALER GET supplier dashboard'] = ['DEALER', 'GET', '/admin/supplier/dashboard'];
        $cases['SUPPLIER GET dealer dashboard'] = ['SUPPLIER', 'GET', '/dealer/dashboard'];
        $cases['DEALER PUT supplier profile'] = ['DEALER', 'PUT', '/admin/supplier/profile'];

        return $cases;
    }

    public function test_admin_is_not_blocked_and_a_supplier_profile_save_reaches_the_supplier_controller(): void
    {
        $this->makeAdmins();
        $this->as('ADMIN');

        // Gate passes: whatever happens next is not a 403 (it may be a validation redirect or an error).
        $status = $this->call('GET', '/admin/dealer/dealer-customers/report')->getStatusCode();
        $this->assertNotSame(403, $status);

        // SUPPLIER: PUT /admin/supplier/profile must resolve to the profile controller, not PUT /{id}.
        $route = Route::getRoutes()->match(\Illuminate\Http\Request::create('/admin/supplier/profile', 'PUT'));
        $this->assertSame('supplier.profile.update', $route->getName());
        $this->assertSame(['SUPPLIER'], $this->roleMiddleware($route));
    }
}
