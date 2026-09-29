<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Dealer;
use App\Models\SetupShalotrackDevice;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression guards for "dead end" fixes (actions that used to fail with no
 * feedback, or with feedback that leaked internals). Own minimal schema - the
 * full migration history can't build a fresh sqlite database.
 */
class DeadEndFixesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::fake();

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

        $this->actingAs(Admin::forceCreate([
            'admin_id' => 'adm-1', 'username' => 'a', 'role' => 'ADMIN', 'status' => 'ACTIVE',
        ]));
    }

    /** The Edit button used to call /admin/dealer/stock-transfer/... which does not exist. */
    public function test_stock_transfer_edit_modal_uses_real_routes(): void
    {
        $view = file_get_contents(resource_path('views/admin/master_pages/stock_transfer.blade.php'));

        $this->assertStringNotContainsString('/admin/dealer/stock-transfer', $view);
        $this->assertStringContainsString("route('admin.stock_transfer.edit-data'", $view);
        $this->assertStringContainsString("route('admin.stock_transfer.update'", $view);

        $this->assertSame(
            '/admin/master-pages/stock-transfer/7/edit-data',
            route('admin.stock_transfer.edit-data', ['ledger' => 7], false)
        );
        $this->assertSame(
            '/admin/master-pages/stock-transfer/7',
            route('admin.stock_transfer.update', ['ledger' => 7], false)
        );
    }

    /** A database error used to be echoed to the admin verbatim (SQL, table names). */
    public function test_stock_transfer_hides_raw_database_errors(): void
    {
        Schema::create('dealers', function (Blueprint $t) {
            $t->id();
            $t->string('full_name');
            $t->timestamps();
        });
        Schema::create('setup_shalotrack_devices', function (Blueprint $t) {
            $t->id('shdevice_id');
            $t->string('device_category');
            $t->string('imei_number')->unique();
            $t->string('sim_number')->nullable();
            $t->string('status')->nullable();
            $t->unsignedBigInteger('dealer_id')->nullable();
            $t->unsignedBigInteger('transfer_id')->nullable();
            $t->timestamp('allocated_at')->nullable();
            $t->timestamps();
        });
        // dealer_transfer_ledgers deliberately NOT created: creating the
        // ledger row inside the transfer raises a QueryException.

        $dealer = Dealer::create(['full_name' => 'Kasun Rajitha']);
        SetupShalotrackDevice::create([
            'device_category' => 'V5 Basic', 'imei_number' => '868159941856606',
            'sim_number' => '0700000101', 'status' => 'Not Activated',
        ]);

        $response = $this->post(route('admin.stock_transfer.store'), [
            'device_category' => 'V5 Basic',
            'dealer_id'       => $dealer->id,
            'sim_numbers'     => ['0700000101'],
        ]);

        $response->assertSessionHasErrors('transfer');
        $message = session('errors')->first('transfer');

        $this->assertStringContainsString('database problem', $message);
        $this->assertStringNotContainsStringIgnoringCase('no such table', $message);
        $this->assertStringNotContainsStringIgnoringCase('sql', $message);

        // and the device was not moved (rolled back / never changed)
        $this->assertNull(SetupShalotrackDevice::first()->dealer_id);
    }

    /** The switch flips on screen before the server answers; on failure the page must be able to flip it back. */
    public function test_customer_status_toggle_hands_its_checkbox_to_the_page(): void
    {
        $html = view('admin.customer._status_toggle', [
            'c' => (object) ['customer_id' => 'c-1', 'cus_status' => 'verified'],
        ])->render();

        $this->assertStringContainsString('$event.target.checked, $event.target)', $html);
    }

    public function test_customer_setup_and_setup_device_report_failed_requests(): void
    {
        $setup = file_get_contents(resource_path('views/admin/customer/customer_setup.blade.php'));
        $this->assertStringContainsString('errorMessage', $setup);
        $this->assertStringContainsString('checkbox.checked = !isChecked', $setup);

        $device = file_get_contents(resource_path('views/admin/master_pages/add_device.blade.php'));
        $this->assertStringContainsString('id="refreshError"', $device);
    }
}
