<?php

namespace Tests\Pgsql;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * The login lockout (5 wrong passwords per username and visitor address) must not be walkable-around by
 * sending a different forged address in a header on every try.
 *
 * Real production path: visitor -> Cloudflare -> load balancer (private address) -> app. The load balancer
 * appends the address that connected to it (a Cloudflare edge) to X-Forwarded-For.
 */
class LoginLockoutProxyPgTest extends PgsqlTestCase
{
    use DatabaseTransactions;
    use CreatesTestAdmins;

    private function wrongPassword(string $username, string $remoteAddr, array $headers = []): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
            ->withHeaders($headers)
            ->post('/login', ['username' => $username, 'password' => 'Wrong#Password1']);
    }

    private function isLockedOut(TestResponse $response): bool
    {
        $errors = session('errors');

        return $errors !== null && str_contains((string) $errors->first('username'), 'Too many');
    }

    public function test_a_direct_caller_cannot_dodge_the_lockout_with_a_new_forged_address_each_time(): void
    {
        $admin = $this->makeAdmin('ADMIN');

        for ($i = 1; $i <= 5; $i++) {
            $response = $this->wrongPassword($admin->username, '203.0.113.50', ['X-Forwarded-For' => "198.51.100.$i"]);
            $this->assertFalse($this->isLockedOut($response), "attempt $i should still be a normal failure");
        }

        $sixth = $this->wrongPassword($admin->username, '203.0.113.50', ['X-Forwarded-For' => '198.51.100.200']);
        $this->assertTrue($this->isLockedOut($sixth), 'a 6th attempt from the same direct caller must be locked, whatever address the header claims');
    }

    public function test_a_direct_caller_cannot_dodge_it_with_a_forged_cloudflare_header_either(): void
    {
        $admin = $this->makeAdmin('ADMIN');

        for ($i = 1; $i <= 5; $i++) {
            $this->wrongPassword($admin->username, '203.0.113.51', ['CF-Connecting-IP' => "198.51.100.$i"]);
        }

        $sixth = $this->wrongPassword($admin->username, '203.0.113.51', ['CF-Connecting-IP' => '198.51.100.99']);
        $this->assertTrue($this->isLockedOut($sixth));
    }

    public function test_behind_the_load_balancer_made_up_addresses_added_on_the_left_do_not_help(): void
    {
        $admin = $this->makeAdmin('ADMIN');

        // The load balancer is 10.0.1.5; it appended the address that really connected to it (172.64.0.1).
        for ($i = 1; $i <= 5; $i++) {
            $this->wrongPassword($admin->username, '10.0.1.5', ['X-Forwarded-For' => "198.51.100.$i, 172.64.0.1"]);
        }

        $sixth = $this->wrongPassword($admin->username, '10.0.1.5', ['X-Forwarded-For' => '198.51.100.77, 172.64.0.1']);
        $this->assertTrue($this->isLockedOut($sixth));
    }

    public function test_through_cloudflare_each_real_visitor_has_their_own_attempts(): void
    {
        $admin = $this->makeAdmin('ADMIN');
        $edge  = ['X-Forwarded-For' => '172.64.0.1'];

        for ($i = 1; $i <= 5; $i++) {
            $this->wrongPassword($admin->username, '10.0.1.5', $edge + ['CF-Connecting-IP' => '198.51.100.10']);
        }

        $sameVisitor = $this->wrongPassword($admin->username, '10.0.1.5', $edge + ['CF-Connecting-IP' => '198.51.100.10']);
        $this->assertTrue($this->isLockedOut($sameVisitor), 'the visitor who guessed 5 times is locked');

        $otherVisitor = $this->wrongPassword($admin->username, '10.0.1.5', $edge + ['CF-Connecting-IP' => '198.51.100.11']);
        $this->assertFalse($this->isLockedOut($otherVisitor), 'another visitor on the same Cloudflare edge is not locked by that');
    }
}
