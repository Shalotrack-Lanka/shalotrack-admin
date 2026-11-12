<?php

namespace Tests\Pgsql;

use App\Models\ActivatedDevice;
use App\Models\DeviceSyncState;
use App\Models\SetupShalotrackDevice;
use App\Services\DeviceSync;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsReportData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * THE DEVICE SYNC QUEUE ("outbox"), on real PostgreSQL (autotest schema).
 *
 * The admin database is the master copy of every device. The mobile API keeps its own copy, so every change that
 * the API cares about is put in a queue (device_sync_state) and pushed by `devices:sync-outbox` every minute.
 *
 * What the tests prove (simple words):
 *   - a new device, or a change to what the API cares about (status, dealer, SIM, category, cancel info, device
 *     type), or binding / unbinding it to a vehicle, puts the device in the queue; a change to anything else does not,
 *   - the push sends the right fields and the secret key, and a good answer clears the queue entry,
 *   - a bad answer (HTTP error or no connection) keeps it queued and waits longer each time (1, 2, 5, 15, then 60
 *     minutes), shows as "stuck" after 5 failures, and a later good answer starts it fresh,
 *   - a change that arrives while the push is in flight is not lost,
 *   - the hourly drift scan queues only the devices that no longer match what the API last accepted,
 *   - a device deleted here is dropped from the queue, and nothing breaks if the queue table is missing.
 *
 * No real call is made: every request to the API is faked. Everything made here is rolled back after each test.
 */
class DeviceSyncOutboxPgTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsReportData;

    private const URL = 'http://api.test/api/internal/setup-devices-sync';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shalotrack_api.base_url' => 'http://api.test', 'services.shalotrack_api.sync_key' => 'autotest-key']);
        DeviceSync::forgetTableCheck();

        $this->assertTrue(
            Schema::hasTable('device_sync_state'),
            'The device_sync_state table is missing in the autotest schema. Run the migrations for the autotest schema first.'
        );
    }

    protected function tearDown(): void
    {
        DeviceSync::forgetTableCheck();   // never leave a "table is missing" answer behind for the next test class
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    private function device(array $overrides = []): SetupShalotrackDevice
    {
        return SetupShalotrackDevice::create(array_merge($this->simIdentity() + [
            'device_category' => 'AUTOTEST-GPS',
            'imei_number'     => $this->validImei(),
            'status'          => 'Activated',
        ], $overrides));
    }

    private function bind(string $imei, array $overrides = []): ActivatedDevice
    {
        return $this->makeBound(array_merge(['imei_number' => $imei], $overrides));
    }

    private function state(SetupShalotrackDevice $d): ?DeviceSyncState
    {
        return DeviceSyncState::find($d->shdevice_id);
    }

    /** Send it once, successfully, so the queue entry is clear. */
    private function synced(SetupShalotrackDevice $d): SetupShalotrackDevice
    {
        Http::fake([self::URL => Http::response('ok', 200)]);
        $this->assertTrue(DeviceSync::send($d));

        return $d->fresh();
    }

    private function fakeReset(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
    }

    // ================================================================== the queue is filled by changes

    public function test_creating_a_device_queues_it(): void
    {
        $d = $this->device();

        $s = $this->state($d);
        $this->assertNotNull($s);
        $this->assertTrue($s->pending);
        $this->assertSame(0, (int) $s->attempts);
        $this->assertSame($d->imei_number, $s->imei_number);
        $this->assertNotNull($s->pending_since);
    }

    public static function watchedChanges(): array
    {
        return [
            'status'          => [['status' => 'Temporarily Stopped']],
            'sim number'      => [['sim_number' => '0701112223']],
            'device category' => [['device_category' => 'AUTOTEST-OTHER']],
            'cancel reason'   => [['cancel_reason' => 'customer left']],
            'cancelled date'  => [['canceled_date' => '2026-11-01 10:00:00']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('watchedChanges')]
    public function test_a_change_the_api_cares_about_queues_the_device_again(array $change): void
    {
        $d = $this->synced($this->device());
        $this->assertFalse($this->state($d)->pending);

        $d->update($change);

        $this->assertTrue($this->state($d)->pending);
    }

    public function test_giving_the_device_to_a_dealer_queues_it_and_taking_it_back_queues_it_again(): void
    {
        $dealer = $this->makeDealer();
        $d = $this->synced($this->device());

        $d->update(['dealer_id' => $dealer->id]);
        $this->assertTrue($this->state($d)->pending, 'moved to a dealer');

        $d = $this->synced($d->fresh());
        $d->update(['dealer_id' => null]);
        $this->assertTrue($this->state($d)->pending, 'taken back by the company');
    }

    public function test_changing_the_device_type_queues_the_device(): void
    {
        $type = $this->makeDeviceType();
        $d = $this->synced($this->device());

        $d->update(['device_type_id' => $type->id]);

        $this->assertTrue($this->state($d)->pending);
    }

    public function test_changing_the_imei_queues_the_device_with_its_new_imei(): void
    {
        $d = $this->synced($this->device());
        $new = $this->validImei();

        $d->update(['imei_number' => $new]);

        $s = $this->state($d);
        $this->assertTrue($s->pending);
        $this->assertSame($new, $s->imei_number);
    }

    public function test_touching_columns_the_api_does_not_use_does_not_queue(): void
    {
        $d = $this->synced($this->device());

        $d->update(['registered_by' => 5, 'iccid' => '89949' . str_pad((string) $this->nextIntakeNumber(), 14, '0', STR_PAD_LEFT)]);

        $this->assertFalse($this->state($d)->pending);
    }

    public function test_saving_a_device_with_no_change_does_not_queue_it_again(): void
    {
        $d = $this->synced($this->device());

        $d->save();
        $d->fresh()->update(['status' => $d->status]);

        $this->assertFalse($this->state($d)->pending);
    }

    public function test_binding_and_unbinding_queue_the_device(): void
    {
        $d = $this->synced($this->device());

        $row = $this->bind($d->imei_number);
        $this->assertTrue($this->state($d)->pending, 'binding queues it');

        $this->synced($d);
        $row->delete();
        $this->assertTrue($this->state($d)->pending, 'unbinding queues it');
    }

    public function test_moving_a_binding_to_another_device_queues_both_devices(): void
    {
        $a = $this->device();
        $b = $this->device();
        $row = $this->bind($a->imei_number);
        $this->synced($a);
        $this->synced($b);

        $row->update(['imei_number' => $b->imei_number]);

        $this->assertTrue($this->state($a)->pending, 'the old device is no longer bound');
        $this->assertTrue($this->state($b)->pending, 'the new device is now bound');
    }

    public function test_binding_an_imei_that_is_not_a_known_device_is_harmless(): void
    {
        $before = DeviceSyncState::count();

        $this->bind($this->validImei());

        $this->assertSame($before, DeviceSyncState::count());
    }

    public function test_marking_by_an_empty_or_unknown_imei_does_nothing_and_never_fails(): void
    {
        $before = DeviceSyncState::count();

        DeviceSync::markPendingByImei(null);
        DeviceSync::markPendingByImei('');
        DeviceSync::markPendingByImei('000000000000000');

        $this->assertSame($before, DeviceSyncState::count());
    }

    public function test_a_change_restarts_the_retry_counter_and_makes_it_due_now(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);
        $d = $this->device();
        DeviceSync::send($d);
        DeviceSync::send($d);
        $this->assertSame(2, (int) $this->state($d)->attempts);

        $d->update(['status' => 'Temporarily Stopped']);

        $s = $this->state($d);
        $this->assertSame(0, (int) $s->attempts);
        $this->assertTrue($s->next_attempt_at->lte(now()->addSecond()));
    }

    // ================================================================== what is sent

    public function test_the_payload_says_unbound_then_bound_with_the_time(): void
    {
        $d = $this->device();
        $p = DeviceSync::payload($d);
        $this->assertFalse($p['isBound']);
        $this->assertNull($p['boundAt']);

        $this->bind($d->imei_number);
        $p = DeviceSync::payload($d->fresh());

        $this->assertTrue($p['isBound']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(\+00:00|Z)$/', $p['boundAt'], 'an ISO-8601 time in UTC');
    }

    public function test_the_payload_has_exactly_the_fields_the_api_expects(): void
    {
        $d = $this->device(['dealer_id' => null, 'cancel_reason' => 'x']);

        $p = DeviceSync::payload($d);

        $this->assertEqualsCanonicalizing([
            'id', 'deviceCategory', 'imeiNumber', 'simNumber', 'status', 'cancelReason', 'canceledDate', 'dealerId',
            'deviceTypeId', 'isBound', 'boundAt', 'createdAt', 'updatedAt',
        ], array_keys($p));
        $this->assertSame($d->shdevice_id, $p['id']);
        $this->assertSame($d->imei_number, $p['imeiNumber']);
        $this->assertSame($d->sim_number, $p['simNumber']);
        $this->assertSame('AUTOTEST-GPS', $p['deviceCategory']);
        $this->assertSame('Activated', $p['status']);
        $this->assertSame('x', $p['cancelReason']);
    }

    public function test_the_first_bind_time_is_the_one_sent_when_a_device_was_bound_more_than_once(): void
    {
        $d = $this->device();
        $first = $this->bind($d->imei_number);
        $second = $this->bind($d->imei_number);
        ActivatedDevice::whereKey($first->getKey())->update(['created_at' => '2026-01-01 08:00:00']);
        ActivatedDevice::whereKey($second->getKey())->update(['created_at' => '2026-06-01 08:00:00']);

        $this->assertStringStartsWith('2026-01-01T08:00:00', DeviceSync::payload($d->fresh())['boundAt']);
        $this->assertSame('2026-01-01 08:00:00', DeviceSync::boundMap()[$d->imei_number]);
    }

    public function test_the_fingerprint_ignores_timestamps_but_not_real_changes(): void
    {
        $d = $this->device();
        $p = DeviceSync::payload($d);

        $this->assertSame(DeviceSync::fingerprint($p), DeviceSync::fingerprint(['createdAt' => 'x', 'updatedAt' => 'y'] + $p));
        $this->assertNotSame(DeviceSync::fingerprint($p), DeviceSync::fingerprint(['status' => 'Cancelled'] + $p));
        $this->assertSame(40, strlen(DeviceSync::fingerprint($p)));
    }

    public function test_a_successful_send_posts_the_fields_with_the_secret_key_and_clears_the_queue(): void
    {
        Http::fake([self::URL => Http::response('ok', 200)]);
        $d = $this->device();
        $this->bind($d->imei_number);

        $this->assertTrue(DeviceSync::send($d->fresh()));

        Http::assertSent(fn (Request $r) => $r->url() === self::URL
            && $r->method() === 'POST'
            && $r['isBound'] === true
            && $r['imeiNumber'] === $d->imei_number
            && $r['id'] === $d->shdevice_id
            && $r['status'] === 'Activated'
            && $r->hasHeader('X-Admin-Sync-Key', 'autotest-key'));
        Http::assertSentCount(1);
        $s = $this->state($d);
        $this->assertFalse($s->pending);
        $this->assertSame(0, (int) $s->attempts);
        $this->assertNull($s->last_error);
        $this->assertNotNull($s->last_synced_at);
        $this->assertSame(DeviceSync::fingerprint(DeviceSync::payload($d->fresh())), $s->last_synced_hash);
    }

    // ================================================================== failures and backing off

    public function test_a_failed_send_keeps_it_queued_records_the_error_and_waits(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);
        $d = $this->device();

        $this->assertFalse(DeviceSync::send($d));

        $s = $this->state($d);
        $this->assertTrue($s->pending);
        $this->assertSame(1, (int) $s->attempts);
        $this->assertSame('HTTP 500', $s->last_error);
        $this->assertTrue($s->next_attempt_at->between(now()->addSeconds(30), now()->addSeconds(90)), 'first wait is 1 minute');
        $this->assertNull($s->last_synced_hash);
    }

    public function test_no_connection_is_also_a_failure_and_the_message_is_kept(): void
    {
        Http::fake([self::URL => fn () => throw new ConnectionException('Could not resolve host: api.test')]);
        $d = $this->device();

        $this->assertFalse(DeviceSync::send($d));

        $s = $this->state($d);
        $this->assertTrue($s->pending);
        $this->assertSame(1, (int) $s->attempts);
        $this->assertStringContainsString('Could not resolve host', $s->last_error);
    }

    public function test_the_wait_grows_1_2_5_15_then_60_minutes_and_stays_at_60(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);
        $d = $this->device();

        foreach ([1, 2, 5, 15, 60, 60] as $attempt => $minutes) {
            DeviceSync::send($d);
            $s = $this->state($d);
            $this->assertSame($attempt + 1, (int) $s->attempts);
            $this->assertTrue(
                $s->next_attempt_at->between(now()->addMinutes($minutes)->subSeconds(60), now()->addMinutes($minutes)->addSeconds(60)),
                'failure ' . ($attempt + 1) . ' should wait about ' . $minutes . ' minute(s)'
            );
        }
    }

    public function test_after_five_failures_the_device_counts_as_stuck_and_a_good_answer_clears_it(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);
        $d = $this->device();
        $stuckBefore = DeviceSync::stuckCount();

        for ($i = 0; $i < DeviceSync::WARN_AFTER_ATTEMPTS - 1; $i++) {
            DeviceSync::send($d);
        }
        $this->assertSame($stuckBefore, DeviceSync::stuckCount(), 'four failures are not stuck yet');

        DeviceSync::send($d);
        $this->assertSame($stuckBefore + 1, DeviceSync::stuckCount());

        $this->fakeReset();
        Http::fake([self::URL => Http::response('ok', 200)]);
        $this->assertTrue(DeviceSync::send($d));
        $this->assertSame($stuckBefore, DeviceSync::stuckCount());
        $this->assertSame(0, (int) $this->state($d)->attempts);
        $this->assertNull($this->state($d)->last_error);
    }

    public function test_a_change_that_arrives_while_the_request_is_in_flight_stays_queued(): void
    {
        $d = $this->device();
        $this->synced($d);
        $this->fakeReset();
        Http::fake([self::URL => function () use ($d) {
            DeviceSync::markPending($d->shdevice_id, $d->imei_number);   // another change lands during the request

            return Http::response('ok', 200);
        }]);

        DeviceSync::send($d);

        $this->assertTrue($this->state($d)->pending, 'the newer change still has to be sent');
    }

    // ================================================================== the scheduled run

    public function test_the_run_sends_what_is_due_and_leaves_what_is_waiting(): void
    {
        $due = $this->device();
        $waiting = $this->device();
        Http::fake([self::URL => Http::response('ok', 200)]);
        DeviceSyncState::whereKey($waiting->shdevice_id)->update(['next_attempt_at' => now()->addMinutes(30), 'attempts' => 2]);

        $r = DeviceSync::processDue();

        $this->assertGreaterThanOrEqual(1, $r['sent']);
        $this->assertFalse($this->state($due)->pending);
        $this->assertTrue($this->state($waiting)->pending, 'not due yet, so it is left alone');
        $this->assertSame(0, $r['remaining'], '"remaining" counts only what is due and was not reached');
        $this->assertSame(2, (int) $this->state($waiting)->attempts, 'its retry counter is untouched');
    }

    public function test_a_failed_device_is_retried_once_it_is_due_and_then_clears(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);
        $d = $this->device();
        DeviceSync::send($d);
        $this->assertSame(0, DeviceSync::processDue()['failed'], 'still waiting its minute');

        DeviceSyncState::whereKey($d->shdevice_id)->update(['next_attempt_at' => now()->subMinute()]);
        $this->fakeReset();
        Http::fake([self::URL => Http::response('ok', 200)]);

        $this->assertGreaterThanOrEqual(1, DeviceSync::processDue()['sent']);
        $this->assertFalse($this->state($d)->pending);
    }

    public function test_the_run_counts_a_failure_and_a_success_separately(): void
    {
        $good = $this->device();
        $bad = $this->device();
        Http::fake([self::URL => fn (Request $r) => $r['imeiNumber'] === $bad->imei_number ? Http::response('boom', 500) : Http::response('ok', 200)]);

        $r = DeviceSync::processDue();

        $this->assertGreaterThanOrEqual(1, $r['sent']);
        $this->assertGreaterThanOrEqual(1, $r['failed']);
        $this->assertFalse($this->state($good)->pending);
        $this->assertTrue($this->state($bad)->pending);
        $this->assertSame(1, (int) $this->state($bad)->attempts);
    }

    public function test_the_oldest_change_is_sent_first(): void
    {
        $first = $this->device();
        $second = $this->device();
        DeviceSyncState::whereKey($first->shdevice_id)->update(['pending_since' => '2026-11-01 10:00:00.000100']);
        DeviceSyncState::whereKey($second->shdevice_id)->update(['pending_since' => '2026-11-01 10:00:00.000200']);
        $order = [];
        Http::fake([self::URL => function (Request $r) use (&$order, $first, $second) {
            if (in_array($r['imeiNumber'], [$first->imei_number, $second->imei_number], true)) {
                $order[] = $r['imeiNumber'];
            }

            return Http::response('ok', 200);
        }]);

        DeviceSync::processDue();

        $this->assertSame([$first->imei_number, $second->imei_number], $order);
    }

    public function test_a_run_with_no_time_left_sends_nothing_and_reports_what_remains(): void
    {
        $this->device();
        Http::fake([self::URL => Http::response('ok', 200)]);

        $r = DeviceSync::processDue(-1);

        $this->assertSame(0, $r['sent'] + $r['failed']);
        $this->assertGreaterThanOrEqual(1, $r['remaining']);
        Http::assertNothingSent();
    }

    public function test_a_device_deleted_here_is_dropped_from_the_queue_not_sent(): void
    {
        $gone = $this->device();
        $kept = $this->device();
        DB::table('setup_shalotrack_devices')->where('shdevice_id', $gone->shdevice_id)->delete();
        Http::fake([self::URL => Http::response('ok', 200)]);

        DeviceSync::processDue();

        $this->assertNull($this->state($gone));
        $this->assertFalse($this->state($kept)->pending);
        Http::assertNotSent(fn (Request $r) => $r['imeiNumber'] === $gone->imei_number);
    }

    // ================================================================== the drift scan

    public function test_the_drift_scan_queues_only_devices_that_no_longer_match_what_the_api_accepted(): void
    {
        $a = $this->synced($this->device());
        $b = $this->synced($this->device());
        $c = $this->synced($this->device());
        DeviceSync::scanDrift();   // clears anything else in the autotest schema from the comparison
        DeviceSyncState::query()->update(['pending' => false]);

        // Changes the observers cannot see (raw queries fire no events).
        DB::table('setup_shalotrack_devices')->where('shdevice_id', $b->shdevice_id)->update(['status' => 'Temporarily Stopped']);
        DB::table('activated_devices')->insert(['vehicle_id' => (string) \Illuminate\Support\Str::uuid(), 'imei_number' => $a->imei_number, 'created_at' => now(), 'updated_at' => now()]);

        DeviceSync::scanDrift();

        $this->assertTrue($this->state($a)->pending, 'bound by a raw insert');
        $this->assertTrue($this->state($b)->pending, 'status changed by a raw update');
        $this->assertFalse($this->state($c)->pending, 'unchanged');
    }

    public function test_the_drift_scan_returns_how_many_it_queued_and_zero_when_all_match(): void
    {
        $this->synced($this->device());
        $this->synced($this->device());
        DeviceSync::scanDrift();
        DeviceSyncState::query()->update(['pending' => false]);

        $this->assertSame(0, DeviceSync::scanDrift());

        DB::table('setup_shalotrack_devices')->orderByDesc('shdevice_id')->limit(1)->update(['status' => 'Cancelled']);
        $this->assertSame(1, DeviceSync::scanDrift());
    }

    public function test_the_drift_scan_queues_a_device_that_was_never_synced(): void
    {
        $d = $this->device();
        DeviceSyncState::whereKey($d->shdevice_id)->delete();   // lost from the queue by some accident

        $this->assertGreaterThanOrEqual(1, DeviceSync::scanDrift());

        $this->assertTrue($this->state($d)->pending);
    }

    public function test_the_drift_scan_does_not_requeue_a_device_that_only_had_its_timestamp_touched(): void
    {
        $d = $this->synced($this->device());
        DeviceSync::scanDrift();
        DeviceSyncState::query()->update(['pending' => false]);

        DB::table('setup_shalotrack_devices')->where('shdevice_id', $d->shdevice_id)->update(['updated_at' => now()->addDay()]);

        DeviceSync::scanDrift();
        $this->assertFalse($this->state($d)->pending);
    }

    // ================================================================== the command

    public function test_the_command_sends_the_queue_and_says_how_many(): void
    {
        $d = $this->device();
        Http::fake([self::URL => Http::response('ok', 200)]);

        $this->artisan('devices:sync-outbox')
            ->expectsOutputToContain(', failed 0, still waiting 0.')
            ->assertSuccessful();

        $this->assertFalse($this->state($d)->pending);
    }

    public function test_the_command_with_scan_runs_the_drift_scan_first(): void
    {
        $d = $this->synced($this->device());
        DeviceSync::scanDrift();
        DeviceSyncState::query()->update(['pending' => false]);
        DB::table('setup_shalotrack_devices')->where('shdevice_id', $d->shdevice_id)->update(['status' => 'Cancelled']);
        $this->fakeReset();
        Http::fake([self::URL => Http::response('ok', 200)]);

        $this->artisan('devices:sync-outbox --scan')
            ->expectsOutputToContain('Queued by drift scan: ')
            ->expectsOutputToContain('Sent ')
            ->assertSuccessful();

        $this->assertFalse($this->state($d)->pending, 'found by the scan, then sent in the same run');
        Http::assertSent(fn (Request $r) => $r['imeiNumber'] === $d->imei_number && $r['status'] === 'Cancelled');
    }

    public function test_the_command_reports_failures_and_still_succeeds(): void
    {
        $d = $this->device();
        Http::fake([self::URL => Http::response('boom', 500)]);

        $this->artisan('devices:sync-outbox')->expectsOutputToContain('failed')->assertSuccessful();

        $this->assertSame(1, (int) $this->state($d)->attempts);
    }

    // ================================================================== before the migration has run

    public function test_nothing_breaks_and_nothing_is_queued_when_the_queue_table_is_missing(): void
    {
        Schema::drop('device_sync_state');   // PostgreSQL rolls this back with the test
        DeviceSync::forgetTableCheck();
        Http::fake([self::URL => Http::response('ok', 200)]);

        $d = $this->device();   // the observer must not throw

        $this->assertFalse(DeviceSync::enabled());
        $this->assertTrue(DeviceSync::send($d), 'a direct push still works');
        $this->assertSame(['sent' => 0, 'failed' => 0, 'remaining' => 0], DeviceSync::processDue());
        $this->assertSame(0, DeviceSync::scanDrift());
        $this->assertSame(0, DeviceSync::stuckCount());
        DeviceSync::markPending($d->shdevice_id, $d->imei_number);   // no error
        $this->artisan('devices:sync-outbox')->expectsOutputToContain('device_sync_state table is missing')->assertSuccessful();
    }

    public function test_a_failed_direct_push_with_no_queue_table_returns_false_and_does_not_throw(): void
    {
        Schema::drop('device_sync_state');
        DeviceSync::forgetTableCheck();
        Http::fake([self::URL => Http::response('boom', 500)]);

        $this->assertFalse(DeviceSync::send($this->device()));
    }
}