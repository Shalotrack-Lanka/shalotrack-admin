<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\Dealer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * Admin > Dealer Management > add dealer (POST admin.dealer.store).
 * Runs in the isolated "autotest" schema; every row is rolled back by DatabaseTransactions.
 * Assertions look only at the tagged rows created here, never at table totals.
 */
class DealerCreateTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    private function payload(array $overrides = []): array
    {
        $tag = substr(md5((string) microtime(true)), 0, 8);

        return array_merge([
            'full_name'     => 'Autotest Dealer ' . $tag,
            'dealer_status' => 'distributor',
            'region'        => 'colombo',
            'country'       => 'Sri Lanka',
            'contact_email' => "autotest+dealer-{$tag}@example.test",
            'password'      => 'Autotest#12345',
        ], $overrides);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    public function test_admin_can_create_a_dealer_with_a_linked_dealer_login(): void
    {
        $admin = $this->makeAdmin('ADMIN');
        $data  = $this->payload();

        $this->actingAs($admin)
            ->post(route('admin.dealer.store'), $data)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $dealer = Dealer::where('contact_email', $data['contact_email'])->first();
        $this->assertNotNull($dealer, 'dealer row was not created');
        $this->assertSame($data['full_name'], $dealer->full_name);
        $this->assertSame('active', $dealer->status);

        $login = Admin::where('dealer_id', $dealer->id)->first();
        $this->assertNotNull($login, 'linked DEALER login was not created');
        $this->assertSame('DEALER', $login->role);
        $this->assertSame('ACTIVE', $login->status);
        $this->assertNotSame($data['password'], $login->password, 'password must be hashed');
    }

    public function test_duplicate_contact_email_is_rejected_and_creates_nothing_new(): void
    {
        $admin = $this->makeAdmin('ADMIN');
        $data  = $this->payload();

        $this->actingAs($admin)->post(route('admin.dealer.store'), $data)->assertSessionHasNoErrors();

        $second = $this->payload(['contact_email' => $data['contact_email']]);
        $this->actingAs($admin)
            ->post(route('admin.dealer.store'), $second)
            ->assertSessionHasErrors('contact_email');

        $this->assertSame(1, Dealer::where('contact_email', $data['contact_email'])->count());
    }

    public function test_required_fields_are_validated(): void
    {
        $admin = $this->makeAdmin('ADMIN');

        $this->actingAs($admin)
            ->post(route('admin.dealer.store'), [])
            ->assertSessionHasErrors(['full_name', 'dealer_status', 'region', 'contact_email']);
    }

    public function test_short_password_and_bad_email_are_rejected(): void
    {
        $admin = $this->makeAdmin('ADMIN');

        $this->actingAs($admin)
            ->post(route('admin.dealer.store'), $this->payload(['password' => 'short', 'contact_email' => 'not-an-email']))
            ->assertSessionHasErrors(['password', 'contact_email']);
    }

    public function test_a_dealer_login_cannot_create_dealers(): void
    {
        $dealerLogin = $this->makeAdmin('DEALER');
        $data        = $this->payload();

        $status = $this->actingAs($dealerLogin)
            ->post(route('admin.dealer.store'), $data)
            ->getStatusCode();

        $this->assertContains($status, [302, 403]);
        $this->assertNull(Dealer::where('contact_email', $data['contact_email'])->first());
    }
}