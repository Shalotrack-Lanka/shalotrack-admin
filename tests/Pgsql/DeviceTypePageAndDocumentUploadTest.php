<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\DevicePrice;
use App\Models\Feature;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsSupplierData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * TWO SMALLER PAGES, on real PostgreSQL (autotest schema).
 *
 *   GET admin.add-device-type            ADMIN: the Add Device Type page (list of types + the add forms)
 *   GET admin.customer-document-upload   ADMIN: the Customer Document Upload page
 *
 * (Adding a type, adding a feature and the Excel import are tested in IntakeImportsTest.)
 *
 * What the tests prove (simple words):
 *   - every device type is listed newest first, with its category, model, protocol and feature names,
 *   - the list tells the truth about how each type is linked to the rest: its suppliers (or "No supplier"),
 *     the stock the company holds, and whether a price is set (or "Not set") with links to fix the gap,
 *   - features show in the add form, or "No features available" when there are none,
 *   - names typed by a person are shown as text, never run as a script,
 *   - the Customer Document Upload page opens with its search choices,
 *   - both pages are for the company ADMIN only.
 *
 * Everything made here is rolled back after each test. One login per test.
 */
class DeviceTypePageAndDocumentUploadTest extends PgsqlTestCase
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

    private function page(): string
    {
        return $this->actingAs($this->admin())->get(route('admin.add-device-type'))->assertOk()->getContent();
    }

    /** The text of the one table row that mentions this category. */
    private function rowOf(string $html, string $category): string
    {
        preg_match_all('#<tr>(.*?)</tr>#s', $html, $rows);
        foreach ($rows[1] as $row) {
            if (str_contains($row, $category)) {
                return preg_replace('/\s+/', ' ', strip_tags($row));
            }
        }
        $this->fail("No table row for {$category}");
    }

    // ================================================================== Add Device Type

    public function test_a_device_type_is_listed_with_category_model_and_protocol(): void
    {
        $this->makeDeviceType(['device_category' => 'AUTOTEST-LIST-A', 'model' => 'Plus', 'protocol' => 'JT808']);

        $row = $this->rowOf($this->page(), 'AUTOTEST-LIST-A');

        $this->assertStringContainsString('Plus', $row);
        $this->assertStringContainsString('JT808', $row);
    }

    public function test_the_newest_device_type_is_listed_first(): void
    {
        $old = $this->makeDeviceType(['device_category' => 'AUTOTEST-ORDER-OLD']);
        $this->makeDeviceType(['device_category' => 'AUTOTEST-ORDER-NEW']);
        \App\Models\DeviceType::whereKey($old->id)->update(['created_at' => now()->subDay()]);   // a clear gap, not the same second

        $html = $this->page();

        $this->assertLessThan(strpos($html, 'AUTOTEST-ORDER-OLD'), strpos($html, 'AUTOTEST-ORDER-NEW'));
    }

    public function test_feature_names_are_shown_for_a_type_and_a_dash_when_it_has_none(): void
    {
        $f1 = Feature::create(['name' => 'Autotest Relay Cut']);
        $f2 = Feature::create(['name' => 'Autotest Door Sensor']);
        $this->makeDeviceType(['device_category' => 'AUTOTEST-FEAT', 'features' => [$f1->id, $f2->id]]);
        $this->makeDeviceType(['device_category' => 'AUTOTEST-NOFEAT', 'features' => []]);

        $html = $this->page();

        $with = $this->rowOf($html, 'AUTOTEST-FEAT');
        $this->assertStringContainsString('Autotest Relay Cut', $with);
        $this->assertStringContainsString('Autotest Door Sensor', $with);
        $this->assertStringNotContainsString('Autotest Relay Cut', $this->rowOf($html, 'AUTOTEST-NOFEAT'));
        $this->assertStringContainsString(' - ', $this->rowOf($html, 'AUTOTEST-NOFEAT'));
    }

    public function test_the_suppliers_of_a_type_are_listed_and_a_type_without_one_says_so(): void
    {
        $with = $this->makeDeviceType(['device_category' => 'AUTOTEST-SUP-YES']);
        $this->makeDeviceType(['device_category' => 'AUTOTEST-SUP-NO']);
        $s1 = $this->makeSupplier(['name' => 'Autotest Alpha Supplies']);
        $s2 = $this->makeSupplier(['name' => 'Autotest Beta Supplies']);
        $product = $this->makeDeviceProduct($with);
        $this->offer($s1, $product, 10, 1);
        $this->offer($s2, $product, 12, 1);

        $html = $this->page();

        $yes = $this->rowOf($html, 'AUTOTEST-SUP-YES');
        $this->assertStringContainsString('Autotest Alpha Supplies, Autotest Beta Supplies', $yes);
        $this->assertStringNotContainsString('No supplier', $yes);
        $this->assertStringContainsString('No supplier', $this->rowOf($html, 'AUTOTEST-SUP-NO'));
        $this->assertStringContainsString(route('admin.suppliers'), $html);
    }

    public function test_the_company_stock_of_each_type_is_shown_and_zero_when_there_is_none(): void
    {
        $stocked = $this->makeDeviceType(['device_category' => 'AUTOTEST-STOCK-7']);
        $this->stockFor($stocked, 7);
        $this->makeDeviceType(['device_category' => 'AUTOTEST-STOCK-0']);

        $html = $this->page();

        $this->assertMatchesRegularExpression('/AUTOTEST-STOCK-7.*?\b7\b/', $this->rowOf($html, 'AUTOTEST-STOCK-7'));
        $this->assertStringEndsWith('0 Not set', trim($this->rowOf($html, 'AUTOTEST-STOCK-0')));
    }

    public function test_a_price_shows_set_and_a_missing_price_says_not_set_with_a_link_to_fix_it(): void
    {
        $priced = $this->makeDeviceType(['device_category' => 'AUTOTEST-PRICE-SET']);
        $this->makeDeviceType(['device_category' => 'AUTOTEST-PRICE-NOT']);
        DevicePrice::create(['device_type_id' => $priced->id, 'landing_cost' => 100, 'company_margin' => 10, 'distributor_margin' => 10, 'retailer_margin' => 10, 'effective_from' => '2026-11-01']);

        $html = $this->page();

        $this->assertStringEndsWith('Set', trim($this->rowOf($html, 'AUTOTEST-PRICE-SET')));
        $this->assertStringEndsWith('Not set', trim($this->rowOf($html, 'AUTOTEST-PRICE-NOT')));
        $this->assertStringContainsString(route('admin.device-prices'), $html);
    }

    public function test_features_fill_the_add_form_and_the_empty_message_shows_when_there_are_none(): void
    {
        Feature::query()->delete();
        $this->assertStringContainsString('No features available', $this->page());

        Feature::create(['name' => 'Autotest Form Feature']);
        $html = $this->get(route('admin.add-device-type'))->assertOk()->getContent();

        $this->assertStringContainsString('Autotest Form Feature', $html);
        $this->assertStringNotContainsString('No features available', $html);
    }

    public function test_the_page_has_the_add_form_the_import_form_and_the_template_link(): void
    {
        $html = $this->page();

        $this->assertStringContainsString(route('admin.device-types.store'), $html);
        $this->assertStringContainsString(route('admin.device-types.import'), $html);
        $this->assertStringContainsString(route('admin.device-types.import-template'), $html);
        foreach (['Basic', 'Plus', 'Customize'] as $model) {
            $this->assertStringContainsString('value="' . $model . '"', $html);
        }
    }

    public function test_an_empty_list_says_no_device_types_added_yet(): void
    {
        \App\Models\Stock::query()->delete();
        \Illuminate\Support\Facades\DB::table('device_prices')->delete();
        \App\Models\DeviceType::query()->delete();

        $this->assertStringContainsString('No device types added yet.', $this->page());
    }

    public function test_names_are_shown_as_text_never_run_as_a_script(): void
    {
        $this->makeDeviceType(['device_category' => '<script>alert(1)</script>', 'protocol' => '<img src=x onerror=alert(2)>']);
        Feature::create(['name' => '<b>Bold Feature</b>']);
        $sup = $this->makeSupplier(['name' => '<i>Evil Supplier</i>']);
        $this->offer($sup, $this->makeDeviceProduct(\App\Models\DeviceType::where('device_category', '<script>alert(1)</script>')->first()), 1, 1);

        $html = $this->page();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<b>Bold Feature</b>', $html);
        $this->assertStringNotContainsString('<i>Evil Supplier</i>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_a_type_added_in_the_page_form_appears_in_the_list_afterwards(): void
    {
        $this->actingAs($this->admin())->post(route('admin.device-types.store'), [
            'device_category' => 'AUTOTEST-FORM-ADD', 'model' => 'Basic', 'protocol' => 'GT06', 'features' => [],
        ])->assertSessionHasNoErrors();

        $this->assertStringContainsString('AUTOTEST-FORM-ADD', $this->get(route('admin.add-device-type'))->assertOk()->getContent());
    }

    // ================================================================== Customer Document Upload

    public function test_the_document_upload_page_opens_with_its_title_and_search_choices(): void
    {
        $html = $this->actingAs($this->admin())->get(route('admin.customer-document-upload'))->assertOk()->getContent();

        $this->assertStringContainsString('Customer Document Upload', $html);
        $this->assertStringContainsString('Search By', $html);
        $this->assertStringContainsString('Invoice No', $html);
        $this->assertStringContainsString('value="imei"', $html);
    }

    public function test_the_document_upload_page_does_not_change_anything(): void
    {
        $before = \App\Models\ActivatedDevice::count();

        $this->actingAs($this->admin())->get(route('admin.customer-document-upload'))->assertOk();

        $this->assertSame($before, \App\Models\ActivatedDevice::count());
        $this->post(route('admin.customer-document-upload'))->assertStatus(405);
    }

    // ================================================================== who may use them

    public static function otherRoles(): array
    {
        return ['dealer' => ['DEALER'], 'distributor' => ['DISTRIBUTOR'], 'finance' => ['FINANCE'], 'technician' => ['TECHNICIAN'], 'supplier' => ['SUPPLIER']];
    }

    #[DataProvider('otherRoles')]
    public function test_only_the_company_admin_may_open_the_device_type_page(string $role): void
    {
        $this->actingAs($this->makeAdmin($role))->get(route('admin.add-device-type'))->assertForbidden();
    }

    #[DataProvider('otherRoles')]
    public function test_only_the_company_admin_may_open_the_document_upload_page(string $role): void
    {
        $this->actingAs($this->makeAdmin($role))->get(route('admin.customer-document-upload'))->assertForbidden();
    }

    public function test_a_visitor_who_is_not_signed_in_is_sent_to_login_on_both_pages(): void
    {
        $this->get(route('admin.add-device-type'))->assertRedirect();
        $this->get(route('admin.customer-document-upload'))->assertRedirect();
    }
}