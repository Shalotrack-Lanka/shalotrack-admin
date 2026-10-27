<?php

namespace Tests\Feature;

use App\Http\Middleware\SanitizeRequestInput;
use App\Support\Input;
use App\Support\PasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Every unvalidated input (search boxes, filters, ids in URLs) goes through App\Support\Input or a route
 * pattern, and every request passes the SanitizeRequestInput filter. These tests send the hostile shapes
 * real attackers and scanners send: arrays instead of strings, SQL, NUL bytes, letters where an id belongs.
 */
class InputHardeningTest extends TestCase
{
    // ---- App\Support\Input ----------------------------------------------------------------

    public function test_text_never_returns_array_or_junk_and_is_bounded(): void
    {
        $this->assertSame('', Input::text(['x']));
        $this->assertSame('', Input::text(null));
        $this->assertSame('', Input::text(new \stdClass()));
        $this->assertSame('abc', Input::text("  a\0b\x07c  "));
        $this->assertSame(str_repeat('a', 10), Input::text(str_repeat('a', 500), 10));
        // Not HTML-escaped on purpose (that would corrupt real names); Blade escapes on output.
        $this->assertSame("O'Brien & <Sons>", Input::text("O'Brien & <Sons>"));
        // Invalid UTF-8 is dropped, not passed on.
        $this->assertSame('', Input::text("\xC3\x28"));
    }

    public function test_sql_text_stays_plain_text_and_like_wildcards_are_literal(): void
    {
        $this->assertSame("'; DROP TABLE users; --", Input::text("'; DROP TABLE users; --"));
        $this->assertSame('50\\%\\_off\\\\', Input::like('50%_off\\'));
        $this->assertSame('%a\\%b%', Input::contains('a%b'));
    }

    public function test_id_accepts_only_plain_positive_numbers(): void
    {
        $this->assertSame(5, Input::id('5'));
        $this->assertSame(5, Input::id(5));
        foreach (['abc', '5abc', '-1', '0', '1.5', '', ' 5', '5 ', '1 OR 1=1', '99999999999999999999', null, [], ['1'], '0x1', '1e3'] as $bad) {
            $this->assertNull(Input::id($bad), 'should reject ' . json_encode($bad));
        }
    }

    public function test_choice_int_date_imei_uuid(): void
    {
        $this->assertSame('b', Input::choice('b', ['a', 'b'], 'a'));
        $this->assertSame('a', Input::choice('c', ['a', 'b'], 'a'));
        $this->assertSame('a', Input::choice(['b'], ['a', 'b'], 'a'));
        $this->assertNull(Input::choice('0', ['a'], null));

        $this->assertSame(30, Input::int('30', 1, 365, 7));
        $this->assertSame(365, Input::int('99999', 1, 365, 7));
        $this->assertSame(1, Input::int('-4', 1, 365, 7));
        $this->assertSame(7, Input::int('abc', 1, 365, 7));
        $this->assertSame(7, Input::int(['3'], 1, 365, 7));

        $this->assertSame('2026-02-28', Input::date('2026-02-28'));
        foreach (['2026-02-30', '2026-2-1', '26-02-01', 'now', '2026-02-01; DROP', ['2026-02-01']] as $bad) {
            $this->assertNull(Input::date($bad));
        }

        $this->assertSame('356307042441013', Input::imei('356307042441013'));
        $this->assertNull(Input::imei('35630704244101'));
        $this->assertNull(Input::imei('356307042441013 '));

        $u = '123E4567-E89B-12D3-A456-426614174000';
        $this->assertSame(strtolower($u), Input::uuid($u));
        $this->assertNull(Input::uuid('not-a-uuid'));
    }

    // ---- SanitizeRequestInput -------------------------------------------------------------

    private function passThrough(Request $request): Request
    {
        $seen = null;
        (new SanitizeRequestInput())->handle($request, function ($r) use (&$seen) {
            $seen = $r;

            return response('ok');
        });

        return $seen;
    }

