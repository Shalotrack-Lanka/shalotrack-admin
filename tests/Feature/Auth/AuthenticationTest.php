<?php

namespace Tests\Feature\Auth;

use App\Models\Admin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The portal logs in against the Admins table by username (not the stock Breeze users/email),
 * and sends each role to its own dashboard.
 */
class AuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

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

    private function account(string $role, string $status = 'ACTIVE'): Admin
    {
        return Admin::forceCreate([
            'admin_id' => 'id-' . strtolower($role) . $status,
            'username' => strtolower($role) . ($status === 'ACTIVE' ? '' : '_off'),
            'password' => Hash::make('correct-password'),
            'role'     => $role,
            'status'   => $status,
        ]);
    }

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_each_role_lands_on_its_own_dashboard(): void
    {
        foreach ([
            'ADMIN'      => 'admin.dashboard',
            'DEALER'     => 'dealer.dashboard',
            'FINANCE'    => 'finance.dashboard',
            'TECHNICIAN' => 'technician.dashboard',
            'SUPPLIER'   => 'supplier.dashboard',
        ] as $role => $route) {
            $user = $this->account($role);

            $this->post('/login', ['username' => $user->username, 'password' => 'correct-password'])
                ->assertRedirect(route($route));
            $this->assertAuthenticatedAs($user);

            $this->post('/logout');
            $this->assertGuest();
        }
    }

    public function test_wrong_password_does_not_log_in(): void
    {
        $user = $this->account('ADMIN');

        $this->post('/login', ['username' => $user->username, 'password' => 'wrong'])
            ->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_deactivated_accounts_cannot_log_in(): void
    {
        $user = $this->account('DEALER', 'INACTIVE');

        $this->post('/login', ['username' => $user->username, 'password' => 'correct-password'])
            ->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_five_bad_attempts_lock_the_login_even_for_the_right_password(): void
    {
        RateLimiter::clear('x');
        $user = $this->account('ADMIN');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => $user->username, 'password' => 'wrong']);
        }

        $this->post('/login', ['username' => $user->username, 'password' => 'correct-password'])
            ->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_logout_ends_the_session(): void
    {
        $this->actingAs($this->account('ADMIN'))->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }
}
