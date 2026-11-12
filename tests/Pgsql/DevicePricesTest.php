<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\DevicePrice;
use App\Models\DeviceType;
use App\Services\Billing\BillingSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsSupplierData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * DEVICE PRICES (Master Pages), on real PostgreSQL (autotest schema).
 *
 *   GET   admin.device-prices          ADMIN: one card per device type with the whole price chain
 *   PATCH admin.device-prices.update   ADMIN: save landing cost, the three margins and the date
 *
 * What the tests prove (simple words):
 *   - every device type is listed, and a type with no price yet says so instead of showing a made-up chain,
 *   - the chain is worked out right (landing + company = distributor cost, + distributor margin = retailer price,
 *     + retailer margin = customer price) and the total margin is the sum of the three margins,
 *   - tax is shown ONLY when it is switched on in the billing settings, and is never guessed,
 *   - saving creates the price the first time and changes the same row after that (never two rows for one type),
 *   - the landing cost must be more than zero, margins may be zero but never negative, amounts have at most
 *     2 decimals and stay inside the limit, the date must be a real date; a refused save changes nothing,
 *   - every real change is audited with who, what and from/to; saving the same values again is "nothing changed"
 *     and writes no audit line,
 *   - only the company ADMIN may open or change prices (dealer, distributor, finance, technician, supplier get 403),
 *   - supplier prices are shown next to the landing cost, and what suppliers charge is never copied into it,
 *   - names are shown as text, never run as a script.
 *
 * Nothing real is touched: every row is made up (AUTOTEST names) and rolled back after each test.
 * ONE login per test (a second login mid-test is logged out by AuthenticateSession).
 */
class DevicePricesTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsSupplierData;

    private ?Admin $admin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->quietApi();
    }

    private function admin(): Admin
    {
        return $this->admin ??= $this->makeAdmin('ADMIN');
    }

    private function good(array $over = []): array
    {
        return array_merge([
            'landing_cost'       => '1000.00',
            'company_margin'     => '200.00',
            'distributor_margin' => '100.00',
            'retailer_margin'    => '150.00',
            'effective_from'     => '2026-11-01',
        ], $over);
    }

    private function savePrice(DeviceType $type, array $body)
    {
        return $this->actingAs($this->admin())->from(route('admin.device-prices'))
            ->patch(route('admin.device-prices.update', $type->id), $body);
    }

    private function auditFor(DeviceType $type): \Illuminate\Support\Collection
    {
        return AuditLog::where('action', 'device_price.updated')->where('subject_id', (string) $type->id)->get();
    }

    private function setTax(?string $rate, string $label = 'VAT'): void
    {
        app(BillingSettings::class)->save(
            ['tax_enabled' => $rate === null ? '0' : '1', 'tax_rate' => $rate ?? '0.00', 'tax_label' => $label],
            $this->admin()->admin_id
        );
    }

    // ================================================================== the page

    public function test_every_device_type_is_listed_and_one_without_a_price_says_so(): void
    {
        $priced   = $this->makeDeviceType(['device_category' => 'AUTOTEST-PRICED', 'model' => 'P1']);
        $unpriced = $this->makeDeviceType(['device_category' => 'AUTOTEST-NOPRICE', 'model' => 'N1']);
        DevicePrice::create(['device_type_id' => $priced->id] + $this->good());

        $html = $this->actingAs($this->admin())->get(route('admin.device-prices'))->assertOk()->getContent();

        $this->assertStringContainsString('AUTOTEST-PRICED P1', $html);
        $this->assertStringContainsString('AUTOTEST-NOPRICE N1', $html);
        // Only the priced one has a chain: one customer price of Rs. 1,450.00 appears, and the unpriced card has none.
        $this->assertSame(1, substr_count($html, 'Rs. 1,450.00'));
    }

    public function test_the_chain_is_worked_out_right(): void
    {
        $type = $this->makeDeviceType(['device_category' => 'AUTOTEST-CHAIN']);
        DevicePrice::create(['device_type_id' => $type->id] + $this->good());

        $html = $this->actingAs($this->admin())->get(route('admin.device-prices'))->assertOk()->getContent();

        $this->assertStringContainsString('Company to distributor', $html);
        $this->assertStringContainsString('Distributor to retailer', $html);
        $this->assertStringContainsString('1,200.00', $html);                  // distributor cost 1000 + 200
        $this->assertStringContainsString('1,300.00', $html);                  // retailer price 1200 + 100
        $this->assertStringContainsString('Rs. 1,450.00', $html);              // customer price 1300 + 150
        $this->assertStringContainsString('Total margin across the chain: Rs. 450.00', $html);
    }

    public function test_a_zero_margin_is_a_valid_chain(): void
    {
        $type = $this->makeDeviceType(['device_category' => 'AUTOTEST-ZERO']);
        DevicePrice::create(['device_type_id' => $type->id] + $this->good(['company_margin' => '0.00', 'distributor_margin' => '0.00', 'retailer_margin' => '0.00']));

        $html = $this->actingAs($this->admin())->get(route('admin.device-prices'))->assertOk()->getContent();

        $this->assertStringContainsString('Rs. 1,000.00', $html);
        $this->assertStringContainsString('Total margin across the chain: Rs. 0.00', $html);
    }

    public function test_tax_is_hidden_until_it_is_switched_on_and_then_shown_with_its_label(): void
    {
        $type = $this->makeDeviceType(['device_category' => 'AUTOTEST-TAX']);
        DevicePrice::create(['device_type_id' => $type->id] + $this->good());

        $off = $this->actingAs($this->admin())->get(route('admin.device-prices'))->assertOk()->getContent();
        $this->assertStringNotContainsString('(from billing settings)', $off);

        $this->setTax('8.00', 'GST');
        $on = $this->get(route('admin.device-prices'))->assertOk()->getContent();
        $this->assertStringContainsString('GST at 8.00% (from billing settings)', $on);
        $this->assertStringContainsString('96.00', $on);                       // 8% of the 1,200.00 first sale
    }

    public function test_a_tax_rate_of_zero_counts_as_no_tax(): void
    {
        $type = $this->makeDeviceType(['device_category' => 'AUTOTEST-TAX0']);
        DevicePrice::create(['device_type_id' => $type->id] + $this->good());
        DB::table('billing_settings')->updateOrInsert(['key' => 'tax_enabled'], ['value' => '1', 'updated_at' => now()]);
        DB::table('billing_settings')->updateOrInsert(['key' => 'tax_rate'], ['value' => '0.00', 'updated_at' => now()]);

        $this->actingAs($this->admin())->get(route('admin.device-prices'))->assertOk()
            ->assertDontSee('(from billing settings)', false);
    }

    public function test_the_edit_form_is_filled_with_the_saved_values_and_the_right_address(): void
    {
        $type = $this->makeDeviceType(['device_category' => 'AUTOTEST-FORM']);
        DevicePrice::create(['device_type_id' => $type->id] + $this->good(['landing_cost' => '1234.50', 'effective_from' => '2026-10-05']));

        $html = $this->actingAs($this->admin())->get(route('admin.device-prices'))->assertOk()->getContent();

        $this->assertStringContainsString('value="1234.50"', $html);
        $this->assertStringContainsString('value="2026-10-05"', $html);
        $this->assertStringContainsString(route('admin.device-prices.update', $type->id), $html);
        $this->assertStringContainsString('Effective 2026-10-05', $html);
    }

    public function test_a_page_with_no_device_types_of_ours_still_opens(): void
    {
        $this->actingAs($this->admin())->get(route('admin.device-prices'))->assertOk();
    }

    public function test_suppliers_prices_are_shown_next_to_the_landing_cost_but_never_copied_into_it(): void
    {
        $type = $this->makeDeviceType(['device_category' => 'AUTOTEST-SUP']);
        $sup  = $this->makeSupplier(['name' => 'Autotest Cheap Supplier']);
        $this->offer($sup, $this->makeDeviceProduct($type), 777.25, 3);

        $html = $this->actingAs($this->admin())->get(route('admin.device-prices'))->assertOk()->getContent();

        $this->assertStringContainsString('Autotest Cheap Supplier Rs. 777.25', $html);
        $this->assertNull(DevicePrice::where('device_type_id', $type->id)->first());   // nothing was saved for it
    }

    public function test_a_supplier_name_is_shown_as_text_never_run_as_a_script(): void
    {
        $type = $this->makeDeviceType(['device_category' => 'AUTOTEST-XSS']);
        $sup  = $this->makeSupplier(['name' => '<script>alert(1)</script>']);
        $this->offer($sup, $this->makeDeviceProduct($type), 10, 1);

        $html = $this->actingAs($this->admin())->get(route('admin.device-prices'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    // ================================================================== saving

    public function test_the_first_save_creates_the_price_with_who_saved_it_and_audits_it(): void
    {
        $type = $this->makeDeviceType(['device_category' => 'AUTOTEST-NEW', 'model' => 'M9']);

        $this->savePrice($type, $this->good())->assertRedirect(route('admin.device-prices'))
            ->assertSessionHas('success', 'AUTOTEST-NEW M9 price saved.');

        $row = DevicePrice::where('device_type_id', $type->id)->firstOrFail();
        $this->assertSame('1000.00', (string) $row->landing_cost);
        $this->assertSame('200.00', (string) $row->company_margin);
        $this->assertSame('100.00', (string) $row->distributor_margin);
        $this->assertSame('150.00', (string) $row->retailer_margin);
        $this->assertSame('2026-11-01', $row->effective_from->format('Y-m-d'));
        $this->assertSame($this->admin()->admin_id, $row->updated_by);

        $audit = $this->auditFor($type);
        $this->assertCount(1, $audit);
        $this->assertSame('AUTOTEST-NEW M9', $audit->first()->subject_label);
        $changes = $audit->first()->changes;
        $this->assertNull($changes['landing_cost']['from']);
        $this->assertSame('1000.00', $changes['landing_cost']['to']);
    }

    public function test_saving_again_changes_the_same_row_and_audits_only_what_changed(): void
    {
        $type = $this->makeDeviceType(['device_category' => 'AUTOTEST-EDIT']);
        $this->savePrice($type, $this->good())->assertSessionHas('success');

        $this->savePrice($type, $this->good(['landing_cost' => '1100.00', 'effective_from' => '2026-12-01']))
            ->assertSessionHas('success');

        $this->assertSame(1, DevicePrice::where('device_type_id', $type->id)->count());
        $this->assertSame('1100.00', (string) DevicePrice::where('device_type_id', $type->id)->value('landing_cost'));

        $second = $this->auditFor($type)->sortBy('id')->last();
        $this->assertEqualsCanonicalizing(['landing_cost', 'effective_from'], array_keys($second->changes));
        $this->assertEquals(['from' => '1000.00', 'to' => '1100.00'], $second->changes['landing_cost']);
        $this->assertEquals(['from' => '2026-11-01', 'to' => '2026-12-01'], $second->changes['effective_from']);
    }

    public function test_saving_the_same_values_says_nothing_changed_and_writes_no_audit_line(): void
    {
        $type = $this->makeDeviceType(['device_category' => 'AUTOTEST-SAME']);
        $this->savePrice($type, $this->good());
        $before = $this->auditFor($type)->count();

        $this->savePrice($type, $this->good())->assertSessionHas('success', 'AUTOTEST-SAME Basic price saved (nothing changed).');

        $this->assertSame($before, $this->auditFor($type)->count());
    }

    public function test_the_price_can_be_typed_with_fewer_decimals_or_none(): void
    {
        $type = $this->makeDeviceType(['device_category' => 'AUTOTEST-DEC']);

        $this->savePrice($type, $this->good(['landing_cost' => '1500', 'company_margin' => '250.5', 'distributor_margin' => '0', 'retailer_margin' => '99.9']))
            ->assertSessionHasNoErrors();

        $row = DevicePrice::where('device_type_id', $type->id)->firstOrFail();
        $this->assertSame('1500.00', (string) $row->landing_cost);
        $this->assertSame('250.50', (string) $row->company_margin);
        $this->assertSame('0.00', (string) $row->distributor_margin);
        $this->assertSame('99.90', (string) $row->retailer_margin);
    }

    public function test_the_biggest_allowed_amount_saves_and_one_cent_more_is_refused(): void
    {
        $type = $this->makeDeviceType(['device_category' => 'AUTOTEST-MAX']);

        $this->savePrice($type, $this->good(['landing_cost' => '9999999.99']))->assertSessionHasNoErrors();
        $this->assertSame('9999999.99', (string) DevicePrice::where('device_type_id', $type->id)->value('landing_cost'));

        $this->savePrice($type, $this->good(['landing_cost' => '10000000.00']))->assertSessionHasErrors('landing_cost');
        $this->assertSame('9999999.99', (string) DevicePrice::where('device_type_id', $type->id)->value('landing_cost'));
    }

    public static function badBodies(): array
    {
        return [
            'landing cost zero'              => [['landing_cost' => '0'], 'landing_cost'],
            'landing cost 0.00'              => [['landing_cost' => '0.00'], 'landing_cost'],
            'landing cost negative'          => [['landing_cost' => '-5'], 'landing_cost'],
            'landing cost missing'           => [['landing_cost' => ''], 'landing_cost'],
            'landing cost words'             => [['landing_cost' => 'ten'], 'landing_cost'],
            'landing cost 3 decimals'        => [['landing_cost' => '10.005'], 'landing_cost'],
            'landing cost too big'           => [['landing_cost' => '99999999'], 'landing_cost'],
            'company margin negative'        => [['company_margin' => '-0.01'], 'company_margin'],
            'company margin missing'         => [['company_margin' => ''], 'company_margin'],
            'company margin words'           => [['company_margin' => 'abc'], 'company_margin'],
            'company margin 3 decimals'      => [['company_margin' => '1.234'], 'company_margin'],
            'company margin too big'         => [['company_margin' => '10000000'], 'company_margin'],
            'distributor margin negative'    => [['distributor_margin' => '-1'], 'distributor_margin'],
            'distributor margin missing'     => [['distributor_margin' => ''], 'distributor_margin'],
            'distributor margin 3 decimals'  => [['distributor_margin' => '5.555'], 'distributor_margin'],
            'retailer margin negative'       => [['retailer_margin' => '-1'], 'retailer_margin'],
            'retailer margin missing'        => [['retailer_margin' => ''], 'retailer_margin'],
            'retailer margin too big'        => [['retailer_margin' => '12345678'], 'retailer_margin'],
            'date missing'                   => [['effective_from' => ''], 'effective_from'],
            'date not a date'                => [['effective_from' => 'tomorrow-ish'], 'effective_from'],
            'date impossible'                => [['effective_from' => '2026-13-45'], 'effective_from'],
        ];
    }

    #[DataProvider('badBodies')]
    public function test_a_bad_value_is_refused_with_its_field_named_and_nothing_is_saved(array $override, string $badField): void
    {
        $type = $this->makeDeviceType(['device_category' => 'AUTOTEST-BAD']);

        $this->savePrice($type, $this->good($override))->assertSessionHasErrors($badField);

        $this->assertNull(DevicePrice::where('device_type_id', $type->id)->first());
        $this->assertCount(0, $this->auditFor($type));
    }

    public function test_a_refused_save_leaves_the_old_price_and_the_audit_untouched(): void
    {
        $type = $this->makeDeviceType(['device_category' => 'AUTOTEST-KEEP']);
        $this->savePrice($type, $this->good());
        $audits = $this->auditFor($type)->count();

        $this->savePrice($type, $this->good(['landing_cost' => '-1', 'company_margin' => '999']))->assertSessionHasErrors('landing_cost');

        $row = DevicePrice::where('device_type_id', $type->id)->firstOrFail();
        $this->assertSame('1000.00', (string) $row->landing_cost);
        $this->assertSame('200.00', (string) $row->company_margin);
        $this->assertSame($audits, $this->auditFor($type)->count());
    }

    public function test_a_missing_device_type_is_a_404_and_saves_nothing(): void
    {
        $before = DevicePrice::count();

        $this->actingAs($this->admin())->patch(route('admin.device-prices.update', 999999999), $this->good())->assertNotFound();

        $this->assertSame($before, DevicePrice::count());
    }

    public static function strangeIds(): array
    {
        return ['letters' => ['abc'], 'minus' => ['-1'], 'sql' => ["1'%20or%20'1'='1"], 'too long' => [str_repeat('9', 30)], 'decimal' => ['1.5']];
    }

    #[DataProvider('strangeIds')]
    public function test_a_strange_id_in_the_address_is_a_404_not_an_error(string $id): void
    {
        $this->actingAs($this->admin())->patch('/admin/master-pages/device-prices/' . $id, $this->good())->assertNotFound();
    }

    public function test_opening_the_update_address_with_get_or_post_is_not_allowed(): void
    {
        $type = $this->makeDeviceType(['device_category' => 'AUTOTEST-VERB']);
        $url = route('admin.device-prices.update', $type->id);

        $this->actingAs($this->admin())->get($url)->assertStatus(405);
        $this->post($url, $this->good())->assertStatus(405);
        $this->assertNull(DevicePrice::where('device_type_id', $type->id)->first());
    }

    public function test_extra_fields_in_the_request_are_ignored(): void
    {
        $type = $this->makeDeviceType(['device_category' => 'AUTOTEST-EXTRA']);

        $this->savePrice($type, $this->good(['updated_by' => 'someone-else', 'device_type_id' => 1, 'id' => 7]))->assertSessionHasNoErrors();

        $row = DevicePrice::where('device_type_id', $type->id)->firstOrFail();
        $this->assertSame($this->admin()->admin_id, $row->updated_by);
        $this->assertNotSame(7, (int) $row->id);
    }

    public function test_the_database_itself_refuses_a_price_chain_with_a_negative_part(): void
    {
        $type = $this->makeDeviceType(['device_category' => 'AUTOTEST-CHK']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('device_prices')->insert([
            'device_type_id' => $type->id, 'landing_cost' => 0, 'company_margin' => 0, 'distributor_margin' => 0,
            'retailer_margin' => 0, 'effective_from' => '2026-11-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ================================================================== who may use it

    public static function otherRoles(): array
    {
        return ['dealer' => ['DEALER'], 'distributor' => ['DISTRIBUTOR'], 'finance' => ['FINANCE'], 'technician' => ['TECHNICIAN'], 'supplier' => ['SUPPLIER']];
    }

    #[DataProvider('otherRoles')]
    public function test_only_the_company_admin_may_open_the_page(string $role): void
    {
        $this->actingAs($this->makeAdmin($role))->get(route('admin.device-prices'))->assertForbidden();
    }

    #[DataProvider('otherRoles')]
    public function test_only_the_company_admin_may_change_a_price(string $role): void
    {
        $type = $this->makeDeviceType(['device_category' => 'AUTOTEST-ROLE']);

        $this->actingAs($this->makeAdmin($role))->patch(route('admin.device-prices.update', $type->id), $this->good())->assertForbidden();

        $this->assertNull(DevicePrice::where('device_type_id', $type->id)->first());
        $this->assertCount(0, $this->auditFor($type));
    }

    public function test_a_visitor_who_is_not_signed_in_is_sent_to_login_and_nothing_is_saved(): void
    {
        $type = $this->makeDeviceType(['device_category' => 'AUTOTEST-GUEST']);

        $this->get(route('admin.device-prices'))->assertRedirect();
        $this->patch(route('admin.device-prices.update', $type->id), $this->good())->assertRedirect();

        $this->assertNull(DevicePrice::where('device_type_id', $type->id)->first());
    }
}