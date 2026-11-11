<?php

namespace Tests\Feature;

use App\Models\ActivatedDevice;
use App\Models\DeviceSyncState;
use App\Models\SetupShalotrackDevice;
use App\Services\DeviceSync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Device sync queue: payload (incl. bound state), retry/backoff, observers, drift scan.
 * sqlite in-memory ONLY (an earlier suite run against the production database caused an incident).
 */
class DeviceSyncOutboxTest extends TestCase
{
    private const URL = 'http://api.test/api/internal/setup-devices-sync';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', \DB::getDriverName(), 'DeviceSyncOutboxTest must only run on sqlite.');

        config(['services.shalotrack_api.base_url' => 'http://api.test', 'services.shalotrack_api.sync_key' => 'k']);

        Schema::create('setup_shalotrack_devices', function (Blueprint $t) {
            $t->id('shdevice_id');
            $t->string('device_category')->nullable();
            $t->string('imei_number')->unique();
            $t->string('sim_number')->nullable();
            $t->string('iccid')->nullable();
            $t->unsignedBigInteger('registered_by')->nullable();
            $t->string('intake_source')->nullable();
            $t->string('status')->default('Not Activated');
            $t->string('cancel_reason')->nullable();
            $t->timestamp('canceled_date')->nullable();
            $t->unsignedBigInteger('dealer_id')->nullable();
            $t->unsignedBigInteger('device_type_id')->nullable();
            $t->timestamp('allocated_at')->nullable();
            $t->unsignedBigInteger('transfer_id')->nullable();
            $t->timestamps();
        });
        Schema::create('activated_devices', function (Blueprint $t) {
            $t->id('activated_device_id');
            $t->string('vehicle_id')->unique();
            $t->string('customer_id')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('imei_number')->nullable();
            $t->timestamps();
        });
        Schema::create('device_sync_state', function (Blueprint $t) {
            $t->unsignedBigInteger('shdevice_id')->primary();
            $t->string('imei_number', 32)->index();
            $t->boolean('pending')->default(true);
            $t->timestamp('pending_since', 6)->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->string('last_error', 300)->nullable();
            $t->string('last_synced_hash', 40)->nullable();
            $t->timestamp('last_synced_at')->nullable();
            $t->timestamps();
        });
    }

    private function device(string $imei = '111111111111111', string $status = 'Activated'): SetupShalotrackDevice
    {
        return SetupShalotrackDevice::create([
            'device_category' => 'GPS', 'imei_number' => $imei, 'status' => $status,
        ]);
    }

    private function bind(string $imei, string $vehicle = 'v1'): ActivatedDevice
    {
        return ActivatedDevice::create(['vehicle_id' => $vehicle, 'imei_number' => $imei, 'customer_name' => 'C']);
    }

    public function test_creating_a_device_queues_it(): void
    {
        $d = $this->device();

        $s = DeviceSyncState::find($d->shdevice_id);
        $this->assertTrue($s->pending);
        $this->assertSame(0, $s->attempts);
    }

    public function test_payload_says_unbound_then_bound_with_a_time(): void
    {
        $d = $this->device();
        $p = DeviceSync::payload($d);
        $this->assertFalse($p['isBound']);
        $this->assertNull($p['boundAt']);

        $this->bind($d->imei_number);
        $p = DeviceSync::payload($d->fresh());
        $this->assertTrue($p['isBound']);
        $this->assertNotNull($p['boundAt']);
    }

    public function test_successful_send_clears_pending_and_sends_bound_fields(): void
    {
        Http::fake([self::URL => Http::response('ok', 200)]);
        $d = $this->device();
        $this->bind($d->imei_number);

        $this->assertTrue(DeviceSync::send($d->fresh()));

        Http::assertSent(fn (Request $r) => $r->url() === self::URL
            && $r['isBound'] === true && $r['imeiNumber'] === $d->imei_number
            && $r->hasHeader('X-Admin-Sync-Key', 'k'));
        $s = DeviceSyncState::find($d->shdevice_id);
        $this->assertFalse($s->pending);
        $this->assertNotNull($s->last_synced_hash);
    }

    public function test_failure_keeps_it_pending_and_backs_off(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);
        $d = $this->device();

        $this->assertFalse(DeviceSync::send($d));
        $s = DeviceSyncState::find($d->shdevice_id);
        $this->assertTrue($s->pending);
        $this->assertSame(1, $s->attempts);
        $this->assertTrue($s->next_attempt_at->isFuture());

        // Not due yet, so the scheduler leaves it alone.
        $this->assertSame(0, DeviceSync::processDue()['sent'] + DeviceSync::processDue()['failed']);

        // Becomes due, and a good answer clears it.
        DeviceSyncState::query()->update(['next_attempt_at' => now()->subMinute()]);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([self::URL => Http::response('ok', 200)]);
        $this->assertSame(1, DeviceSync::processDue()['sent']);
        $this->assertFalse(DeviceSyncState::find($d->shdevice_id)->pending);
    }

    public function test_backoff_grows_and_flags_a_stuck_device(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);
        $d = $this->device();

        for ($i = 0; $i < DeviceSync::WARN_AFTER_ATTEMPTS; $i++) {
            DeviceSync::send($d);
        }
        $this->assertSame(1, DeviceSync::stuckCount());
        $wait = DeviceSyncState::find($d->shdevice_id)->next_attempt_at;
        $this->assertTrue($wait->between(now()->addMinutes(59), now()->addMinutes(61)));
    }

    public function test_binding_and_unbinding_queue_the_device(): void
    {
        Http::fake([self::URL => Http::response('ok', 200)]);
        $d = $this->device();
        DeviceSync::send($d);
        $this->assertFalse(DeviceSyncState::find($d->shdevice_id)->pending);

        $row = $this->bind($d->imei_number);
        $this->assertTrue(DeviceSyncState::find($d->shdevice_id)->pending);

        DeviceSync::send($d->fresh());
        $row->delete();
        $this->assertTrue(DeviceSyncState::find($d->shdevice_id)->pending);
    }

    public function test_a_change_that_arrives_during_the_request_stays_pending(): void
    {
        $d = $this->device();
        Http::fake([self::URL => function () use ($d) {
            // Another change lands while this request is in flight.
            DeviceSync::markPending($d->shdevice_id, $d->imei_number);

            return Http::response('ok', 200);
        }]);

        DeviceSync::send($d);

        $this->assertTrue(DeviceSyncState::find($d->shdevice_id)->pending);
    }

    public function test_drift_scan_queues_only_devices_that_differ(): void
    {
        Http::fake([self::URL => Http::response('ok', 200)]);
        $a = $this->device('111111111111111');
        $b = $this->device('222222222222222');
        DeviceSync::processDue();
        $this->assertSame(0, DeviceSync::scanDrift());

        // A raw query the observers cannot see (no events fire).
        \DB::table('setup_shalotrack_devices')->where('shdevice_id', $b->shdevice_id)->update(['status' => 'Temporarily Stopped']);
        \DB::table('activated_devices')->insert(['vehicle_id' => 'v9', 'imei_number' => $a->imei_number, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(2, DeviceSync::scanDrift());
        $this->assertSame(2, DeviceSyncState::where('pending', true)->count());
    }

    public function test_touching_unwatched_columns_does_not_queue(): void
    {
        Http::fake([self::URL => Http::response('ok', 200)]);
        $d = $this->device();
        DeviceSync::send($d);

        SetupShalotrackDevice::find($d->shdevice_id)->update(['registered_by' => 5]);

        $this->assertFalse(DeviceSyncState::find($d->shdevice_id)->pending);
    }

    public function test_command_runs_and_a_deleted_device_is_dropped(): void
    {
        Http::fake([self::URL => Http::response('ok', 200)]);
        $a = $this->device('111111111111111');
        $b = $this->device('222222222222222');
        \DB::table('setup_shalotrack_devices')->where('shdevice_id', $b->shdevice_id)->delete();

        $this->artisan('devices:sync-outbox')->assertSuccessful();

        $this->assertFalse(DeviceSyncState::find($a->shdevice_id)->pending);
        $this->assertNull(DeviceSyncState::find($b->shdevice_id));
    }

    public function test_it_is_a_no_op_before_the_migration_has_run(): void
    {
        Schema::drop('device_sync_state');
        DeviceSync::forgetTableCheck();
        Http::fake([self::URL => Http::response('ok', 200)]);

        $d = $this->device();                       // observer must not throw
        $this->assertTrue(DeviceSync::send($d));     // direct push still works
        $this->artisan('devices:sync-outbox')->assertSuccessful();
    }
}
