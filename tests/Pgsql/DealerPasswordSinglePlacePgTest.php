<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\Dealer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsReportData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * A DEALER'S PASSWORD HASH LIVES IN ONE PLACE ONLY: THE DEALER'S LOGIN (Admins table).
 *
 * Dealers sign in through Admins; the old "password" column on the dealers table is never read for sign-in.
 * Keeping a second copy only gives an attacker with a database leak one more hash to crack, so creating a
 * dealer and changing a dealer's password must leave that column empty.
 */
class DealerPasswordSinglePlacePgTest extends PgsqlTestCase
{
    use DatabaseTransactions;
    use BuildsIntakeData;
    use BuildsReportData;
    use CreatesTestAdmins;

    private function dealerRowPassword(int $id): ?string
    {
        return DB::table('dealers')->where('id', $id)->value('password');
    }

    public function test_creating_a_dealer_with_a_password_stores_the_hash_only_on_the_login(): void
    {
        $email = 'autotest+pw-' . Str::random(8) . '@example.test';

        $this->actingAs($this->makeAdmin('ADMIN'))
            ->post(route('admin.dealer.store'), [
                'full_name' => 'Autotest Pw ' . Str::random(5), 'region' => 'colombo', 'country' => 'Sri Lanka',
                'dealer_status' => 'retailer', 'contact_email' => $email, 'password' => 'Autotest#12345',
            ])
            ->assertSessionHasNoErrors();

        $dealer = Dealer::where('contact_email', $email)->firstOrFail();
        $login  = Admin::where('dealer_id', $dealer->id)->firstOrFail();

        $this->assertNull($this->dealerRowPassword($dealer->id));
        $this->assertTrue(Hash::check('Autotest#12345', $login->password));
    }

    public function test_creating_a_dealer_without_a_password_still_gets_a_working_login_and_no_copy(): void
    {
        $email = 'autotest+pw-' . Str::random(8) . '@example.test';

        $this->actingAs($this->makeAdmin('ADMIN'))
            ->post(route('admin.dealer.store'), [
                'full_name' => 'Autotest NoPw ' . Str::random(5), 'region' => 'colombo', 'country' => 'Sri Lanka',
                'dealer_status' => 'retailer', 'contact_email' => $email,
            ])
            ->assertSessionHasNoErrors();

        $dealer = Dealer::where('contact_email', $email)->firstOrFail();
        $login  = Admin::where('dealer_id', $dealer->id)->firstOrFail();

        $this->assertNull($this->dealerRowPassword($dealer->id));
        $this->assertNotEmpty($login->password);
    }

    public function test_an_administrator_changing_the_password_updates_the_login_and_leaves_no_copy(): void
    {
        $dealer = $this->makeDealer();
        $login  = $this->makeAdmin('DEALER', ['dealer_id' => $dealer->id, 'email' => $dealer->contact_email]);

        $this->actingAs($this->makeAdmin('ADMIN'))
            ->put(route('admin.dealer.profile.update', $dealer->id), [
                'full_name' => $dealer->full_name, 'dealer_status' => $dealer->dealer_status, 'region' => 'western',
                'contact_email' => $dealer->contact_email,
                'password' => 'Brand#NewPass91', 'password_confirmation' => 'Brand#NewPass91',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Brand#NewPass91', $login->fresh()->password));
        $this->assertNull($this->dealerRowPassword($dealer->id));
    }

    public function test_the_dealer_model_cannot_be_mass_filled_with_a_password(): void
    {
        $dealer = $this->makeDealer();
        $dealer->fill(['password' => 'should-be-ignored'])->save();

        $this->assertNull($this->dealerRowPassword($dealer->id));
        $this->assertArrayNotHasKey('password', $dealer->fresh()->toArray());
    }
}