    public function test_filter_strips_control_characters_from_query_form_and_json(): void
    {
        $r = $this->passThrough(Request::create('/x?search=ab%00c&list[]=d%07e', 'POST', ['name' => "Ra\0vi", 'note' => "line1\nline2\tend"]));

        $this->assertSame('abc', $r->query('search'));
        $this->assertSame(['de'], $r->query('list'));
        $this->assertSame('Ravi', $r->input('name'));
        $this->assertSame("line1\nline2\tend", $r->input('note'), 'newline and tab are kept');

        $json = Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['q' => "a\0b"]));
        $this->assertSame('ab', $this->passThrough($json)->input('q'));
    }

    public function test_filter_never_touches_passwords(): void
    {
        $typed = "P\x01ss\0w0rd!";
        $r = $this->passThrough(Request::create('/x', 'POST', ['password' => $typed, 'current_password' => $typed, 'new_password' => $typed]));

        $this->assertSame($typed, $r->input('password'));
        $this->assertSame($typed, $r->input('current_password'));
        $this->assertSame($typed, $r->input('new_password'));
    }

    public function test_filter_refuses_absurdly_deep_arrays(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->passThrough(Request::create('/x?a[b][c][d][e][f][g][h]=1'));
    }

    public function test_filter_is_registered_first_in_the_web_group(): void
    {
        $web = app('router')->getMiddlewareGroups()['web'];

        $this->assertSame(SanitizeRequestInput::class, $web[0]);
    }

    // ---- Route patterns: bad ids are a 404, never a database error ------------------------

    private function routeResolves(string $method, string $uri): bool
    {
        try {
            app('router')->getRoutes()->match(Request::create($uri, $method));

            return true;
        } catch (NotFoundHttpException) {
            return false;
        }
    }

    public function test_ids_in_urls_must_have_the_right_shape(): void
    {
        $this->assertTrue($this->routeResolves('GET', '/admin/billing/invoices/12'));
        $this->assertFalse($this->routeResolves('GET', '/admin/billing/invoices/abc'));
        $this->assertFalse($this->routeResolves('GET', "/admin/billing/invoices/1'%20OR%201=1"));
        $this->assertFalse($this->routeResolves('GET', '/admin/billing/receipts/99999999999999999999999'));
        $this->assertFalse($this->routeResolves('GET', '/admin/billing/accounts/x'));

        $this->assertTrue($this->routeResolves('GET', '/admin/vehicles/device-commands/status/356307042441013'));
        $this->assertFalse($this->routeResolves('GET', '/admin/vehicles/device-commands/status/123'));
        $this->assertFalse($this->routeResolves('GET', '/admin/vehicles/device-commands/status/abcdefghijklmno'));

        $uuid = '123e4567-e89b-12d3-a456-426614174000';
        $this->assertTrue($this->routeResolves('GET', "/admin/vehicles/device-commands/history/$uuid"));
        $this->assertFalse($this->routeResolves('GET', '/admin/vehicles/device-commands/history/1'));
        $this->assertFalse($this->routeResolves('PATCH', '/admin/customer/setup/not-a-uuid/status'));

        $this->assertFalse($this->routeResolves('GET', '/admin/dealer/abc/profile'));
        $this->assertFalse($this->routeResolves('GET', '/admin/supplier/abc/edit'));
        $this->assertFalse($this->routeResolves('GET', '/admin/renewal-requests/a%3Fb/slip'));
        $this->assertTrue($this->routeResolves('GET', "/admin/renewal-requests/$uuid/slip"));
    }

    // ---- Password limits -------------------------------------------------------------------

    public function test_passwords_have_one_policy_and_a_ceiling(): void
    {
        $rules = ['p' => ['required', 'string', 'max:' . PasswordPolicy::MAX_LENGTH, PasswordPolicy::rule()]];

        $this->assertTrue(Validator::make(['p' => 'Str0ngPassw0rd'], $rules)->passes());
        $this->assertFalse(Validator::make(['p' => 'short1A'], $rules)->passes());
        $this->assertFalse(Validator::make(['p' => 'alllowercase123'], $rules)->passes());
        $this->assertFalse(Validator::make(['p' => 'Aa1' . str_repeat('x', 100)], $rules)->passes(), 'over 72 bytes is refused, not silently cut by bcrypt');
    }
}
