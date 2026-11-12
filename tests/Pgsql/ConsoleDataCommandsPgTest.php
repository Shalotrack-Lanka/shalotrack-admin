<?php

namespace Tests\Pgsql;

use App\Models\ActivatedDevice;
use App\Models\CustomerAd;
use App\Models\DashboardMetricSnapshot;
use App\Models\ExpiredDevice;
use App\Models\SetupShalotrackDevice;
use App\Models\Stock;
use App\Models\VehicleAd;
use App\Services\Billing\Billing;
use App\Services\DeviceSync;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsReportData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * THE SCHEDULED / MAINTENANCE COMMANDS THAT WORK ON THIS PORTAL'S OWN DATA, run for real on PostgreSQL
 * (autotest schema). Each command is run the way the scheduler runs it and we check three things: what it prints,
 * its exit code (the scheduler and any monitor read this), and what it did to the database.
 *
 *   billing:verify               the tamper check of invoices, credit notes and receipts
 *   devices:backfill             push every device to the app server
 *   devices:expire-subscriptions the every-minute sweep that marks lapsed subscriptions as "needs renewal"
 *   audit:prune                  delete audit entries older than the retention period (never fewer than 3 months)
 *   devices:push-replacement     retry a failed device replacement
 *   customers:scrub-deleted      remove names and plates of deleted customers from the records that must be kept
 *   dashboard:snapshot           save today's numbers for the dashboard trend arrows
 *
 * (The commands that PULL data from the app server are in ConsoleApiSyncCommandsPgTest.)
 * Everything made here is rolled back after each test. The outside world is never called: every web call is faked.
 */
class ConsoleDataCommandsPgTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsReportData;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.shalotrack_api.sync_key' => 'autotest-key',
            'services.shalotrack_api.base_url' => 'https://api.example.test',
            'services.gateway.command_url'     => 'https://gateway.example.test',
        ]);
        DeviceSync::forgetTableCheck();
        Sleep::fake();   // the commands retry with pauses; never really wait in a test
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DeviceSync::forgetTableCheck();
        parent::tearDown();
    }

    /** @return array{0:int,1:string} exit code and everything printed */
    private function run_(string $command, array $args = []): array
    {
        $code = Artisan::call($command, $args);

        return [$code, Artisan::output()];
    }

    private function device(array $overrides = []): SetupShalotrackDevice
    {
        $n = $this->nextIntakeNumber();

        return SetupShalotrackDevice::create(array_merge([
            'device_category' => 'AUTOTEST-CAT', 'imei_number' => $this->validImei(),
            'sim_number' => '07' . str_pad((string) $n, 9, '0', STR_PAD_LEFT), 'status' => 'Activated',
        ], $overrides));
    }

    // ================================================================== billing:verify

    public function test_billing_verify_says_intact_and_exits_0_when_there_are_no_problems(): void
    {
        $this->mock(Billing::class)->shouldReceive('verify')->once()->andReturn([]);

        [$code, $out] = $this->run_('billing:verify');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Billing records are intact.', $out);
    }

    public function test_billing_verify_lists_every_problem_and_exits_1(): void
    {
        $this->mock(Billing::class)->shouldReceive('verify')->once()->andReturn([
            'INV-2026-000003: content does not match its stored hash (altered)',
            'RCT-2026-000001: numbering gap or reuse (expected 1)',
        ]);

        [$code, $out] = $this->run_('billing:verify');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('2 problem(s) found:', $out);
        $this->assertStringContainsString(' - INV-2026-000003: content does not match its stored hash (altered)', $out);
        $this->assertStringContainsString(' - RCT-2026-000001: numbering gap or reuse (expected 1)', $out);
        $this->assertStringNotContainsString('intact', $out);
    }

    public function test_billing_verify_on_the_real_service_agrees_with_the_service_itself(): void
    {
        $problems = app(Billing::class)->verify();

        [$code, $out] = $this->run_('billing:verify');

        $this->assertSame($problems === [] ? 0 : 1, $code);
        $this->assertStringContainsString($problems === [] ? 'intact' : count($problems) . ' problem(s) found:', $out);
    }

    // ================================================================== devices:backfill

    public function test_backfill_pushes_every_device_and_reports_the_counts(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $before = SetupShalotrackDevice::count();
        $a = $this->device();
        $b = $this->device();
        $c = $this->device();
        $total = $before + 3;

        [$code, $out] = $this->run_('devices:backfill');

        $this->assertSame(0, $code);
        $this->assertStringContainsString("Pushing {$total} device(s) to the API...", $out);
        $this->assertStringContainsString("Done. Succeeded: {$total}, Failed: 0.", $out);
        $this->assertStringNotContainsString('Check storage/logs', $out);

        $sent = collect(Http::recorded())->map(fn ($p) => $p[0]);
        $this->assertCount($total, $sent);
        $this->assertTrue($sent->every(fn ($r) => str_ends_with($r->url(), '/api/internal/setup-devices-sync') && $r->header('X-Admin-Sync-Key') === ['autotest-key']));
        $imeis = $sent->map(fn ($r) => $r->data()['imei'] ?? $r->data()['imeiNumber'] ?? null)->filter()->all();
        foreach ([$a, $b, $c] as $d) {
            $this->assertContains($d->imei_number, $imeis);
        }
    }

    public function test_backfill_goes_through_more_than_one_chunk_of_50(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $before = SetupShalotrackDevice::count();
        foreach (range(1, 55) as $i) {
            $this->device();
        }

        [, $out] = $this->run_('devices:backfill');

        $total = $before + 55;
        $this->assertStringContainsString("Done. Succeeded: {$total}, Failed: 0.", $out);
        $this->assertCount($total, Http::recorded());
    }

    public function test_backfill_counts_failures_warns_and_still_exits_0(): void
    {
        $before = SetupShalotrackDevice::count();
        $bad = $this->device();
        $good = $this->device();
        Http::fake(fn ($request) => ($request->data()['imei'] ?? $request->data()['imeiNumber'] ?? null) === $bad->imei_number
            ? Http::response('boom', 500) : Http::response([], 200));

        [$code, $out] = $this->run_('devices:backfill');

        $this->assertSame(0, $code);
        $ok = $before + 1;
        $this->assertStringContainsString("Done. Succeeded: {$ok}, Failed: 1.", $out);
        $this->assertStringContainsString('Check storage/logs/laravel.log for details on the failed pushes.', $out);
        unset($good);
    }

    public function test_backfill_survives_an_unreachable_app_server(): void
    {
        Http::fake(fn () => throw new ConnectionException('cannot connect'));
        $before = SetupShalotrackDevice::count();
        $this->device();

        [$code, $out] = $this->run_('devices:backfill');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Succeeded: 0, Failed: ' . ($before + 1) . '.', $out);
    }

    public function test_backfill_with_no_devices_says_so_and_calls_nothing(): void
    {
        Http::fake();
        // Remove every device inside this test's transaction (rolled back afterwards).
        DB::table('device_sync_state')->delete();
        DB::table('dealer_transfer_items')->delete();
        SetupShalotrackDevice::query()->delete();

        [$code, $out] = $this->run_('devices:backfill');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('No devices found — nothing to backfill.', $out);
        Http::assertNothingSent();
    }

    // ================================================================== devices:expire-subscriptions

    private function paid(array $overrides = []): ActivatedDevice
    {
        return $this->makeBound(array_merge([
            'payment_status' => 'Paid', 'subscription_model' => 'monthly',
            'subscription_start_date' => now()->subMonth(), 'subscription_end_date' => now()->subMinute(),
            'bank_invoice' => 'INV-AUTOTEST-' . $this->nextIntakeNumber(), 'bank_slip' => 'slips/autotest.pdf',
        ], $overrides));
    }

    public function test_a_lapsed_paid_subscription_is_marked_needs_renewal_and_the_device_is_kept(): void
    {
        $end = now()->subDays(2)->startOfSecond();
        $device = $this->paid(['subscription_end_date' => $end]);

        [$code, $out] = $this->run_('devices:expire-subscriptions');

        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/Marked \d+ device\(s\) as requiring subscription renewal\./', $out);

        $row = $device->fresh();
        $this->assertNotNull($row, 'the live device row is never deleted');
        $this->assertSame('not-Paid', $row->payment_status);
        $this->assertSame('Activated', $row->status, 'the device is still installed and activated');
        foreach (['subscription_model', 'subscription_start_date', 'subscription_end_date', 'bank_invoice', 'bank_slip'] as $field) {
            $this->assertNull($row->$field, $field);
        }
        $this->assertSame($device->customer_name, $row->customer_name);
        $this->assertSame($device->imei_number, $row->imei_number);
    }

    public function test_the_lapse_is_logged_once_for_reporting_with_the_old_end_date(): void
    {
        $end = now()->subDays(3)->startOfSecond();
        $device = $this->paid(['subscription_end_date' => $end]);

        $this->run_('devices:expire-subscriptions');

        $log = ExpiredDevice::where('vehicle_id', $device->vehicle_id)->get();
        $this->assertCount(1, $log);
        $l = $log->first();
        $this->assertSame('Expired', $l->status);
        $this->assertSame('not-Paid', $l->payment_status);
        $this->assertSame($device->imei_number, $l->imei_number);
        $this->assertSame($device->customer_id, $l->customer_id);
        $this->assertSame($device->customer_name, $l->customer_name);
        $this->assertSame($device->vehicle_number, $l->vehicle_number);
        $this->assertTrue($end->equalTo($l->expired_date), 'expired_date is the day the subscription really ended');
        $this->assertNull($l->subscription_end_date);
        $this->assertNull($l->bank_slip);
    }

    public function test_only_paid_subscriptions_that_have_ended_are_touched(): void
    {
        $future  = $this->paid(['subscription_end_date' => now()->addDays(5)]);
        $noEnd   = $this->paid(['subscription_end_date' => null]);
        $unpaid  = $this->paid(['payment_status' => 'not-Paid', 'subscription_end_date' => now()->subDay()]);
        $lapsed  = $this->paid();

        $this->run_('devices:expire-subscriptions');

        $this->assertSame('Paid', $future->fresh()->payment_status);
        $this->assertNotNull($future->fresh()->subscription_end_date);
        $this->assertSame('Paid', $noEnd->fresh()->payment_status);
        $this->assertSame('not-Paid', $unpaid->fresh()->payment_status);
        $this->assertNotNull($unpaid->fresh()->subscription_end_date, 'an unpaid row is not rewritten');
        $this->assertSame('not-Paid', $lapsed->fresh()->payment_status);
        foreach ([$future, $noEnd, $unpaid] as $untouched) {
            $this->assertFalse(ExpiredDevice::where('vehicle_id', $untouched->vehicle_id)->exists());
        }
    }

    public function test_the_sweep_is_safe_to_run_again(): void
    {
        $device = $this->paid();

        $this->run_('devices:expire-subscriptions');
        $logs = ExpiredDevice::count();
        [$code, $out] = $this->run_('devices:expire-subscriptions');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('No lapsed subscriptions.', $out);
        $this->assertSame($logs, ExpiredDevice::count(), 'no second log row');
        $this->assertSame(1, ExpiredDevice::where('vehicle_id', $device->vehicle_id)->count());
    }

    public function test_a_subscription_ending_exactly_now_is_treated_as_lapsed(): void
    {
        Carbon::setTestNow('2026-11-12 10:00:00');
        $device = $this->paid(['subscription_end_date' => '2026-11-12 10:00:00']);

        $this->run_('devices:expire-subscriptions');

        $this->assertSame('not-Paid', $device->fresh()->payment_status);
    }

    public function test_a_customer_who_renews_and_lapses_again_is_swept_again_without_stopping_the_run(): void
    {
        // First lapse, then a renewal by staff on the SAME row, then it lapses again.
        $device = $this->paid();
        $other  = $this->paid();
        $this->run_('devices:expire-subscriptions');
        $this->assertSame('not-Paid', $device->fresh()->payment_status);

        $device->fresh()->update(['payment_status' => 'Paid', 'subscription_model' => 'monthly',
            'subscription_start_date' => now()->subMonth(), 'subscription_end_date' => now()->subMinute()]);
        $later = $this->paid();   // another lapsed device that must not be blocked by the repeat

        [$code, $out] = $this->run_('devices:expire-subscriptions');

        $this->assertSame(0, $code, $out);
        $this->assertSame('not-Paid', $device->fresh()->payment_status, 'the repeat lapse is recorded');
        $this->assertSame('not-Paid', $later->fresh()->payment_status, 'a repeat must not block the devices after it');
        $this->assertSame(1, ExpiredDevice::where('vehicle_id', $device->vehicle_id)->count(), 'one reporting row per vehicle');
        $this->assertSame('not-Paid', $other->fresh()->payment_status);
    }

    // ================================================================== audit:prune

    private function auditRow(Carbon $when, string $action = 'autotest.row'): int
    {
        return DB::table('audit_logs')->insertGetId([
            'occurred_at' => $when, 'category' => 'device', 'action' => $action, 'subject_label' => 'autotest',
        ]);
    }

    public static function tooShort(): array
    {
        return ['zero' => ['0'], 'one' => ['1'], 'two' => ['2'], 'negative' => ['-5'], 'not a number' => ['abc']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tooShort')]
    public function test_a_retention_under_3_months_is_refused_and_nothing_is_deleted(string $months): void
    {
        $old = $this->auditRow(now()->subYears(3));
        $count = DB::table('audit_logs')->count();

        [$code, $out] = $this->run_('audit:prune', ['--months' => $months]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Refusing to keep fewer than 3 months of audit log.', $out);
        $this->assertSame($count, DB::table('audit_logs')->count());
        $this->assertTrue(DB::table('audit_logs')->where('id', $old)->exists());
    }

    public function test_by_default_entries_older_than_12_months_are_deleted_and_newer_ones_kept(): void
    {
        Carbon::setTestNow('2026-11-12 10:00:00');
        $old   = $this->auditRow(Carbon::parse('2025-11-11 09:00:00'));   // just over 12 months
        $older = $this->auditRow(Carbon::parse('2024-01-01'));
        $edge  = $this->auditRow(Carbon::parse('2025-11-13'));            // just under 12 months
        $new   = $this->auditRow(now()->subDay());

        [$code, $out] = $this->run_('audit:prune');

        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/Pruned \d+ audit entries older than 2025-11-12\./', $out);
        $left = DB::table('audit_logs')->whereIn('id', [$old, $older, $edge, $new])->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$edge, $new], $left);
    }

    public function test_a_custom_retention_is_used(): void
    {
        Carbon::setTestNow('2026-11-12 10:00:00');
        $fourMonths = $this->auditRow(Carbon::parse('2026-07-01'));
        $twoMonths  = $this->auditRow(Carbon::parse('2026-09-12'));

        [$code, $out] = $this->run_('audit:prune', ['--months' => '3']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('older than 2026-08-12.', $out);
        $this->assertFalse(DB::table('audit_logs')->where('id', $fourMonths)->exists());
        $this->assertTrue(DB::table('audit_logs')->where('id', $twoMonths)->exists());
    }

    public function test_exactly_3_months_is_allowed(): void
    {
        [$code] = $this->run_('audit:prune', ['--months' => '3']);

        $this->assertSame(0, $code);
    }

    public function test_the_month_end_does_not_overflow(): void
    {
        Carbon::setTestNow('2026-05-31 10:00:00');
        $row = $this->auditRow(Carbon::parse('2026-02-27'));   // older than 3 months back (28 Feb), must go
        $keep = $this->auditRow(Carbon::parse('2026-03-01'));  // newer than 28 Feb, must stay

        [, $out] = $this->run_('audit:prune', ['--months' => '3']);

        $this->assertStringContainsString('older than 2026-02-28.', $out);
        $this->assertFalse(DB::table('audit_logs')->where('id', $row)->exists());
        $this->assertTrue(DB::table('audit_logs')->where('id', $keep)->exists());
    }

    // ================================================================== devices:push-replacement

    public function test_the_replacement_is_pushed_new_device_first_then_the_replacement(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $new = $this->device();

        [$code, $out] = $this->run_('devices:push-replacement', ['oldImei' => '111111111111111', 'newImei' => $new->imei_number]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("Replacement 111111111111111 -> {$new->imei_number} sent to the API.", $out);
        $calls = collect(Http::recorded())->map(fn ($p) => $p[0]);
        $this->assertCount(2, $calls);
        $this->assertStringEndsWith('/api/internal/setup-devices-sync', $calls[0]->url());
        $this->assertStringEndsWith('/api/internal/device-replacement-sync', $calls[1]->url());
        $this->assertSame(['oldImei' => '111111111111111', 'newImei' => $new->imei_number], $calls[1]->data());
        $this->assertSame(['autotest-key'], $calls[1]->header('X-Admin-Sync-Key'));
    }

    public function test_spaces_around_the_imeis_are_ignored(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $new = $this->device();

        [$code] = $this->run_('devices:push-replacement', ['oldImei' => '  222222222222222 ', 'newImei' => " {$new->imei_number}  "]);

        $this->assertSame(0, $code);
        $this->assertSame('222222222222222', collect(Http::recorded())->last()[0]->data()['oldImei']);
    }

    public function test_an_unknown_new_device_is_refused_and_nothing_is_sent(): void
    {
        Http::fake();

        [$code, $out] = $this->run_('devices:push-replacement', ['oldImei' => '333333333333333', 'newImei' => '444444444444444']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('New device 444444444444444 is not in the admin device registry.', $out);
        Http::assertNothingSent();
    }

    public function test_if_the_device_push_fails_the_replacement_is_not_sent(): void
    {
        $new = $this->device();
        Http::fake(['*' => Http::response('boom', 500)]);

        [$code, $out] = $this->run_('devices:push-replacement', ['oldImei' => '555555555555555', 'newImei' => $new->imei_number]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString("Could not push device {$new->imei_number} to the API.", $out);
        $this->assertTrue(collect(Http::recorded())->every(fn ($p) => str_ends_with($p[0]->url(), '/setup-devices-sync')), 'the replacement call must not be made');
    }

    public function test_if_the_api_refuses_the_replacement_it_is_retried_then_reported(): void
    {
        $new = $this->device();
        Http::fake(fn ($request) => str_ends_with($request->url(), '/device-replacement-sync') ? Http::response(['error' => 'conflict'], 409) : Http::response([], 200));

        [$code, $out] = $this->run_('devices:push-replacement', ['oldImei' => '666666666666666', 'newImei' => $new->imei_number]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString("The API refused or could not process the replacement 666666666666666 -> {$new->imei_number}.", $out);
        $replacementCalls = collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/device-replacement-sync'));
        $this->assertCount(3, $replacementCalls, 'tried 3 times');
    }

    public function test_an_unreachable_api_during_the_replacement_is_reported_not_thrown(): void
    {
        $new = $this->device();
        Http::fake(fn ($request) => str_ends_with($request->url(), '/device-replacement-sync') ? throw new ConnectionException('cannot connect') : Http::response([], 200));

        [$code, $out] = $this->run_('devices:push-replacement', ['oldImei' => '777777777777777', 'newImei' => $new->imei_number]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('The API refused or could not process the replacement', $out);
    }

    // ================================================================== customers:scrub-deleted

    private const ANON = 'Deleted customer';

    /** A deleted customer (as the app server leaves it) with one vehicle and one retained record in each table. */
    private function deletedCustomer(bool $plateAnonymised = true): array
    {
        $cid = (string) Str::uuid();
        $vid = (string) Str::uuid();
        $plate = 'DELETED-' . strtoupper(substr(str_replace('-', '', $vid), 0, 8));
        $n = $this->nextIntakeNumber();

        CustomerAd::create(['customer_id' => $cid, 'full_name' => self::ANON, 'email' => "deleted-{$n}@deleted.invalid"]);
        VehicleAd::create(['vehicle_id' => $vid, 'customer_id' => $cid, 'vehicle_number' => $plateAnonymised ? $plate : "AUTO-{$n}"]);

        $imei = $this->validImei();
        $active = ActivatedDevice::create(['vehicle_id' => $vid, 'customer_id' => $cid, 'customer_name' => 'Real Name Active', 'vehicle_number' => 'AUTO-REAL-1',
            'imei_number' => $imei, 'has_gps_device' => true, 'status' => 'Activated', 'payment_status' => 'Paid', 'warranty_months' => 12]);
        $expired = ExpiredDevice::create(['vehicle_id' => $vid, 'customer_id' => $cid, 'customer_name' => 'Real Name Expired', 'vehicle_number' => 'AUTO-REAL-1',
            'imei_number' => $imei, 'payment_status' => 'not-Paid', 'status' => 'Expired']);
        $paymentId = DB::table('package_payments')->insertGetId([
            'imei_number' => $imei, 'vehicle_id' => $vid, 'vehicle_number' => 'AUTO-REAL-1', 'customer_id' => $cid, 'customer_name' => 'Real Name Payment',
            'source' => 'activation', 'package_model' => 'monthly', 'customer_price' => 1500, 'status' => 'valid', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $techId = DB::table('technicians')->insertGetId(['name' => 'Autotest Tech', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $jobId = DB::table('technician_jobs')->insertGetId([
            'job_type' => 'install', 'status' => 'scheduled', 'vehicle_id' => $vid, 'vehicle_number' => 'AUTO-REAL-1', 'customer_id' => $cid,
            'customer_name' => 'Real Name Job', 'technician_id' => $techId, 'scheduled_for' => now()->toDateString(), 'location_note' => '12 Secret Lane',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('cid', 'vid', 'plate', 'active', 'expired', 'paymentId', 'jobId', 'imei');
    }

    protected function clearDeletedCustomers(): void
    {
        CustomerAd::where('email', 'like', 'deleted-%@deleted.invalid')->delete();
    }

    public function test_scrub_removes_the_name_and_plate_everywhere_but_keeps_money_imei_and_dates(): void
    {
        $this->clearDeletedCustomers();
        $d = $this->deletedCustomer();

        [$code, $out] = $this->run_('customers:scrub-deleted');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Updated 8 record(s) for 1 deleted customer(s).', $out);

        $active = DB::table('activated_devices')->where('activated_device_id', $d['active']->activated_device_id)->first();
        $this->assertSame(self::ANON, $active->customer_name);
        $this->assertSame($d['plate'], $active->vehicle_number);
        $this->assertSame($d['imei'], $active->imei_number);
        $this->assertSame('Paid', $active->payment_status);
        $this->assertSame(12, (int) $active->warranty_months);

        $expired = DB::table('expired_devices')->where('expired_device_id', $d['expired']->expired_device_id)->first();
        $this->assertSame(self::ANON, $expired->customer_name);
        $this->assertSame($d['plate'], $expired->vehicle_number);

        $pay = DB::table('package_payments')->where('id', $d['paymentId'])->first();
        $this->assertSame(self::ANON, $pay->customer_name);
        $this->assertSame($d['plate'], $pay->vehicle_number);
        $this->assertEquals(1500, $pay->customer_price, 'amounts stay');
        $this->assertSame($d['imei'], $pay->imei_number);

        $job = DB::table('technician_jobs')->where('id', $d['jobId'])->first();
        $this->assertSame(self::ANON, $job->customer_name);
        $this->assertSame($d['plate'], $job->vehicle_number);
        $this->assertNull($job->location_note, 'the address note is cleared');
    }

    public function test_a_dry_run_counts_the_same_but_changes_nothing(): void
    {
        $this->clearDeletedCustomers();
        $d = $this->deletedCustomer();

        [$code, $out] = $this->run_('customers:scrub-deleted', ['--dry-run' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('DRY RUN: would update 8 record(s) for 1 deleted customer(s).', $out);
        $this->assertSame('Real Name Active', DB::table('activated_devices')->where('activated_device_id', $d['active']->activated_device_id)->value('customer_name'));
        $this->assertSame('AUTO-REAL-1', DB::table('technician_jobs')->where('id', $d['jobId'])->value('vehicle_number'));
        $this->assertSame('12 Secret Lane', DB::table('technician_jobs')->where('id', $d['jobId'])->value('location_note'));
    }

    public function test_plates_are_scrubbed_only_after_the_app_server_has_really_anonymised_the_vehicle(): void
    {
        $this->clearDeletedCustomers();
        $d = $this->deletedCustomer(plateAnonymised: false);

        [, $out] = $this->run_('customers:scrub-deleted');

        // names go (4), plates and the address note stay until vehicle_ad shows the anonymised plate
        $this->assertStringContainsString('Updated 4 record(s) for 1 deleted customer(s).', $out);
        $this->assertSame(self::ANON, DB::table('activated_devices')->where('activated_device_id', $d['active']->activated_device_id)->value('customer_name'));
        $this->assertSame('AUTO-REAL-1', DB::table('activated_devices')->where('activated_device_id', $d['active']->activated_device_id)->value('vehicle_number'));
        $this->assertSame('12 Secret Lane', DB::table('technician_jobs')->where('id', $d['jobId'])->value('location_note'));
    }

    public function test_an_ordinary_customer_is_never_touched(): void
    {
        $this->clearDeletedCustomers();
        $this->deletedCustomer();   // so the command has work to do
        $cid = (string) Str::uuid();
        CustomerAd::create(['customer_id' => $cid, 'full_name' => 'Keep Me', 'email' => 'keep.me@example.test']);
        $vid = (string) Str::uuid();
        VehicleAd::create(['vehicle_id' => $vid, 'customer_id' => $cid, 'vehicle_number' => 'AUTO-KEEP-1']);
        $kept = ActivatedDevice::create(['vehicle_id' => $vid, 'customer_id' => $cid, 'customer_name' => 'Keep Me', 'vehicle_number' => 'AUTO-KEEP-1',
            'imei_number' => $this->validImei(), 'status' => 'Activated', 'payment_status' => 'Paid']);

        $this->run_('customers:scrub-deleted');

        $row = $kept->fresh();
        $this->assertSame('Keep Me', $row->customer_name);
        $this->assertSame('AUTO-KEEP-1', $row->vehicle_number);
    }

    public static function lookAlikeEmails(): array
    {
        return ['wrong domain' => ['deleted-1@example.test'], 'suffix added' => ['deleted-1@deleted.invalid.evil.test'], 'no prefix' => ['x-deleted-1@deleted.invalid'], 'name only' => ['Deleted customer']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lookAlikeEmails')]
    public function test_a_customer_is_treated_as_deleted_only_when_the_email_has_the_exact_deleted_form(string $email): void
    {
        $this->clearDeletedCustomers();
        $cid = (string) Str::uuid();
        CustomerAd::create(['customer_id' => $cid, 'full_name' => self::ANON, 'email' => $email]);
        $device = ActivatedDevice::create(['vehicle_id' => (string) Str::uuid(), 'customer_id' => $cid, 'customer_name' => 'Real Person', 'vehicle_number' => 'AUTO-LA-1',
            'imei_number' => $this->validImei(), 'status' => 'Activated', 'payment_status' => 'Paid']);

        [, $out] = $this->run_('customers:scrub-deleted');

        $this->assertStringContainsString('No deleted customers.', $out);
        $this->assertSame('Real Person', $device->fresh()->customer_name);
    }

    public function test_with_no_deleted_customers_it_says_so(): void
    {
        $this->clearDeletedCustomers();

        [$code, $out] = $this->run_('customers:scrub-deleted');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('No deleted customers.', $out);
    }

    public function test_the_scrub_is_safe_to_run_again_and_logs_only_a_real_change(): void
    {
        $this->clearDeletedCustomers();
        $this->deletedCustomer();
        Log::spy();

        $this->run_('customers:scrub-deleted');
        [, $second] = $this->run_('customers:scrub-deleted');

        $this->assertStringContainsString('Updated 0 record(s) for 1 deleted customer(s).', $second);
        Log::shouldHaveReceived('info')->withArgs(fn ($m) => str_contains($m, 'customers:scrub-deleted Updated 8 record(s)'))->once();
    }

    public function test_a_dry_run_writes_no_log_line(): void
    {
        $this->clearDeletedCustomers();
        $this->deletedCustomer();
        Log::spy();

        $this->run_('customers:scrub-deleted', ['--dry-run' => true]);

        Log::shouldNotHaveReceived('info');
    }

    // ================================================================== dashboard:snapshot

    private int $openComplaints = 0;

    private function complaintsFake(?int $count, ?int $status = 200): void
    {
        $this->openComplaints = (int) $count;
        Http::fake(function ($request) use ($status) {
            if (str_contains($request->url(), '/api/internal/complaints/for-admin')) {
                return $status === 200 ? Http::response(['data' => array_fill(0, $this->openComplaints, ['id' => 1])], 200) : Http::response('boom', $status);
            }
            if (str_contains($request->url(), '/devices')) {
                return Http::response(['connected_devices' => ['a', 'b']], 200);
            }

            return Http::response([], 200);
        });
    }

    public function test_the_snapshot_saves_todays_numbers(): void
    {
        Carbon::setTestNow('2026-11-12 23:55:00');
        $this->complaintsFake(3);
        $stock = (int) Stock::sum('company_available_stock');
        $tracked = VehicleAd::whereNotNull('imei')->where('imei', '!=', '')->count();

        [$code, $out] = $this->run_('dashboard:snapshot');

        $this->assertSame(0, $code);
        $row = DashboardMetricSnapshot::whereDate('snapshot_date', '2026-11-12')->get();
        $this->assertCount(1, $row);
        $this->assertSame(3, (int) $row[0]->open_complaints_count);
        $this->assertSame(2, (int) $row[0]->devices_online);
        $this->assertSame(max($tracked - 2, 0), (int) $row[0]->devices_offline);
        $this->assertSame($stock, (int) $row[0]->stock_units_available);
        $this->assertStringContainsString("Dashboard snapshot recorded for 2026-11-12: 3 open complaints, 2 devices online, {$stock} stock units.", $out);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'for-admin') && $r->header('X-Admin-Sync-Key') === ['autotest-key']);
    }

    public function test_running_it_twice_on_the_same_day_overwrites_that_days_row(): void
    {
        Carbon::setTestNow('2026-11-12 23:55:00');
        $this->complaintsFake(3);
        $this->run_('dashboard:snapshot');
        $this->openComplaints = 7;

        $this->run_('dashboard:snapshot');

        $rows = DashboardMetricSnapshot::whereDate('snapshot_date', '2026-11-12')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(7, (int) $rows[0]->open_complaints_count);
    }

    public function test_a_new_day_gets_a_new_row(): void
    {
        $this->complaintsFake(1);
        Carbon::setTestNow('2026-11-12 23:55:00');
        $this->run_('dashboard:snapshot');
        Carbon::setTestNow('2026-11-13 23:55:00');
        $this->run_('dashboard:snapshot');

        $this->assertSame(2, DashboardMetricSnapshot::whereBetween('snapshot_date', ['2026-11-12', '2026-11-13'])->count());
    }

    public static function complaintFailures(): array
    {
        return ['server error' => [500], 'not found' => [404], 'unauthorised' => [401]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('complaintFailures')]
    public function test_a_failing_complaints_service_still_saves_a_snapshot_with_0_complaints(int $status): void
    {
        Carbon::setTestNow('2026-11-12 23:55:00');
        $this->complaintsFake(null, $status);

        [$code, $out] = $this->run_('dashboard:snapshot');

        $this->assertSame(0, $code);
        $this->assertSame(0, (int) DashboardMetricSnapshot::whereDate('snapshot_date', '2026-11-12')->value('open_complaints_count'));
        $this->assertStringContainsString('0 open complaints', $out);
    }

    public function test_the_complaints_in_an_error_answer_are_not_counted(): void
    {
        Carbon::setTestNow('2026-11-12 23:55:00');
        Http::fake(fn ($request) => str_contains($request->url(), '/devices')
            ? Http::response(['connected_devices' => []], 200)
            : Http::response(['data' => [['id' => 1], ['id' => 2], ['id' => 3]]], 500));

        $this->run_('dashboard:snapshot');

        $this->assertSame(0, (int) DashboardMetricSnapshot::whereDate('snapshot_date', '2026-11-12')->value('open_complaints_count'));
    }

    public function test_an_unreachable_complaints_service_and_gateway_still_save_a_snapshot(): void
    {
        Carbon::setTestNow('2026-11-12 23:55:00');
        Http::fake(fn () => throw new ConnectionException('cannot connect'));

        [$code, $out] = $this->run_('dashboard:snapshot');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('0 open complaints, 0 devices online', $out);
        $this->assertSame(1, DashboardMetricSnapshot::whereDate('snapshot_date', '2026-11-12')->count());
    }

    public function test_offline_devices_never_go_below_zero(): void
    {
        Carbon::setTestNow('2026-11-12 23:55:00');
        Http::fake(function ($request) {
            return str_contains($request->url(), '/devices')
                ? Http::response(['connected_devices' => array_fill(0, 5000, 'x')], 200)
                : Http::response(['data' => []], 200);
        });

        $this->run_('dashboard:snapshot');

        $this->assertSame(0, (int) DashboardMetricSnapshot::whereDate('snapshot_date', '2026-11-12')->value('devices_offline'));
    }
}