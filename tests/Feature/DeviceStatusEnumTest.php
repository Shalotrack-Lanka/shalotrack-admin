<?php

namespace Tests\Feature;

use App\Enums\DeviceStatus;
use App\Models\Admin;
use App\Models\SetupShalotrackDevice;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DeviceStatus is the single source of truth for setup_shalotrack_devices.status.
 * These tests pin (a) the exact stored strings, (b) the admin transition rules,
 * (c) that the docs match real behaviour, and (d) that nobody reintroduces raw
 * status strings in app code.
 */
class DeviceStatusEnumTest extends TestCase
{
    public function test_stored_strings_are_exactly_the_six_database_values(): void
    {
        $this->assertSame(
            ['Not Activated', 'Activated', 'Temporarily Stopped', 'Assigned to Customer', 'Pending Repair', 'Broken Device'],
            array_map(fn ($c) => $c->value, DeviceStatus::cases())
        );
    }

    public function test_admin_transitions_are_exactly_the_old_cancel_device_map(): void
    {
        $map = [];
        foreach (DeviceStatus::cases() as $c) {
            $map[$c->value] = array_map(fn ($n) => $n->value, $c->adminNext());
        }

        $this->assertSame(['Activated'], $map['Not Activated']);
        $this->assertSame(['Temporarily Stopped'], $map['Activated']);
        $this->assertSame(['Activated'], $map['Temporarily Stopped']);
        $this->assertSame([], $map['Assigned to Customer']);
        $this->assertSame([], $map['Pending Repair']);
        $this->assertSame([], $map['Broken Device']);
    }

    public function test_descriptions_state_the_real_behaviour(): void
    {
        foreach (DeviceStatus::cases() as $c) {
            $this->assertNotSame('', $c->description());
        }
        // Not Activated includes dealer stock (not only company stock).
        $this->assertStringContainsString('dealer', DeviceStatus::NotActivated->description());
        // Temporarily Stopped is admin-only; expiry never sets it.
        $this->assertStringContainsString('admin', DeviceStatus::TemporarilyStopped->description());
        $this->assertStringContainsString('never set automatically', DeviceStatus::TemporarilyStopped->description());
        // Expiry leaves Activated untouched.
        $this->assertStringContainsString('does NOT change', DeviceStatus::Activated->description());
    }

    public function test_no_raw_device_status_strings_remain_in_app_code(): void
    {
        $names = 'Not Activated|Activated|Temporarily Stopped|Assigned to Customer|Pending Repair|Broken Device';
        // 'status' => 'X' | 'status', 'X' | ->status = / !== / === 'X' | ['status'] === 'X'
        // (sim_status is a different column and is deliberately not matched.)
        $re = "/(?:'status'\s*(?:=>|,)\s*'(?:$names)'|->status\s*(?:=|!==|===|!=)\s*'(?:$names)'|\['status'\]\s*(?:===|!==)\s*'(?:$names)')/";

        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (preg_match($re, file_get_contents($file->getPathname()), $m)) {
                $offenders[] = str_replace(base_path() . '/', '', $file->getPathname()) . ' => ' . $m[0];
            }
        }

        $this->assertSame([], $offenders, "Use DeviceStatus::X->value instead of raw strings:\n" . implode("\n", $offenders));
    }

    private function bootCancelDevicePage(): void
    {
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
            $t->string('cancel_reason')->nullable();
            $t->timestamp('canceled_date')->nullable();
            $t->unsignedBigInteger('dealer_id')->nullable();
            $t->unsignedBigInteger('device_type_id')->nullable();
            $t->timestamps();
        });

        $this->actingAs(Admin::forceCreate(['admin_id' => 'adm-1', 'username' => 'a', 'role' => 'ADMIN', 'status' => 'ACTIVE']));
    }

    private function device(string $status): SetupShalotrackDevice
    {
        return SetupShalotrackDevice::create([
            'device_category' => 'V5 Basic', 'imei_number' => (string) random_int(100000000000000, 999999999999999),
            'status' => $status,
        ]);
    }

    public function test_cancel_device_allows_stop_with_reason_and_rejects_illegal_moves(): void
    {
        $this->bootCancelDevicePage();

        // Activated -> Temporarily Stopped, reason required.
        $d = $this->device('Activated');
        $this->patch(route('admin.cancel_device.update', $d), ['status' => 'Temporarily Stopped'])
            ->assertSessionHasErrors('cancel_reason');
        $this->assertSame('Activated', $d->fresh()->status);

        $this->patch(route('admin.cancel_device.update', $d), ['status' => 'Temporarily Stopped', 'cancel_reason' => 'Customer request'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Temporarily Stopped', $d->fresh()->status);
        $this->assertSame('Customer request', $d->fresh()->cancel_reason);

        // Not Activated -> Temporarily Stopped is not allowed directly.
        $n = $this->device('Not Activated');
        $this->patch(route('admin.cancel_device.update', $n), ['status' => 'Temporarily Stopped', 'cancel_reason' => 'x'])
            ->assertSessionHasErrors('status');
        $this->assertSame('Not Activated', $n->fresh()->status);

        // A dealer-side status can't be changed from this admin page at all.
        $b = $this->device('Broken Device');
        $this->patch(route('admin.cancel_device.update', $b), ['status' => 'Activated'])
            ->assertSessionHasErrors('status');
        $this->assertSame('Broken Device', $b->fresh()->status);

        // A status value outside the allowed set is rejected by validation.
        $this->patch(route('admin.cancel_device.update', $d), ['status' => 'Assigned to Customer'])
            ->assertSessionHasErrors('status');

        // A stored status the enum doesn't know (bad data) allows no move, no crash.
        $x = $this->device('Mystery');
        $this->patch(route('admin.cancel_device.update', $x), ['status' => 'Activated'])
            ->assertSessionHasErrors('status');
        $this->assertSame('Mystery', $x->fresh()->status);
    }
}
