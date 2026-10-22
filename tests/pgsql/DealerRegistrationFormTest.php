<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Dealer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * Admin > Dealer Management: the two registration forms (POST admin.dealer.store).
 *   form_kind = distributor : always creates a DISTRIBUTOR, whatever type or parent is posted
 *   form_kind = dealer      : always creates an "Authorized Dealer", never a distributor; may be attached to an
 *                             active distributor (distributor_id) or to nobody (direct dealer)
 *
 * The person who signs in is decided by the server: type "distributor" gets a DISTRIBUTOR login, every other type a
 * DEALER login. These tests post forged forms to prove the browser cannot talk the server into anything else.
 * Runs in the isolated "autotest" schema; every row is rolled back by DatabaseTransactions.
 */
class DealerRegistrationFormTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    private ?Admin $admin = null;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
    }

    /** One admin per test (a second login mid-test would be logged out, see PaidActivationTest). */
    private function admin(): Admin
    {
        return $this->admin ??= $this->makeAdmin('ADMIN');
    }

    private function payload(array $overrides = []): array
    {
        $tag = substr(md5((string) microtime(true) . ++$this->n), 0, 8);

        return array_merge([
            'full_name'     => 'Autotest Reg ' . $tag,
            'region'        => 'colombo',
            'country'       => 'Sri Lanka',
            'contact_email' => "autotest+reg-{$tag}@example.test",
            'password'      => 'Autotest#12345',
        ], $overrides);
    }

    private function submit(array $data)
    {
        return $this->actingAs($this->admin())->post(route('admin.dealer.store'), $data);
    }

    private function dealerOf(array $data): ?Dealer
    {
        return Dealer::where('contact_email', $data['contact_email'])->first();
    }

    private function loginOf(Dealer $dealer): ?Admin
    {
        return Admin::where('dealer_id', $dealer->id)->first();
    }

    /** A distributor made directly in the database (not through the form), so it can be used as a parent. */
    private function makeDistributor(string $status = 'active'): Dealer
    {
        return Dealer::create([
            'full_name'     => 'Autotest Parent Distributor ' . ++$this->n,
            'dealer_status' => 'distributor',
            'region'        => 'colombo',
            'country'       => 'Sri Lanka',
            'contact_email' => "autotest+parent-{$this->n}-" . substr(md5((string) microtime(true)), 0, 6) . '@example.test',
            'status'        => $status,
            'created_by'    => 'autotest',
        ]);
    }

    public function test_the_distributor_form_always_creates_a_distributor_even_if_the_form_is_forged(): void
    {
        $parent = $this->makeDistributor();
        // A forged form: says "retailer" and tries to hang the new distributor under another distributor.
        $data = $this->payload(['form_kind' => 'distributor', 'dealer_status' => 'retailer', 'distributor_id' => $parent->id]);

        $this->submit($data)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('saved_kind', 'distributor')
            ->assertSessionHas('success', "Distributor '{$data['full_name']}' saved successfully.");

        $dealer = $this->dealerOf($data);
        $this->assertNotNull($dealer, 'distributor was not created');
        $this->assertSame('distributor', $dealer->dealer_status);
        $this->assertNull($dealer->distributor_id, 'a distributor never reports to another distributor');
        $this->assertSame('active', $dealer->status);

        $login = $this->loginOf($dealer);
        $this->assertNotNull($login);
        $this->assertSame('DISTRIBUTOR', $login->role);
        $this->assertSame('ACTIVE', $login->status);
    }

    public function test_the_dealer_form_can_never_create_a_distributor(): void
    {
        // A forged form: says "distributor" although it is the dealer form.
        $data = $this->payload(['form_kind' => 'dealer', 'dealer_status' => 'distributor']);

        $this->submit($data)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('saved_kind', 'dealer')
            ->assertSessionHas('success', "Dealer '{$data['full_name']}' saved successfully.");

        $dealer = $this->dealerOf($data);
        $this->assertNotNull($dealer);
        $this->assertSame('Authorized Dealer', $dealer->dealer_status, 'the dealer form always makes a plain Authorized Dealer');
        $this->assertSame('DEALER', $this->loginOf($dealer)->role, 'and its login can never be a DISTRIBUTOR');
    }

    public function test_a_dealer_can_be_attached_to_an_active_distributor(): void
    {
        $parent = $this->makeDistributor();
        $data   = $this->payload(['form_kind' => 'dealer', 'distributor_id' => $parent->id]);

        $this->submit($data)->assertSessionHasNoErrors();

        $dealer = $this->dealerOf($data);
        $this->assertSame($parent->id, (int) $dealer->distributor_id);
        $this->assertSame('DEALER', $this->loginOf($dealer)->role);
    }

    public function test_a_dealer_with_no_distributor_is_a_direct_dealer(): void
    {
        // The browser sends an empty text for "no distributor".
        $data = $this->payload(['form_kind' => 'dealer', 'distributor_id' => '']);

        $this->submit($data)->assertSessionHasNoErrors();

        $this->assertNull($this->dealerOf($data)->distributor_id);
    }

    public function test_the_parent_distributor_must_be_a_real_active_distributor(): void
    {
        $archived = $this->makeDistributor('archived');
        $retailer = Dealer::create([
            'full_name' => 'Autotest Not A Distributor', 'dealer_status' => 'retailer', 'region' => 'colombo',
            'country' => 'Sri Lanka', 'contact_email' => 'autotest+notdist-' . substr(md5((string) microtime(true)), 0, 6) . '@example.test',
            'status' => 'active', 'created_by' => 'autotest',
        ]);

        $bad = [
            'an id that does not exist' => 999999999,
            'an archived distributor'   => $archived->id,
            'a dealer that is not a distributor' => $retailer->id,
            'text instead of a number'  => 'abc',
        ];

        foreach ($bad as $why => $value) {
            $data = $this->payload(['form_kind' => 'dealer', 'distributor_id' => $value]);

            $this->submit($data)->assertSessionHasErrors('distributor_id');
            $this->assertNull($this->dealerOf($data), "nothing may be created for {$why}");
        }
    }

    public function test_an_unknown_form_kind_is_refused(): void
    {
        $data = $this->payload(['form_kind' => 'admin', 'dealer_status' => 'retailer']);

        $this->submit($data)->assertStatus(422);

        $this->assertNull($this->dealerOf($data));
    }

    public function test_every_type_except_distributor_gets_a_dealer_login_and_an_unknown_type_is_refused(): void
    {
        foreach (['lbc', 'retailer', 'dsa', 'ba', 'csa', 'lt_point', 'Authorized Dealer'] as $type) {
            $data = $this->payload(['dealer_status' => $type]);

            $this->submit($data)->assertSessionHasNoErrors();

            $dealer = $this->dealerOf($data);
            $this->assertSame($type, $dealer->dealer_status);
            $this->assertSame('DEALER', $this->loginOf($dealer)->role, "type {$type} must get a DEALER login");
        }

        $bad = $this->payload(['dealer_status' => 'superuser']);
        $this->submit($bad)->assertSessionHasErrors('dealer_status');
        $this->assertNull($this->dealerOf($bad));
    }

    public function test_two_dealers_with_the_same_name_get_two_different_logins(): void
    {
        $name  = 'Autotest Same Name ' . substr(md5((string) microtime(true)), 0, 6);
        $first = $this->payload(['full_name' => $name, 'form_kind' => 'dealer']);
        $second = $this->payload(['full_name' => $name, 'form_kind' => 'dealer']);

        $this->submit($first)->assertSessionHasNoErrors();
        $this->submit($second)->assertSessionHasNoErrors();

        $a = $this->loginOf($this->dealerOf($first));
        $b = $this->loginOf($this->dealerOf($second));

        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertNotSame($a->username, $b->username, 'two logins must never share a username');
    }

    public function test_the_password_typed_by_the_admin_is_stored_hashed_and_never_written_to_the_audit_log(): void
    {
        $data = $this->payload(['form_kind' => 'dealer', 'password' => 'Autotest#Secret99']);

        $this->submit($data)->assertSessionHasNoErrors();

        $dealer = $this->dealerOf($data);
        $login  = $this->loginOf($dealer);
        $this->assertTrue(Hash::check('Autotest#Secret99', $login->password), 'the dealer can sign in with the password the admin typed');
        $this->assertNotSame('Autotest#Secret99', $login->password);
        $this->assertNotSame('Autotest#Secret99', $dealer->fresh()->password, 'the dealer record keeps only a hash too');

        $log = AuditLog::where('action', 'dealer.created')->where('subject_label', $data['full_name'])->first();
        $this->assertNotNull($log, 'creating a dealer must be audited');
        $this->assertSame('DEALER', $log->meta['login_role']);
        $this->assertTrue($log->meta['password_set_by_admin']);
        $this->assertStringNotContainsString('Autotest#Secret99', json_encode([$log->changes, $log->meta]), 'a password must never reach the audit log');
    }

    public function test_the_audit_entry_of_a_distributor_records_its_login_role(): void
    {
        $data = $this->payload(['form_kind' => 'distributor']);

        $this->submit($data)->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'dealer.created')->where('subject_label', $data['full_name'])->first();
        $this->assertNotNull($log);
        $this->assertSame('DISTRIBUTOR', $log->meta['login_role']);
        $this->assertSame('distributor', $log->meta['dealer_status']);
    }

    public function test_the_dealer_management_page_opens(): void
    {
        $this->actingAs($this->admin())->get(route('admin.dealer-management'))->assertOk();
    }

    public static function blockedRoles(): array
    {
        return ['a dealer login' => ['DEALER'], 'a distributor login' => ['DISTRIBUTOR'], 'a finance login' => ['FINANCE']];
    }

    #[DataProvider('blockedRoles')]
    public function test_only_an_admin_can_register_dealers_or_distributors(string $role): void
    {
        $login = $this->makeAdmin($role);

        foreach (['distributor', 'dealer'] as $kind) {
            $data = $this->payload(['form_kind' => $kind]);

            $this->actingAs($login)->post(route('admin.dealer.store'), $data)->assertForbidden();

            $this->assertNull($this->dealerOf($data), "a {$role} login must not be able to register a {$kind}");
        }
    }
}