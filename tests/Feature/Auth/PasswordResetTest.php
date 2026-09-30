<?php

namespace Tests\Feature\Auth;

use App\Models\Admin;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * "Forgot password" on the login page, against the Admins table.
 */
class PasswordResetTest extends TestCase
{
    private Admin $user;

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
        Schema::create('password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });

        $this->user = Admin::forceCreate([
            'admin_id' => 'a-1', 'username' => 'dealer1', 'email' => 'dealer1@example.com',
            'password' => Hash::make('old-password'), 'role' => 'DEALER', 'status' => 'ACTIVE',
        ]);
    }

    public function test_reset_link_screen_can_be_rendered(): void
    {
        $this->get('/forgot-password')->assertOk();
    }

    public function test_reset_link_can_be_requested(): void
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => $this->user->email])->assertSessionHasNoErrors();

        Notification::assertSentTo($this->user, ResetPassword::class);
    }

    public function test_reset_screen_renders_and_password_can_be_reset_with_the_emailed_token(): void
    {
        Notification::fake();
        $this->post('/forgot-password', ['email' => $this->user->email]);

        Notification::assertSentTo($this->user, ResetPassword::class, function ($notification) {
            $this->get('/reset-password/' . $notification->token)->assertOk();

            $this->post('/reset-password', [
                'token'                 => $notification->token,
                'email'                 => $this->user->email,
                'password'              => 'N3w-strong-password!',
                'password_confirmation' => 'N3w-strong-password!',
            ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

            return true;
        });

        $this->assertTrue(Hash::check('N3w-strong-password!', $this->user->fresh()->password));
        $this->post('/login', ['username' => 'dealer1', 'password' => 'N3w-strong-password!'])
            ->assertRedirect(route('dealer.dashboard'));
    }

    public function test_a_wrong_token_is_rejected(): void
    {
        $this->post('/reset-password', [
            'token' => 'nope', 'email' => $this->user->email,
            'password' => 'N3w-strong-password!', 'password_confirmation' => 'N3w-strong-password!',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('old-password', $this->user->fresh()->password));
    }
}
