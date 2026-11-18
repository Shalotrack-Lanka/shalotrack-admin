<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\Dealer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsReportData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * A DEALER'S CONTACT EMAIL IS COPIED INTO THE DEALER'S LOGIN, and the Admins table has no unique index on email.
 * So three pages must each refuse an email that another login already uses (compared without regard to
 * upper/lower case), or one dealer could end up sharing an email with an administrator or another dealer:
 *
 *   PUT  dealer.profile.update          the dealer saves its own profile
 *   PUT  admin.dealer.profile.update    an administrator edits a dealer
 *   POST admin.dealer.store             an administrator creates a dealer
 *
 * Saving a form WITHOUT changing the email must always work, even if an old duplicate already exists.
 */
class DealerLoginEmailPgTest extends PgsqlTestCase
{
    use DatabaseTransactions;
    use BuildsIntakeData;
    use BuildsReportData;
    use CreatesTestAdmins;

    private const TAKEN = 'Another account already uses this email.';

    private function freeEmail(): string
    {
        return 'autotest+free-' . Str::random(10) . '@example.test';
    }

    /** A dealer and its own login; both carry the same email, as the portal creates them. */
    private function dealerAndLogin(?string $email = null): array
    {
        $email ??= $this->freeEmail();
        $dealer = $this->makeDealer(['contact_email' => $email]);
        $login  = $this->makeAdmin('DEALER', ['dealer_id' => $dealer->id, 'email' => $email]);

        return [$dealer, $login];
    }

    private function selfForm(Dealer $dealer, array $changes = []): array
    {
        return array_merge([
            'full_name'     => $dealer->full_name,
            'contact_email' => $dealer->contact_email,
            'phone_number'  => '0771234567',
            'address'       => 'Autotest address',
            'region'        => 'colombo',
            'country'       => 'Sri Lanka',
        ], $changes);
    }

    private function adminForm(Dealer $dealer, array $changes = []): array
    {
        return array_merge([
            'full_name'     => $dealer->full_name,
            'dealer_status' => $dealer->dealer_status,
            'region'        => 'western',
            'contact_email' => $dealer->contact_email,
        ], $changes);
    }

    // ───────────────────────── the dealer's own profile page ─────────────────────────

    public function test_a_dealer_cannot_take_the_email_of_an_administrator_login(): void
    {
        $boss = $this->makeAdmin('ADMIN');
        [$dealer, $login] = $this->dealerAndLogin();
        $original = $dealer->contact_email;

        $this->actingAs($login)->from(route('dealer.profile.edit'))
            ->put(route('dealer.profile.update'), $this->selfForm($dealer, ['contact_email' => $boss->email]))
            ->assertRedirect(route('dealer.profile.edit'))
            ->assertSessionHasErrors(['contact_email' => self::TAKEN]);

        $this->assertSame($original, $dealer->fresh()->contact_email);
        $this->assertSame($original, $login->fresh()->email);
    }

    public function test_the_check_ignores_upper_and_lower_case(): void
    {
        $boss = $this->makeAdmin('ADMIN', ['email' => 'autotest+case-' . Str::random(8) . '@example.test']);
        [$dealer, $login] = $this->dealerAndLogin();

        $this->actingAs($login)
            ->put(route('dealer.profile.update'), $this->selfForm($dealer, ['contact_email' => strtoupper($boss->email)]))
            ->assertSessionHasErrors(['contact_email' => self::TAKEN]);
    }

    public function test_a_dealer_cannot_take_the_email_of_another_dealers_login(): void
    {
        // The other dealer's login carries the email but its dealer row has a different contact email,
        // so the old "unique in dealers" rule alone cannot see it.
        $email = $this->freeEmail();
        $otherDealer = $this->makeDealer();
        $this->makeAdmin('DEALER', ['dealer_id' => $otherDealer->id, 'email' => $email]);
        [$dealer, $login] = $this->dealerAndLogin();

        $this->actingAs($login)
            ->put(route('dealer.profile.update'), $this->selfForm($dealer, ['contact_email' => $email]))
            ->assertSessionHasErrors(['contact_email' => self::TAKEN]);

        $this->assertNotSame($email, $login->fresh()->email);
    }

    public function test_a_free_email_is_saved_to_the_dealer_and_to_its_login(): void
    {
        [$dealer, $login] = $this->dealerAndLogin();
        $new = $this->freeEmail();

        $this->actingAs($login)
            ->put(route('dealer.profile.update'), $this->selfForm($dealer, ['contact_email' => $new]))
            ->assertSessionHasNoErrors();

        $this->assertSame($new, $dealer->fresh()->contact_email);
        $this->assertSame($new, $login->fresh()->email);
    }

    public function test_a_dealer_may_change_only_the_letter_case_of_its_own_email(): void
    {
        [$dealer, $login] = $this->dealerAndLogin('autotest+own-' . Str::random(8) . '@example.test');

        $this->actingAs($login)
            ->put(route('dealer.profile.update'), $this->selfForm($dealer, ['contact_email' => strtoupper($dealer->contact_email)]))
            ->assertSessionHasNoErrors();
    }

    public function test_saving_other_fields_still_works_when_an_old_duplicate_already_exists(): void
    {
        $shared = $this->freeEmail();
        $this->makeAdmin('ADMIN', ['email' => $shared]);          // an old duplicate, created before this rule
        [$dealer, $login] = $this->dealerAndLogin($shared);

        $this->actingAs($login)
            ->put(route('dealer.profile.update'), $this->selfForm($dealer, ['address' => 'New autotest address']))
            ->assertSessionHasNoErrors();

        $this->assertSame('New autotest address', $dealer->fresh()->address);
    }

