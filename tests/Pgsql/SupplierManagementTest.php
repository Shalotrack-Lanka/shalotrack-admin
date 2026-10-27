<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\DeviceType;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsSupplierData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * SUPPLIER MANAGEMENT (the admin's side), on real PostgreSQL.
 *
 * A supplier is a company that sells devices to us. The admin adds suppliers, says which products each one
 * offers (with a price and a quantity), activates or deactivates them, and reads their profile.
 * Rules in simple words:
 *   - Every supplier starts Active. Name is the only required detail. Email, if given, is unique.
 *   - A product line is either a device type from the system or a free-text name, with a price and quantity that
 *     are numbers inside sensible limits.
 *   - Deactivating a supplier also deactivates their supplier login. Activating turns it back on.
 *   - A bad id in the address is a clean 404, never a database error.
 *   - Only an ADMIN may do any of this.
 *
 * Nothing real is touched: all data is made up with Autotest names and rolled back after every test.
 */
class SupplierManagementTest extends PgsqlTestCase
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

    private function goodForm(array $overrides = []): array
    {
        $n = $this->nextIntakeNumber();

        return array_merge([
            'supplier_name' => "Autotest Supplier Form {$n}",
            'address'       => '12 Galle Road, Colombo',
            'country'       => 'Sri Lanka',
            'state'         => 'Western',
            'phone_number'  => '+94 11 234 5678',
            'email_id'      => "autotest+form{$n}@example.test",
            'website'       => 'https://example.test',
            'gstin'         => 'GST12345',
        ], $overrides);
    }

    // ================================================================== list and search

    public function test_the_list_shows_every_supplier_with_active_ones_first(): void
    {
        $inactive = $this->makeSupplier(['name' => 'AAA Autotest Inactive', 'status' => 'Inactive']);
        $active = $this->makeSupplier(['name' => 'ZZZ Autotest Active']);

        $this->actingAs($this->admin())->get(route('admin.suppliers'))
            ->assertOk()
            ->assertViewHas('allSuppliers', function ($list) use ($inactive, $active) {
                $ids = $list->pluck('id')->all();

                return in_array($active->id, $ids) && in_array($inactive->id, $ids)
                    && array_search($active->id, $ids) < array_search($inactive->id, $ids);
            });
    }

    public function test_a_script_in_a_supplier_name_is_shown_as_text_and_never_run(): void
    {
        $this->makeSupplier(['name' => '<script>alert(1)</script>']);

        $html = $this->actingAs($this->admin())->get(route('admin.suppliers'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_search_finds_by_name_email_phone_and_country_ignoring_capitals(): void
    {
        $a = $this->makeSupplier(['name' => 'Zebra Trackers Ltd', 'email' => 'sales@zebra-autotest.test', 'phone_number' => '+94 71 999 0001', 'country' => 'Narnia']);
        $b = $this->makeSupplier(['name' => 'Other Autotest Co']);

        foreach (['zebra trackers', 'ZEBRA-AUTOTEST', '999 0001', 'narnia'] as $term) {
            $this->actingAs($this->admin())->get(route('admin.suppliers', ['search' => $term]))
                ->assertOk()
                ->assertViewHas('searchResults', fn ($r) => $r->pluck('id')->contains($a->id) && ! $r->pluck('id')->contains($b->id));
        }
    }

    public function test_search_treats_percent_and_underscore_as_plain_letters(): void
    {
        $this->makeSupplier(['name' => 'Autotest Plain Name']);
        $literal = $this->makeSupplier(['name' => 'Autotest 100% Genuine_Parts']);

        $this->actingAs($this->admin())->get(route('admin.suppliers', ['search' => '100%']))
            ->assertViewHas('searchResults', fn ($r) => $r->pluck('id')->all() === [$literal->id]);
        $this->actingAs($this->admin())->get(route('admin.suppliers', ['search' => 'Genuine_Parts']))
            ->assertViewHas('searchResults', fn ($r) => $r->pluck('id')->all() === [$literal->id]);
        $this->actingAs($this->admin())->get(route('admin.suppliers', ['search' => '%']))
            ->assertViewHas('searchResults', fn ($r) => $r->pluck('id')->all() === [$literal->id], 'a lone % must not match everything');
    }

    public function test_the_status_filter_works_and_junk_values_are_ignored(): void
    {
        $on = $this->makeSupplier();
        $off = $this->makeSupplier(['status' => 'Inactive']);

        $this->actingAs($this->admin())->get(route('admin.suppliers', ['status' => 'Inactive']))
            ->assertViewHas('searchResults', fn ($r) => $r->pluck('id')->contains($off->id) && ! $r->pluck('id')->contains($on->id));

        foreach (['bogus', ['Active'], "Active'; DROP TABLE suppliers;--"] as $junk) {
            $this->actingAs($this->admin())->get(route('admin.suppliers', ['status' => $junk]))
                ->assertOk()
                ->assertViewHas('searchResults', fn ($r) => $r->isEmpty());
        }
        $this->assertNotNull(Supplier::find($on->id));
    }

    public function test_a_very_long_or_odd_search_does_not_break_the_page(): void
    {
        foreach ([str_repeat('a', 5000), ['x'], "a\0b", '\\', "'"] as $term) {
            $this->actingAs($this->admin())->get(route('admin.suppliers', ['search' => $term]))->assertOk();
        }
    }

    // ================================================================== add

    public function test_a_supplier_is_added_active_with_a_device_product_and_a_free_text_product(): void
    {
        $type = $this->makeDeviceType();
        $form = $this->goodForm(['products' => [
            ['device_type_id' => $type->id, 'price' => '1500.50', 'qty' => 10],
            ['product_name' => 'Autotest SIM package', 'price' => '25', 'qty' => 100],
        ]]);

        $this->actingAs($this->admin())->post(route('admin.suppliers.store'), $form)
            ->assertRedirect(route('admin.suppliers'))->assertSessionHas('success');

        $supplier = Supplier::where('name', $form['supplier_name'])->sole();
        $this->assertSame('Active', $supplier->status);
        $this->assertSame($form['email_id'], $supplier->email);
        $this->assertSame($form['gstin'], $supplier->gstin_number);

        $device = $supplier->products->firstWhere('device_type_id', $type->id);
        $this->assertNotNull($device);
        $this->assertSame(trim($type->device_category . ' ' . $type->model), $device->product_name);
        $this->assertEquals(1500.50, $device->pivot->price);
        $this->assertSame(10, (int) $device->pivot->qty);

        $free = $supplier->products->firstWhere('product_name', 'Autotest SIM package');
        $this->assertNotNull($free, 'a free-text product line must work too');
        $this->assertNull($free->device_type_id);
    }

    public function test_only_the_name_is_required(): void
    {
        $this->actingAs($this->admin())->post(route('admin.suppliers.store'), ['supplier_name' => 'Autotest Minimal'])
            ->assertSessionHasNoErrors();

        $supplier = Supplier::where('name', 'Autotest Minimal')->sole();
        $this->assertNull($supplier->email);
        $this->assertCount(0, $supplier->products);
    }

    public static function badSupplierForms(): array
    {
        return [
            'name missing'               => [['supplier_name' => '__missing__'], 'supplier_name'],
            'name over 255'              => [['supplier_name' => '__L256__'], 'supplier_name'],
            'name is a list'             => [['supplier_name' => ['x']], 'supplier_name'],
            'address over 500'           => [['address' => '__L501__'], 'address'],
            'country over 100'           => [['country' => '__L101__'], 'country'],
            'state over 100'             => [['state' => '__L101__'], 'state'],
            'phone has letters'          => [['phone_number' => '077-ABC-1234'], 'phone_number'],
            'phone too short'            => [['phone_number' => '12345'], 'phone_number'],
            'phone too long'             => [['phone_number' => '123456789012345678901'], 'phone_number'],
            'email is not an email'      => [['email_id' => 'not-an-email'], 'email_id'],
            'email over 255'             => [['email_id' => '__EMAIL256__'], 'email_id'],
            'email already used'         => [['email_id' => '{taken_email}'], 'email_id'],
            'website over 255'           => [['website' => '__L256__'], 'website'],
            'gstin over 50'              => [['gstin' => '__L51__'], 'gstin'],
            'products is not a list'     => [['products' => 'x'], 'products'],
            'a product line is text'     => [['products' => ['x']], 'products.0'],
            'product type is text'       => [['products' => [['device_type_id' => 'abc', 'price' => 1, 'qty' => 1]]], 'products.0.device_type_id'],
            'product type is too big'    => [['products' => [['device_type_id' => '99999999999999999999', 'price' => 1, 'qty' => 1]]], 'products.0.device_type_id'],
            'product type does not exist' => [['products' => [['device_type_id' => '987654321', 'price' => 1, 'qty' => 1]]], 'products.0.device_type_id'],
            'product price is text'      => [['products' => [['product_name' => 'X', 'price' => 'cheap', 'qty' => 1]]], 'products.0.price'],
            'product price is negative'  => [['products' => [['product_name' => 'X', 'price' => '-1', 'qty' => 1]]], 'products.0.price'],
            'product price too big'      => [['products' => [['product_name' => 'X', 'price' => '999999999999', 'qty' => 1]]], 'products.0.price'],
            'product qty is negative'    => [['products' => [['product_name' => 'X', 'price' => 1, 'qty' => -1]]], 'products.0.qty'],
            'product qty has decimals'   => [['products' => [['product_name' => 'X', 'price' => 1, 'qty' => 1.5]]], 'products.0.qty'],
            'product qty too big'        => [['products' => [['product_name' => 'X', 'price' => 1, 'qty' => 99999999999]]], 'products.0.qty'],
            'product name over 255'      => [['products' => [['product_name' => '__L256__', 'price' => 1, 'qty' => 1]]], 'products.0.product_name'],
        ];
    }

    #[DataProvider('badSupplierForms')]
    public function test_a_bad_supplier_form_is_refused_and_nothing_is_saved(array $overrides, string $errorField): void
    {
        $taken = $this->makeSupplier();
        $suppliers = Supplier::count();
        $products = Product::count();

        $swap = fn ($v) => match ($v) {
            '__L51__' => str_repeat('a', 51), '__L101__' => str_repeat('a', 101), '__L256__' => str_repeat('a', 256),
            '__L501__' => str_repeat('a', 501), '__EMAIL256__' => str_repeat('a', 250) . '@x.test', '{taken_email}' => $taken->email,
            default => $v,
        };
        $overrides = array_map(fn ($v) => is_string($v) ? $swap($v) : $v, $overrides);
        array_walk_recursive($overrides, function (&$v) use ($swap) {
            $v = is_string($v) ? $swap($v) : $v;
        });
        $form = array_filter($this->goodForm($overrides), fn ($v) => $v !== '__missing__');

        $this->actingAs($this->admin())->post(route('admin.suppliers.store'), $form)->assertSessionHasErrors($errorField);

        $this->assertSame($suppliers, Supplier::count());
        $this->assertSame($products, Product::count(), 'no product may be created by a refused form');
    }

    public function test_exact_limits_are_accepted(): void
    {
        $form = $this->goodForm([
            'supplier_name' => str_repeat('n', 255), 'address' => str_repeat('a', 500), 'country' => str_repeat('c', 100),
            'state' => str_repeat('s', 100), 'website' => str_repeat('w', 255), 'gstin' => str_repeat('g', 50),
            'phone_number' => '1234567', 'products' => [['product_name' => 'Autotest Limits', 'price' => '99999999', 'qty' => 1000000]],
        ]);

        $this->actingAs($this->admin())->post(route('admin.suppliers.store'), $form)->assertSessionHasNoErrors();
    }

    // ================================================================== update / edit

    public function test_an_update_changes_the_details_and_replaces_the_product_list(): void
    {
        $supplier = $this->makeSupplier();
        $old = $this->makePlainProduct();
        $this->offer($supplier, $old);
        $type = $this->makeDeviceType();

        $form = $this->goodForm([
            'supplier_name' => 'Autotest Renamed', 'email_id' => $supplier->email,   // keeping its own email is fine
            'products' => [['device_type_id' => $type->id, 'price' => 80, 'qty' => 3]],
        ]);

        $this->actingAs($this->admin())->put(route('admin.suppliers.update', $supplier->id), $form)
            ->assertRedirect(route('admin.suppliers'))->assertSessionHas('success');

        $fresh = $supplier->fresh();
        $this->assertSame('Autotest Renamed', $fresh->name);
        $this->assertSame('Active', $fresh->status, 'an update never changes the status');
        $this->assertSame([$type->id], $fresh->products->pluck('device_type_id')->all(), 'the old product is gone, the new one is there');
    }

    public function test_an_update_cannot_take_another_suppliers_email(): void
    {
        $a = $this->makeSupplier();
        $b = $this->makeSupplier();

        $this->actingAs($this->admin())->put(route('admin.suppliers.update', $a->id), $this->goodForm(['email_id' => $b->email]))
            ->assertSessionHasErrors('email_id');

        $this->assertNotSame($b->email, $a->fresh()->email);
    }

    public function test_a_refused_update_changes_nothing_including_the_product_list(): void
    {
        $supplier = $this->makeSupplier(['name' => 'Autotest Keep Me']);
        $product = $this->makePlainProduct();
        $this->offer($supplier, $product);

        $this->actingAs($this->admin())->put(route('admin.suppliers.update', $supplier->id), $this->goodForm([
            'supplier_name' => 'Should Not Save', 'products' => [['product_name' => 'X', 'price' => 'cheap', 'qty' => 1]],
        ]))->assertSessionHasErrors('products.0.price');

        $this->assertSame('Autotest Keep Me', $supplier->fresh()->name);
        $this->assertSame([$product->id], $supplier->fresh()->products->pluck('id')->all());
    }

    public function test_the_edit_data_endpoint_returns_the_supplier_and_its_products(): void
    {
        $supplier = $this->makeSupplier();
        $type = $this->makeDeviceType();
        $product = $this->makeDeviceProduct($type);
        $this->offer($supplier, $product, 250.5, 7);

        $this->actingAs($this->admin())->getJson(route('admin.suppliers.edit', $supplier->id))
            ->assertOk()
            ->assertJsonPath('name', $supplier->name)
            ->assertJsonPath('status', 'Active')
            ->assertJsonPath('products.0.device_type_id', $type->id)
            ->assertJsonPath('products.0.qty', 7);
    }

    // ================================================================== attach / detach a single product

    public function test_a_product_can_be_attached_by_device_type_and_attaching_again_updates_the_price(): void
    {
        $supplier = $this->makeSupplier();
        $type = $this->makeDeviceType();

        $this->actingAs($this->admin())->post(route('admin.suppliers.attach-product', $supplier->id), ['device_type_id' => $type->id, 'price' => 100, 'qty' => 5])
            ->assertRedirect(route('admin.suppliers'))->assertSessionHas('success');
        $this->actingAs($this->admin())->post(route('admin.suppliers.attach-product', $supplier->id), ['device_type_id' => $type->id, 'price' => 120, 'qty' => 9]);

        $products = $supplier->fresh()->products;
        $this->assertCount(1, $products, 'the same device type is one product, not two');
        $this->assertEquals(120, $products[0]->pivot->price);
        $this->assertSame(9, (int) $products[0]->pivot->qty);
        $this->assertSame(1, Product::where('device_type_id', $type->id)->count());
    }

    public function test_a_free_text_product_can_be_attached_and_does_not_touch_the_other_products(): void
    {
        $supplier = $this->makeSupplier();
        $existing = $this->makePlainProduct();
        $this->offer($supplier, $existing);

        $this->actingAs($this->admin())->post(route('admin.suppliers.attach-product', $supplier->id), ['product_name' => '  Autotest Cable  ', 'price' => 5, 'qty' => 1])
            ->assertSessionHas('success');

        $names = $supplier->fresh()->products->pluck('product_name')->all();
        $this->assertContains('Autotest Cable', $names, 'spaces around the name are removed');
        $this->assertContains($existing->product_name, $names);
    }

    public static function badAttachForms(): array
    {
        return [
            'neither type nor name' => [['device_type_id' => '__missing__', 'product_name' => '__missing__'], 'product_name'],
            'type does not exist'   => [['device_type_id' => '987654321'], 'device_type_id'],
            'type is text'          => [['device_type_id' => 'abc'], 'device_type_id'],
            'type is too big'       => [['device_type_id' => '99999999999999999999'], 'device_type_id'],
            'price missing'         => [['price' => '__missing__'], 'price'],
            'price is text'         => [['price' => 'cheap'], 'price'],
            'price is negative'     => [['price' => '-5'], 'price'],
            'price too big'         => [['price' => '999999999999'], 'price'],
            'qty missing'           => [['qty' => '__missing__'], 'qty'],
            'qty has decimals'      => [['qty' => '1.5'], 'qty'],
            'qty is negative'       => [['qty' => '-1'], 'qty'],
            'qty too big'           => [['qty' => '99999999999'], 'qty'],
            'name over 255'         => [['product_name' => '__L256__'], 'product_name'],
        ];
    }

    #[DataProvider('badAttachForms')]
    public function test_a_bad_attach_is_refused_and_nothing_is_saved(array $overrides, string $errorField): void
    {
        $supplier = $this->makeSupplier();
        $products = Product::count();
        $overrides = array_map(fn ($v) => $v === '__L256__' ? str_repeat('a', 256) : $v, $overrides);
        $form = array_filter(array_merge(['product_name' => 'Autotest Thing', 'price' => 10, 'qty' => 1], $overrides), fn ($v) => $v !== '__missing__');

        $this->actingAs($this->admin())->post(route('admin.suppliers.attach-product', $supplier->id), $form)->assertSessionHasErrors($errorField);

        $this->assertCount(0, $supplier->fresh()->products);
        $this->assertSame($products, Product::count());
    }

    public function test_detaching_removes_only_that_product(): void
    {
        $supplier = $this->makeSupplier();
        $keep = $this->makePlainProduct();
        $drop = $this->makePlainProduct();
        $this->offer($supplier, $keep);
        $this->offer($supplier, $drop);

        $this->actingAs($this->admin())->delete(route('admin.suppliers.detach-product', [$supplier->id, $drop->id]))->assertSessionHas('success');

        $this->assertSame([$keep->id], $supplier->fresh()->products->pluck('id')->all());
        $this->assertNotNull(Product::find($drop->id), 'the product itself is not deleted, only the link');
    }

    // ================================================================== activate / deactivate

    public function test_deactivating_a_supplier_also_deactivates_their_login_and_activating_turns_it_back_on(): void
    {
        $supplier = $this->makeSupplier();
        $other = $this->makeSupplier();
        $login = $this->makeAdmin('SUPPLIER', ['supplier_id' => $supplier->id]);
        $otherLogin = $this->makeAdmin('SUPPLIER', ['supplier_id' => $other->id]);

        $this->actingAs($this->admin())->patch(route('admin.suppliers.toggle-status', $supplier->id))->assertSessionHas('success');
        $this->assertSame('Inactive', $supplier->fresh()->status);
        $this->assertSame('INACTIVE', $login->fresh()->status);
        $this->assertSame('ACTIVE', $otherLogin->fresh()->status, 'another supplier is never touched');

        $this->actingAs($this->admin())->patch(route('admin.suppliers.toggle-status', $supplier->id));
        $this->assertSame('Active', $supplier->fresh()->status);
        $this->assertSame('ACTIVE', $login->fresh()->status);
    }

    public function test_a_deactivated_supplier_login_can_no_longer_use_the_portal(): void
    {
        $supplier = $this->makeSupplier();
        $login = $this->makeAdmin('SUPPLIER', ['supplier_id' => $supplier->id]);

        $this->actingAs($this->admin())->patch(route('admin.suppliers.toggle-status', $supplier->id));

        $this->actingAs($login->fresh())->get(route('supplier.dashboard'))->assertRedirect();
    }

    // ================================================================== admin view of one supplier

    public function test_the_admin_profile_of_a_supplier_adds_up_units_and_invoices(): void
    {
        $supplier = $this->makeSupplier();
        $this->offer($supplier, $this->makePlainProduct(), 10, 5);
        $this->offer($supplier, $this->makePlainProduct(), 10, 7);
        foreach ([1000.50, 250.25] as $i => $total) {
            SupplierInvoice::create(['invoice_number' => 'PO-AUTOTEST-' . $this->nextIntakeNumber(), 'supplier_id' => $supplier->id, 'invoice_date' => now()->toDateString(), 'grand_total' => $total]);
        }

        $this->actingAs($this->admin())->get(route('admin.supplier.profile.show', $supplier->id))
            ->assertOk()
            ->assertViewHas('totalStockReceived', 12)
            ->assertViewHas('totalInvoices', 2)
            ->assertViewHas('totalInvoiceValue', fn ($v) => abs($v - 1250.75) < 0.001);
    }

    // ================================================================== bad ids in the address

    public static function badIds(): array
    {
        return ['text' => ['abc'], 'too big for the database' => ['99999999999999999999'], 'a supplier that does not exist' => ['987654321']];
    }

    #[DataProvider('badIds')]
    public function test_a_bad_supplier_id_is_a_clean_404_everywhere(string $id): void
    {
        $this->actingAs($this->admin());
        $product = $this->makePlainProduct();
        $before = [Supplier::count(), Product::count()];

        $this->get("/admin/supplier/{$id}/edit")->assertNotFound();
        $this->get("/admin/supplier/{$id}/profile")->assertNotFound();
        $this->get("/admin/supplier/{$id}/purchase-data")->assertNotFound();
        $this->get("/admin/supplier/get-supplier-data/{$id}")->assertNotFound();
        $this->put("/admin/supplier/{$id}", $this->goodForm())->assertNotFound();
        $this->patch("/admin/supplier/{$id}/toggle-status")->assertNotFound();
        $this->post("/admin/supplier/{$id}/attach-product", ['product_name' => 'X', 'price' => 1, 'qty' => 1])->assertNotFound();
        $this->delete("/admin/supplier/{$id}/detach-product/{$product->id}")->assertNotFound();

        $this->assertSame($before, [Supplier::count(), Product::count()]);
    }

    public function test_a_bad_product_id_in_detach_is_a_404(): void
    {
        $supplier = $this->makeSupplier();
        $this->actingAs($this->admin());

        foreach (['abc', '99999999999999999999'] as $id) {
            $this->delete("/admin/supplier/{$supplier->id}/detach-product/{$id}")->assertNotFound();
        }
    }

    // ================================================================== who may use it

    public static function otherRoles(): array
    {
        return array_map(fn ($r) => [$r], ['DEALER', 'DISTRIBUTOR', 'FINANCE', 'TECHNICIAN', 'SUPPLIER']);
    }

    #[DataProvider('otherRoles')]
    public function test_nobody_but_an_admin_can_manage_suppliers(string $role): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makePlainProduct();
        $before = [Supplier::count(), Product::count()];
        $this->actingAs($this->makeAdmin($role));

        $this->get(route('admin.suppliers'))->assertForbidden();
        $this->post(route('admin.suppliers.store'), $this->goodForm())->assertForbidden();
        $this->get(route('admin.suppliers.edit', $supplier->id))->assertForbidden();
        $this->put(route('admin.suppliers.update', $supplier->id), $this->goodForm())->assertForbidden();
        $this->patch(route('admin.suppliers.toggle-status', $supplier->id))->assertForbidden();
        $this->post(route('admin.suppliers.attach-product', $supplier->id), ['product_name' => 'X', 'price' => 1, 'qty' => 1])->assertForbidden();
        $this->delete(route('admin.suppliers.detach-product', [$supplier->id, $product->id]))->assertForbidden();
        $this->get(route('admin.supplier.profile.show', $supplier->id))->assertForbidden();
        $this->get(route('admin.suppliers.purchase-data', $supplier->id))->assertForbidden();

        $this->assertSame($before, [Supplier::count(), Product::count()]);
        $this->assertSame('Active', $supplier->fresh()->status);
    }

    public function test_a_logged_out_visitor_is_sent_to_login(): void
    {
        $supplier = $this->makeSupplier();

        $this->get(route('admin.suppliers'))->assertRedirect('/login');
        $this->post(route('admin.suppliers.store'), [])->assertRedirect('/login');
        $this->patch(route('admin.suppliers.toggle-status', $supplier->id))->assertRedirect('/login');
    }
}