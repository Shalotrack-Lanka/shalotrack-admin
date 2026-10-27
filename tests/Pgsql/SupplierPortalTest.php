<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsSupplierData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * SUPPLIER PORTAL (what a supplier sees when they log in), on real PostgreSQL.
 *
 * Rules in simple words:
 *   - A SUPPLIER login sees its own dashboard (its own products) and its own profile, and nothing of any other supplier.
 *   - A login that was never linked to a supplier record gets a clear message, not an error page.
 *   - The supplier can edit its own company details and name. Email must stay unique across suppliers.
 *   - Changing the password needs the current password and follows the company password rule.
 *   - No other role can open the supplier pages, and a logged-out visitor is sent to log in.
 *
 * Nothing real is touched: all data is made up with Autotest names and rolled back after every test.
 */
class SupplierPortalTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsSupplierData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->quietApi();
    }

    private function supplierLogin(?int $supplierId = null): Admin
    {
        return $this->makeAdmin('SUPPLIER', ['supplier_id' => $supplierId]);
    }

    private function goodProfile(array $overrides = []): array
    {
        return array_merge([
            'full_name'    => 'Autotest Contact',
            'name'         => 'Autotest Renamed Supplier',
            'phone_number' => '+94 77 123 4567',
            'email'        => 'autotest+renamed' . $this->nextIntakeNumber() . '@example.test',
            'website'      => 'example.test',
            'address'      => '1 Main Street',
            'country'      => 'Sri Lanka',
            'state'        => 'Western',
            'gstin_number' => 'GST999',
        ], $overrides);
    }

    // ================================================================== dashboard

    public function test_the_dashboard_shows_only_the_suppliers_own_products(): void
    {
        $mine = $this->makeSupplier();
        $theirs = $this->makeSupplier();
        $myProduct = $this->makePlainProduct('Autotest Mine');
        $theirProduct = $this->makePlainProduct('Autotest Theirs');
        $this->offer($mine, $myProduct);
        $this->offer($theirs, $theirProduct);

        $this->actingAs($this->supplierLogin($mine->id))->get(route('supplier.dashboard'))
            ->assertOk()
            ->assertViewHas('totalProducts', 1)
            ->assertViewHas('products', fn ($p) => $p->pluck('id')->all() === [$myProduct->id])
            ->assertSee('Autotest Mine')
            ->assertDontSee('Autotest Theirs');
    }

    public function test_a_login_not_linked_to_a_supplier_sees_an_empty_dashboard_not_an_error(): void
    {
        $this->actingAs($this->supplierLogin(null))->get(route('supplier.dashboard'))
            ->assertOk()
            ->assertViewHas('supplier', null);
    }

    public function test_a_supplier_with_no_products_sees_zero(): void
    {
        $supplier = $this->makeSupplier();

        $this->actingAs($this->supplierLogin($supplier->id))->get(route('supplier.dashboard'))
            ->assertOk()
            ->assertViewHas('totalProducts', 0);
    }

    // ================================================================== profile page

    public function test_the_profile_page_shows_the_suppliers_own_details(): void
    {
        $supplier = $this->makeSupplier(['name' => 'Autotest Profile Co']);

        $this->actingAs($this->supplierLogin($supplier->id))->get(route('supplier.profile'))
            ->assertOk()
            ->assertSee('Autotest Profile Co');
    }

    public function test_a_script_in_the_company_name_is_shown_as_text_and_never_run(): void
    {
        $supplier = $this->makeSupplier(['name' => '<script>alert(1)</script>']);

        $html = $this->actingAs($this->supplierLogin($supplier->id))->get(route('supplier.profile'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    // ================================================================== editing the profile

    public function test_the_supplier_can_update_its_own_details_and_the_contact_name(): void
    {
        $supplier = $this->makeSupplier();
        $login = $this->supplierLogin($supplier->id);
        $form = $this->goodProfile();

        $this->actingAs($login)->put(route('supplier.profile.update'), $form)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $fresh = $supplier->fresh();
        $this->assertSame($form['name'], $fresh->name);
        $this->assertSame($form['email'], $fresh->email);
        $this->assertSame($form['gstin_number'], $fresh->gstin_number);
        $this->assertSame('Autotest Contact', $login->fresh()->full_name);
    }

    public function test_updating_the_profile_never_changes_another_supplier(): void
    {
        $mine = $this->makeSupplier();
        $other = $this->makeSupplier(['name' => 'Autotest Untouchable']);

        $this->actingAs($this->supplierLogin($mine->id))->put(route('supplier.profile.update'), $this->goodProfile());

        $this->assertSame('Autotest Untouchable', $other->fresh()->name);
    }

    public function test_a_supplier_cannot_change_its_own_status_through_the_profile_form(): void
    {
        $supplier = $this->makeSupplier();

        $this->actingAs($this->supplierLogin($supplier->id))
            ->put(route('supplier.profile.update'), $this->goodProfile(['status' => 'Inactive', 'supplier_id' => 999999, 'role' => 'ADMIN']));

        $this->assertSame('Active', $supplier->fresh()->status);
    }

    public function test_the_role_of_the_login_cannot_be_raised_through_the_profile_form(): void
    {
        $supplier = $this->makeSupplier();
        $login = $this->supplierLogin($supplier->id);

        $this->actingAs($login)->put(route('supplier.profile.update'), $this->goodProfile(['role' => 'ADMIN']));

        $this->assertSame('SUPPLIER', $login->fresh()->role);
    }

    public function test_the_email_must_stay_unique_but_the_supplier_may_keep_its_own(): void
    {
        $supplier = $this->makeSupplier(['email' => 'autotest+mine@example.test']);
        $this->makeSupplier(['email' => 'autotest+taken@example.test']);
        $login = $this->supplierLogin($supplier->id);

        $this->actingAs($login)->put(route('supplier.profile.update'), $this->goodProfile(['email' => 'autotest+taken@example.test']))
            ->assertSessionHasErrors('email');

        $this->actingAs($login)->put(route('supplier.profile.update'), $this->goodProfile(['email' => 'autotest+mine@example.test']))
            ->assertSessionHasNoErrors();
    }

    public function test_the_email_and_phone_may_be_left_empty(): void
    {
        $supplier = $this->makeSupplier();

        $this->actingAs($this->supplierLogin($supplier->id))
            ->put(route('supplier.profile.update'), $this->goodProfile(['email' => null, 'phone_number' => null, 'website' => null]))
            ->assertSessionHasNoErrors();

        $this->assertNull($supplier->fresh()->email);
    }

    public static function badProfiles(): array
    {
        return [
            'no contact name'            => [['full_name' => '']],
            'no company name'            => [['name' => '']],
            'company name too long'      => [['name' => str_repeat('a', 256)]],
            'contact name too long'      => [['full_name' => str_repeat('a', 256)]],
            'letters in the phone'       => [['phone_number' => 'call me']],
            'phone too short'            => [['phone_number' => '123']],
            'phone too long'             => [['phone_number' => str_repeat('1', 21)]],
            'not an email'               => [['email' => 'not-an-email']],
            'email too long'             => [['email' => str_repeat('a', 250) . '@x.test']],
            'address too long'           => [['address' => str_repeat('a', 501)]],
            'country too long'           => [['country' => str_repeat('a', 101)]],
            'state too long'             => [['state' => str_repeat('a', 101)]],
            'tax number too long'        => [['gstin_number' => str_repeat('a', 51)]],
            'website too long'           => [['website' => str_repeat('a', 256)]],
            'a list instead of a name'   => [['name' => ['a', 'b']]],
        ];
    }

    #[DataProvider('badProfiles')]
    public function test_a_bad_profile_form_is_refused_and_nothing_is_saved(array $override): void
    {
        $supplier = $this->makeSupplier(['name' => 'Autotest Keep Me']);
        $field = array_key_first($override);

        $this->actingAs($this->supplierLogin($supplier->id))
            ->from(route('supplier.profile'))
            ->put(route('supplier.profile.update'), $this->goodProfile($override))
            ->assertSessionHasErrors($field);

        $this->assertSame('Autotest Keep Me', $supplier->fresh()->name);
    }

    public function test_a_login_without_a_supplier_record_gets_a_clear_message_when_saving(): void
    {
        $this->actingAs($this->supplierLogin(null))
            ->put(route('supplier.profile.update'), $this->goodProfile())
            ->assertSessionHasErrors('supplier');
    }

    // ================================================================== password

    public function test_the_password_changes_with_the_right_current_password(): void
    {
        $supplier = $this->makeSupplier();
        $login = $this->supplierLogin($supplier->id);

        $this->actingAs($login)->put(route('supplier.profile.update'), $this->goodProfile([
            'current_password' => 'Autotest#12345',
            'new_password' => 'BrandNew#98765',
            'new_password_confirmation' => 'BrandNew#98765',
        ]))->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('BrandNew#98765', $login->fresh()->password));
    }

    public function test_a_wrong_current_password_changes_nothing_about_the_password(): void
    {
        $supplier = $this->makeSupplier();
        $login = $this->supplierLogin($supplier->id);

        $this->actingAs($login)->put(route('supplier.profile.update'), $this->goodProfile([
            'current_password' => 'wrong-one',
            'new_password' => 'BrandNew#98765',
            'new_password_confirmation' => 'BrandNew#98765',
        ]))->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('Autotest#12345', $login->fresh()->password));
    }

    public static function weakPasswords(): array
    {
        return [
            'too short'        => ['Ab1'],
            'no number'        => ['OnlyLettersHere'],
            'no capital'       => ['alllowercase123'],
            'no small letter'  => ['ALLUPPERCASE123'],
            'over 72 letters'  => [str_repeat('Aa1', 25)],
        ];
    }

    #[DataProvider('weakPasswords')]
    public function test_a_weak_new_password_is_refused(string $weak): void
    {
        $supplier = $this->makeSupplier();
        $login = $this->supplierLogin($supplier->id);

        $this->actingAs($login)->put(route('supplier.profile.update'), $this->goodProfile([
            'current_password' => 'Autotest#12345',
            'new_password' => $weak,
            'new_password_confirmation' => $weak,
        ]))->assertSessionHasErrors('new_password');

        $this->assertTrue(Hash::check('Autotest#12345', $login->fresh()->password));
    }

    public function test_the_two_new_passwords_must_match(): void
    {
        $supplier = $this->makeSupplier();
        $login = $this->supplierLogin($supplier->id);

        $this->actingAs($login)->put(route('supplier.profile.update'), $this->goodProfile([
            'current_password' => 'Autotest#12345',
            'new_password' => 'BrandNew#98765',
            'new_password_confirmation' => 'Different#98765',
        ]))->assertSessionHasErrors('new_password');

        $this->assertTrue(Hash::check('Autotest#12345', $login->fresh()->password));
    }

    // ================================================================== who may enter

    public static function otherRoles(): array
    {
        return array_map(fn ($r) => [$r], ['ADMIN', 'DEALER', 'DISTRIBUTOR', 'FINANCE', 'TECHNICIAN']);
    }

    #[DataProvider('otherRoles')]
    public function test_no_other_role_can_open_the_supplier_pages(string $role): void
    {
        $user = $this->makeAdmin($role);

        foreach (['supplier.dashboard', 'supplier.profile'] as $name) {
            $this->actingAs($user)->get(route($name))->assertForbidden();
        }
        $this->actingAs($user)->put(route('supplier.profile.update'), $this->goodProfile())->assertForbidden();
    }

    public function test_a_supplier_cannot_open_the_admin_supplier_pages(): void
    {
        $supplier = $this->makeSupplier();
        $login = $this->supplierLogin($supplier->id);

        $this->actingAs($login)->get(route('admin.suppliers'))->assertForbidden();
        $this->actingAs($login)->get(route('admin.supplier.profile.show', $supplier->id))->assertForbidden();
        $this->actingAs($login)->get(route('admin.supplier-invoice'))->assertForbidden();
        $this->actingAs($login)->get(route('admin.stock.manage'))->assertForbidden();
    }

    public function test_a_logged_out_visitor_is_sent_to_log_in(): void
    {
        $this->get(route('supplier.dashboard'))->assertRedirect();
        $this->get(route('supplier.profile'))->assertRedirect();
        $this->put(route('supplier.profile.update'), $this->goodProfile())->assertRedirect();
    }

    public function test_a_deactivated_supplier_login_is_turned_away(): void
    {
        $supplier = $this->makeSupplier();
        $login = $this->makeAdmin('SUPPLIER', ['supplier_id' => $supplier->id, 'status' => 'INACTIVE']);

        $this->actingAs($login)->get(route('supplier.dashboard'))->assertRedirect();
    }
}