    // ───────────────────────── the administrator's dealer profile page ─────────────────────────

    public function test_an_administrator_cannot_give_a_dealer_the_email_of_another_login(): void
    {
        $boss = $this->makeAdmin('ADMIN');
        [$dealer, $login] = $this->dealerAndLogin();
        $original = $dealer->contact_email;

        $this->actingAs($boss)->from(route('admin.dealer.profile', $dealer->id))
            ->put(route('admin.dealer.profile.update', $dealer->id), $this->adminForm($dealer, ['contact_email' => $boss->email]))
            ->assertSessionHasErrors(['contact_email' => self::TAKEN]);

        $this->assertSame($original, $dealer->fresh()->contact_email);
        $this->assertSame($original, $login->fresh()->email);
    }

    public function test_an_administrator_can_give_a_dealer_a_free_email_and_the_login_follows(): void
    {
        $boss = $this->makeAdmin('ADMIN');
        [$dealer, $login] = $this->dealerAndLogin();
        $new = $this->freeEmail();

        $this->actingAs($boss)
            ->put(route('admin.dealer.profile.update', $dealer->id), $this->adminForm($dealer, ['contact_email' => $new]))
            ->assertSessionHasNoErrors();

        $this->assertSame($new, $dealer->fresh()->contact_email);
        $this->assertSame($new, $login->fresh()->email);
    }

    public function test_an_administrator_can_save_a_dealer_with_an_old_duplicate_email_unchanged(): void
    {
        $boss = $this->makeAdmin('ADMIN');
        $shared = $this->freeEmail();
        $this->makeAdmin('FINANCE', ['email' => $shared]);
        [$dealer] = $this->dealerAndLogin($shared);

        $this->actingAs($boss)
            ->put(route('admin.dealer.profile.update', $dealer->id), $this->adminForm($dealer, ['address' => '5 Autotest Lane']))
            ->assertSessionHasNoErrors();

        $this->assertSame('5 Autotest Lane', $dealer->fresh()->address);
    }

    // ───────────────────────── creating a dealer ─────────────────────────

    public function test_a_new_dealer_cannot_be_created_with_the_email_of_an_existing_login(): void
    {
        $boss = $this->makeAdmin('ADMIN');
        $before = Dealer::count();
        $loginsBefore = Admin::count();

        $this->actingAs($boss)
            ->post(route('admin.dealer.store'), [
                'full_name' => 'Autotest Clash ' . Str::random(5), 'region' => 'colombo', 'country' => 'Sri Lanka',
                'dealer_status' => 'retailer', 'contact_email' => strtoupper($boss->email), 'password' => 'Autotest#12345',
            ])
            ->assertSessionHasErrors(['contact_email' => self::TAKEN]);

        $this->assertSame($before, Dealer::count());
        $this->assertSame($loginsBefore, Admin::count());
    }

    public function test_a_new_dealer_with_a_free_email_gets_a_login_with_that_email(): void
    {
        $boss = $this->makeAdmin('ADMIN');
        $email = $this->freeEmail();

        $this->actingAs($boss)
            ->post(route('admin.dealer.store'), [
                'full_name' => 'Autotest Fresh ' . Str::random(5), 'region' => 'colombo', 'country' => 'Sri Lanka',
                'dealer_status' => 'retailer', 'contact_email' => $email, 'password' => 'Autotest#12345',
            ])
            ->assertSessionHasNoErrors();

        $dealer = Dealer::where('contact_email', $email)->firstOrFail();
        $this->assertSame(1, Admin::where('dealer_id', $dealer->id)->where('email', $email)->count());
    }

    // ───────────────────────── the leftover Laravel "users" table ─────────────────────────
    // Sign-in uses the Admins table only. The default "users" table is not read by anything, and has no
    // dealer_id column, so the portal must never write to it (it used to crash dealer creation with a 500
    // error and copied dealer password hashes into a table nobody uses).

    public function test_creating_a_dealer_works_when_a_leftover_users_row_has_the_same_email(): void
    {
        $email = $this->freeEmail();
        $old = User::create(['name' => 'Old User', 'email' => $email, 'password' => 'not-a-real-hash']);
        $storedBefore = $old->fresh()->password;   // the model hashes it on save

        $response = $this->actingAs($this->makeAdmin('ADMIN'))
            ->post(route('admin.dealer.store'), [
                'full_name' => 'Autotest Leftover ' . Str::random(5), 'region' => 'colombo', 'country' => 'Sri Lanka',
                'dealer_status' => 'retailer', 'contact_email' => $email, 'password' => 'Autotest#12345',
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertLessThan(500, $response->getStatusCode());

        $dealer = Dealer::where('contact_email', $email)->firstOrFail();
        $this->assertSame(1, Admin::where('dealer_id', $dealer->id)->count());
        $this->assertSame($storedBefore, $old->fresh()->password);
    }

    public function test_a_new_dealer_password_changes_the_login_and_never_touches_the_users_table(): void
    {
        [$dealer, $login] = $this->dealerAndLogin();
        $leftover = User::create(['name' => 'Old User', 'email' => $dealer->contact_email, 'password' => 'not-a-real-hash']);
        $storedBefore = $leftover->fresh()->password;   // the model hashes it on save

        $this->actingAs($this->makeAdmin('ADMIN'))
            ->put(route('admin.dealer.profile.update', $dealer->id), $this->adminForm($dealer, [
                'password' => 'Brand#NewPass91', 'password_confirmation' => 'Brand#NewPass91',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Brand#NewPass91', $login->fresh()->password));
        $this->assertSame($storedBefore, $leftover->fresh()->password);
    }
}