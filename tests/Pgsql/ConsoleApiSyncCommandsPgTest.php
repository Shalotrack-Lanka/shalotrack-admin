<?php

namespace Tests\Pgsql;

use App\Models\ActivatedDevice;
use App\Models\CustomerAd;
use App\Models\VehicleAd;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsReportData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * THE THREE COMMANDS THAT TALK TO THE APP SERVER (C# API) EVERY FEW MINUTES, run for real on PostgreSQL
 * (autotest schema) with the app server faked:
 *
 *   customers:sync            pulls customers AND vehicles into the local mirror tables (every 5 minutes)
 *   vehicles:sync             pulls vehicles only
 *   subscriptions:sync-to-api pushes every device's paid / not paid state to the app server (every minute)
 *
 * What the tests prove (simple words): the shared key is sent, the right rows are created or updated (never
 * duplicated), a customer's "verified" mark is never wiped by a sync, missing fields get safe defaults, a broken
 * app server gives exit code 1 with a clear message (and customers still sync when vehicles fail, and the other
 * way round), failed calls are tried 3 times, and the subscription push says "active" only for Paid devices.
 */
class ConsoleApiSyncCommandsPgTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsReportData;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shalotrack_api.sync_key' => 'autotest-key', 'services.shalotrack_api.base_url' => 'https://api.example.test']);
        Sleep::fake();
    }

    /** @return array{0:int,1:string} */
    private function run_(string $command): array
    {
        $code = Artisan::call($command);

        return [$code, Artisan::output()];
    }

    private function customerRow(array $o = []): array
    {
        return array_merge([
            'customerId' => (string) Str::uuid(), 'fullName' => 'Autotest Synced', 'email' => 'synced+' . $this->nextIntakeNumber() . '@example.test',
            'phoneNumber' => '+94771234567', 'nicNumber' => '900000000V', 'address' => '1 Autotest Road', 'profileImage' => 'img.png',
            'vehicleCount' => 2, 'accountStatus' => 1,
        ], $o);
    }

    private function vehicleRow(array $o = []): array
    {
        return array_merge([
            'vehicleId' => (string) Str::uuid(), 'customerId' => (string) Str::uuid(), 'customerName' => 'Autotest Synced', 'vehicleNumber' => 'AUTO-SYNC-' . $this->nextIntakeNumber(),
            'chassisNumber' => 'CH123', 'engineNumber' => 'EN123', 'make' => 'Toyota', 'model' => 'Aqua', 'year' => 2020, 'color' => 'White',
            'vehicleType' => 'Car', 'fuelType' => 'Hybrid', 'hasGpsDevice' => true, 'imei' => $this->validImei(),
        ], $o);
    }

    /** Fake both endpoints; a null list means "answer with a server error". */
    private function fakeApi(?array $customers, ?array $vehicles): void
    {
        Http::fake(function ($request) use ($customers, $vehicles) {
            if (str_ends_with($request->url(), '/api/internal/customers-sync')) {
                return $customers === null ? Http::response('boom', 500) : Http::response(['data' => $customers], 200);
            }
            if (str_ends_with($request->url(), '/api/internal/vehicles-sync')) {
                return $vehicles === null ? Http::response('boom', 500) : Http::response(['data' => $vehicles], 200);
            }

            return Http::response([], 404);
        });
    }

    private function calls(string $endingWith)
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn ($r) => str_ends_with($r->url(), $endingWith))->values();
    }

    // ================================================================== customers:sync

    public function test_customers_and_vehicles_are_created_with_every_field_mapped(): void
    {
        $c = $this->customerRow();
        $v = $this->vehicleRow(['customerId' => $c['customerId']]);
        $this->fakeApi([$c], [$v]);

        [$code, $out] = $this->run_('customers:sync');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Customers synced: 1', $out);
        $this->assertStringContainsString('Vehicles synced: 1', $out);

        $row = CustomerAd::where('customer_id', $c['customerId'])->first();
        $this->assertSame('Autotest Synced', $row->full_name);
        $this->assertSame($c['email'], $row->email);
        $this->assertSame('+94771234567', $row->phone_number);
        $this->assertSame('900000000V', $row->nic_number);
        $this->assertSame('1 Autotest Road', $row->address);
        $this->assertSame('img.png', $row->profile_image);
        $this->assertSame(2, (int) $row->vehicle_count);
        $this->assertSame(1, $row->source_account_status);
        $this->assertNotNull($row->last_synced_at);

        $veh = VehicleAd::where('vehicle_id', $v['vehicleId'])->first();
        $this->assertSame($c['customerId'], $veh->customer_id);
        $this->assertSame($v['vehicleNumber'], $veh->vehicle_number);
        $this->assertSame('Toyota', $veh->make);
        $this->assertSame('Aqua', $veh->model);
        $this->assertSame('Hybrid', $veh->fuel_type);
        $this->assertTrue((bool) $veh->has_gps_device);
        $this->assertSame($v['imei'], $veh->imei);
    }

    public function test_both_calls_carry_the_shared_key_as_a_json_get(): void
    {
        $this->fakeApi([], []);

        $this->run_('customers:sync');

        foreach (['/api/internal/customers-sync', '/api/internal/vehicles-sync'] as $path) {
            $calls = $this->calls($path);
            $this->assertCount(1, $calls, $path);
            $this->assertSame('GET', $calls[0]->method());
            $this->assertSame(['autotest-key'], $calls[0]->header('X-Admin-Sync-Key'));
        }
    }

    public function test_running_it_again_updates_rows_instead_of_duplicating_them(): void
    {
        $c = $this->customerRow();
        $v = $this->vehicleRow(['customerId' => $c['customerId']]);
        $this->fakeApi([$c], [$v]);
        $this->run_('customers:sync');

        $this->fakeApiUpdated($c, $v);
        $this->run_('customers:sync');

        $this->assertSame(1, CustomerAd::where('customer_id', $c['customerId'])->count());
        $this->assertSame(1, VehicleAd::where('vehicle_id', $v['vehicleId'])->count());
        $this->assertSame('New Name', CustomerAd::where('customer_id', $c['customerId'])->value('full_name'));
        $this->assertSame('NEW-PLATE', VehicleAd::where('vehicle_id', $v['vehicleId'])->value('vehicle_number'));
    }

    private function fakeApiUpdated(array $c, array $v): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        $this->fakeApi([array_merge($c, ['fullName' => 'New Name'])], [array_merge($v, ['vehicleNumber' => 'NEW-PLATE'])]);
    }

    public function test_a_sync_never_wipes_a_customers_verified_mark(): void
    {
        $c = $this->customerRow();
        CustomerAd::create(['customer_id' => $c['customerId'], 'full_name' => 'Old Name', 'cus_status' => 'verified']);
        $this->fakeApi([$c], []);

        $this->run_('customers:sync');

        $row = CustomerAd::where('customer_id', $c['customerId'])->first();
        $this->assertSame('verified', $row->cus_status, 'verification is decided in this portal, not by the app server');
        $this->assertSame('Autotest Synced', $row->full_name);
    }

    public function test_missing_optional_fields_get_safe_defaults(): void
    {
        $cid = (string) Str::uuid();
        $vid = (string) Str::uuid();
        $this->fakeApi([['customerId' => $cid]], [['vehicleId' => $vid]]);

        [$code] = $this->run_('customers:sync');

        $this->assertSame(0, $code);
        $row = CustomerAd::where('customer_id', $cid)->first();
        $this->assertNull($row->full_name);
        $this->assertSame(0, (int) $row->vehicle_count);
        $veh = VehicleAd::where('vehicle_id', $vid)->first();
        $this->assertFalse((bool) $veh->has_gps_device);
        $this->assertNull($veh->imei);
    }

    public function test_an_empty_answer_warns_but_is_not_a_failure(): void
    {
        $this->fakeApi([], []);
        Log::spy();

        [$code, $out] = $this->run_('customers:sync');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Customer sync returned 0 records.', $out);
        $this->assertStringContainsString('Vehicle sync returned 0 records.', $out);
        Log::shouldHaveReceived('warning')->twice();
    }

    public function test_when_customers_fail_the_run_fails_but_vehicles_are_still_synced(): void
    {
        $v = $this->vehicleRow();
        $this->fakeApi(null, [$v]);
        Log::spy();

        [$code, $out] = $this->run_('customers:sync');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Customer sync failed: HTTP 500', $out);
        $this->assertStringContainsString('Vehicles synced: 1', $out);
        $this->assertTrue(VehicleAd::where('vehicle_id', $v['vehicleId'])->exists());
        Log::shouldHaveReceived('error')->withArgs(fn ($m) => $m === 'Customer sync failed')->once();
    }

    public function test_when_vehicles_fail_the_run_fails_but_customers_are_still_synced(): void
    {
        $c = $this->customerRow();
        $this->fakeApi([$c], null);

        [$code, $out] = $this->run_('customers:sync');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Customers synced: 1', $out);
        $this->assertStringContainsString('Vehicle sync failed: HTTP 500', $out);
        $this->assertTrue(CustomerAd::where('customer_id', $c['customerId'])->exists());
    }

    public function test_a_failed_call_is_tried_3_times_and_a_good_one_once(): void
    {
        $this->fakeApi(null, []);

        $this->run_('customers:sync');

        $this->assertCount(3, $this->calls('/api/internal/customers-sync'));
        $this->assertCount(1, $this->calls('/api/internal/vehicles-sync'));
    }

    public function test_a_server_that_answers_201_or_204_style_success_codes_is_still_a_success(): void
    {
        Http::fake(fn () => Http::response(['data' => []], 202));

        [$code] = $this->run_('customers:sync');

        $this->assertSame(0, $code);
    }

    // ================================================================== vehicles:sync

    public function test_vehicles_sync_creates_and_updates_vehicles_only(): void
    {
        $v = $this->vehicleRow();
        $this->fakeApi([$this->customerRow()], [$v]);
        $customers = CustomerAd::count();

        [$code, $out] = $this->run_('vehicles:sync');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Synced 1 vehicles.', $out);
        $this->assertSame($customers, CustomerAd::count(), 'customers are not touched');
        $this->assertCount(0, $this->calls('/api/internal/customers-sync'));
        $this->assertSame($v['vehicleNumber'], VehicleAd::where('vehicle_id', $v['vehicleId'])->value('vehicle_number'));
        $this->assertSame(['autotest-key'], $this->calls('/api/internal/vehicles-sync')[0]->header('X-Admin-Sync-Key'));
    }

    public function test_vehicles_sync_updates_in_place_and_defaults_missing_fields(): void
    {
        $v = $this->vehicleRow();
        $this->fakeApi([], [$v]);
        $this->run_('vehicles:sync');
        Http::swap(new \Illuminate\Http\Client\Factory());
        $this->fakeApi([], [['vehicleId' => $v['vehicleId'], 'vehicleNumber' => 'CHANGED-1']]);

        $this->run_('vehicles:sync');

        $this->assertSame(1, VehicleAd::where('vehicle_id', $v['vehicleId'])->count());
        $row = VehicleAd::where('vehicle_id', $v['vehicleId'])->first();
        $this->assertSame('CHANGED-1', $row->vehicle_number);
        $this->assertNull($row->imei, 'a field missing from the answer becomes empty, as the app server is the source of truth');
        $this->assertFalse((bool) $row->has_gps_device);
    }

    public function test_vehicles_sync_with_an_empty_list_is_a_success(): void
    {
        $this->fakeApi([], []);

        [$code, $out] = $this->run_('vehicles:sync');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Synced 0 vehicles.', $out);
    }

    public static function failureCodes(): array
    {
        return ['server error' => [500], 'unauthorised' => [401], 'not found' => [404], 'bad gateway' => [502]];
    }

    #[DataProvider('failureCodes')]
    public function test_vehicles_sync_reports_a_failing_app_server_with_exit_1(int $status): void
    {
        Http::fake(fn () => Http::response('nope', $status));
        $vehicles = VehicleAd::count();
        Log::spy();

        [$code, $out] = $this->run_('vehicles:sync');

        $this->assertSame(1, $code);
        $this->assertStringContainsString("API call failed: {$status}", $out);
        $this->assertSame($vehicles, VehicleAd::count());
        Log::shouldHaveReceived('error')->withArgs(fn ($m) => $m === 'Vehicle sync failed')->once();
    }

    // ================================================================== subscriptions:sync-to-api

    private function fakeSubscriptionApi(int $status = 200): void
    {
        Http::fake(['*' => Http::response(['ok' => true], $status)]);
    }

    private function pushed(): array
    {
        return $this->calls('/api/internal/subscription-status-sync')->first()->data()['devices'];
    }

    public function test_every_device_is_pushed_active_only_when_paid(): void
    {
        $this->fakeSubscriptionApi();
        $end = now()->addDays(20)->startOfSecond();
        $paid   = $this->makeBound(['payment_status' => 'Paid', 'subscription_model' => 'monthly', 'subscription_start_date' => now()->subDays(10), 'subscription_end_date' => $end]);
        $unpaid = $this->makeBound(['payment_status' => 'not-Paid']);
        $stale  = $this->makeBound(['payment_status' => 'not-Paid', 'subscription_end_date' => now()->subDays(1)]);

        [$code, $out] = $this->run_('subscriptions:sync-to-api');

        $this->assertSame(0, $code);
        $all = ActivatedDevice::whereNotNull('imei_number')->count();
        $this->assertStringContainsString("Subscription status synced for {$all} device(s).", $out);

        $byImei = collect($this->pushed())->keyBy('imei');
        $this->assertCount($all, $byImei);
        $this->assertTrue($byImei[$paid->imei_number]['isActive']);
        $this->assertSame($paid->fresh()->subscription_end_date->toIso8601String(), $byImei[$paid->imei_number]['expiresAt']);
        $this->assertFalse($byImei[$unpaid->imei_number]['isActive']);
        $this->assertNull($byImei[$unpaid->imei_number]['expiresAt']);
        $this->assertFalse($byImei[$stale->imei_number]['isActive'], 'an unpaid device is never active, even with an old end date');
    }

    public function test_the_push_is_one_authenticated_post_with_only_three_fields_per_device(): void
    {
        $this->fakeSubscriptionApi();
        $this->makeBound(['payment_status' => 'Paid', 'subscription_end_date' => now()->addDay()]);

        $this->run_('subscriptions:sync-to-api');

        $calls = $this->calls('/api/internal/subscription-status-sync');
        $this->assertCount(1, $calls);
        $this->assertSame('POST', $calls[0]->method());
        $this->assertSame(['autotest-key'], $calls[0]->header('X-Admin-Sync-Key'));
        foreach ($calls[0]->data()['devices'] as $d) {
            $this->assertEqualsCanonicalizing(['imei', 'isActive', 'expiresAt'], array_keys($d), 'no customer details are sent');
        }
    }

    public function test_a_failing_app_server_gives_exit_1_a_clear_message_and_3_tries(): void
    {
        $this->makeBound(['payment_status' => 'Paid', 'subscription_end_date' => now()->addDay()]);
        Http::fake(['*' => Http::response('nope', 503)]);
        Log::spy();

        [$code, $out] = $this->run_('subscriptions:sync-to-api');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Subscription status sync failed: HTTP 503', $out);
        $this->assertCount(3, $this->calls('/api/internal/subscription-status-sync'));
        Log::shouldHaveReceived('error')->withArgs(fn ($m) => $m === 'Subscription status sync failed')->once();
    }

    public function test_with_no_devices_nothing_is_sent(): void
    {
        Http::fake();
        DB::table('activated_devices')->delete();

        [$code, $out] = $this->run_('subscriptions:sync-to-api');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('No activated devices to sync.', $out);
        Http::assertNothingSent();
    }

    public function test_an_unreachable_app_server_is_not_swallowed_silently(): void
    {
        $this->makeBound();
        Http::fake(fn () => throw new ConnectionException('cannot connect'));

        $failed = false;
        try {
            [$code] = $this->run_('subscriptions:sync-to-api');
            $failed = $code !== 0;
        } catch (\Throwable $e) {
            $failed = true;   // the scheduler records a thrown error as a failed run
        }

        $this->assertTrue($failed, 'a run that could not reach the app server must not look like a success');
    }
}