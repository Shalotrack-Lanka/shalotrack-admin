<?php

namespace Tests\Pgsql;

use App\Models\VehicleAd;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * VEHICLE DETAILS, on real PostgreSQL (autotest schema).
 *
 *   GET admin.vehicles.details   ADMIN only: the list of every vehicle of the mobile app
 *
 * Opening the page first runs the "vehicles:sync" command (it asks the app server for its vehicles and saves them
 * in the local copy "vehicle_ad"), then shows the local copy, newest first, 10 per page.
 * What the tests prove (simple words):
 *   - the sync asks the app server with the secret key, adds new vehicles and updates old ones (never duplicates),
 *   - every column on the page shows the right value; missing values show a dash or N/A, not a blank or an error,
 *   - the GPS badge shows the IMEI when a device is linked and "None" when not,
 *   - the "last synced" time is shown in Sri Lanka time,
 *   - the list is newest first and split in pages of 10,
 *   - if the app server is slow, broken or sends rubbish, the page still opens with the vehicles already saved,
 *   - names are shown as text, never run as a script.
 *
 * The app server is FAKED (no real network). Rows are tagged "AUTOTEST" and rolled back after each test.
 */
class VehicleDetailsTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    private const API = 'https://api.test';

    /** What the faked app server sends as its vehicle list. */
    private array $apiRows = [];
    /** ok | down | error | not_json | no_data */
    private string $apiMode = 'ok';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Sleep::fake();   // the sync retries after a pause; do not really wait

        config([
            'services.shalotrack_api.base_url' => self::API,
            'services.shalotrack_api.sync_key' => 'sync-key-for-tests',
        ]);

        Http::fake(function (ClientRequest $request) {
            if (! str_starts_with($request->url(), self::API . '/api/internal/vehicles-sync')) {
                return Http::response(['data' => []], 200);   // the sidebar's complaint count
            }

            return match ($this->apiMode) {
                'down'     => throw new ConnectionException('cannot connect'),
                'error'    => Http::response(['message' => 'boom'], 500),
                'not_json' => Http::response('<html>bad gateway</html>', 200),
                'no_data'  => Http::response(['status' => 'ok'], 200),
                default    => Http::response(['data' => $this->apiRows], 200),
            };
        });
    }

    // ------------------------------------------------------------------ helpers

    /** One vehicle the way the app server sends it. */
    private function apiVehicle(array $overrides = []): array
    {
        $n = random_int(100000, 999999);

        return array_merge([
            'vehicleId'     => (string) Str::uuid(),
            'customerId'    => (string) Str::uuid(),
            'customerName'  => "AUTOTEST Customer {$n}",
            'vehicleNumber' => "AUTOTEST-{$n}",
            'chassisNumber' => "CH{$n}",
            'engineNumber'  => "EN{$n}",
            'make'          => 'Toyota',
            'model'         => 'Aqua',
            'year'          => 2019,
            'color'         => 'White',
            'vehicleType'   => 'Car',
            'fuelType'      => 'Hybrid',
            'hasGpsDevice'  => true,
            'imei'          => '864876170981409',
        ], $overrides);
    }

    /** A vehicle already saved in the local copy. */
    private function local(array $overrides = []): VehicleAd
    {
        $n = random_int(100000, 999999);

        return VehicleAd::forceCreate(array_merge([
            'vehicle_id'     => (string) Str::uuid(),
            'customer_id'    => (string) Str::uuid(),
            'customer_name'  => "AUTOTEST Local {$n}",
            'vehicle_number' => "AUTOTEST-L{$n}",
            'make'           => 'Honda',
            'model'          => 'Vezel',
            'year'           => 2020,
            'color'          => 'Black',
            'has_gps_device' => false,
        ], $overrides));
    }

    private function open(): \Illuminate\Testing\TestResponse
    {
        $this->actingAs($this->makeAdmin('ADMIN'));

        return $this->get(route('admin.vehicles.details'));
    }

    private function syncCalls(): int
    {
        return Http::recorded(fn (ClientRequest $r) => str_contains($r->url(), '/api/internal/vehicles-sync'))->count();
    }

    // ------------------------------------------------------------------ the sync

    public function test_opening_the_page_asks_the_app_server_with_the_secret_key_and_saves_what_it_sends(): void
    {
        $a = $this->apiVehicle(['vehicleNumber' => 'AUTOTEST-AAA-1']);
        $b = $this->apiVehicle(['vehicleNumber' => 'AUTOTEST-BBB-2', 'make' => 'Nissan', 'model' => 'Leaf']);
        $this->apiRows = [$a, $b];

        $response = $this->open();

        $response->assertOk()
            ->assertSee('Vehicle Details List')
            ->assertSee('AUTOTEST-AAA-1')
            ->assertSee('AUTOTEST-BBB-2')
            ->assertSee('Nissan - Leaf');

        Http::assertSent(fn (ClientRequest $r) => str_starts_with($r->url(), self::API . '/api/internal/vehicles-sync')
            && $r->method() === 'GET'
            && $r->header('X-Admin-Sync-Key') === ['sync-key-for-tests']);

        $this->assertSame(1, $this->syncCalls());
        $saved = VehicleAd::find($a['vehicleId']);
        $this->assertSame('AUTOTEST-AAA-1', $saved->vehicle_number);
        $this->assertSame($a['customerId'], $saved->customer_id);
        $this->assertSame($a['imei'], $saved->imei);
        $this->assertTrue($saved->has_gps_device);
        $this->assertSame(2019, $saved->year);
    }

    public function test_every_column_shows_its_own_value(): void
    {
        $row = $this->apiVehicle([
            'vehicleNumber' => 'AUTOTEST-COLS-1', 'make' => 'Suzuki', 'model' => 'Alto', 'year' => 2016, 'color' => 'Silver',
            'chassisNumber' => 'CHASSIS-XYZ-1', 'engineNumber' => 'ENGINE-XYZ-1', 'vehicleType' => 'Hatchback', 'fuelType' => 'Petrol',
            'customerName' => 'AUTOTEST Kamal Perera',
        ]);
        $this->apiRows = [$row];

        $this->open()->assertOk()
            ->assertSeeInOrder(['AUTOTEST-COLS-1', 'Suzuki - Alto', '2016', 'Silver', 'CHASSIS-XYZ-1', 'ENGINE-XYZ-1', 'Hatchback / Petrol', 'AUTOTEST Kamal Perera', '864876170981409']);
    }

    public function test_a_vehicle_that_is_already_saved_is_updated_not_duplicated(): void
    {
        $old = $this->local(['vehicle_number' => 'AUTOTEST-OLD-1', 'make' => 'Honda', 'color' => 'Black', 'imei' => null, 'has_gps_device' => false]);
        $before = VehicleAd::count();

        $this->apiRows = [$this->apiVehicle([
            'vehicleId' => $old->vehicle_id, 'vehicleNumber' => 'AUTOTEST-NEW-1', 'make' => 'Mazda', 'color' => 'Red', 'hasGpsDevice' => true, 'imei' => '868159941856606',
        ])];

        $this->open()->assertOk()->assertSee('AUTOTEST-NEW-1')->assertSee('Mazda')->assertDontSee('AUTOTEST-OLD-1');

        $this->assertSame($before, VehicleAd::count(), 'the same vehicle id must not create a second row');
        $fresh = VehicleAd::find($old->vehicle_id);
        $this->assertSame('AUTOTEST-NEW-1', $fresh->vehicle_number);
        $this->assertSame('Red', $fresh->color);
        $this->assertSame('868159941856606', $fresh->imei);
        $this->assertTrue($fresh->has_gps_device);
    }

    public function test_opening_the_page_twice_does_not_make_copies(): void
    {
        $this->apiRows = [$this->apiVehicle(), $this->apiVehicle()];
        $this->actingAs($admin = $this->makeAdmin('ADMIN'));

        $this->get(route('admin.vehicles.details'))->assertOk();
        $afterFirst = VehicleAd::count();
        $this->get(route('admin.vehicles.details'))->assertOk();

        $this->assertSame($afterFirst, VehicleAd::count());
        $this->assertSame(2, $this->syncCalls(), 'every visit runs the sync once');
    }

    public function test_a_vehicle_the_app_server_sends_with_empty_optional_fields_is_saved_and_shown_with_dashes(): void
    {
        $id = (string) Str::uuid();
        $this->apiRows = [[
            'vehicleId' => $id, 'customerId' => null, 'vehicleNumber' => 'AUTOTEST-EMPTY-1',
            'hasGpsDevice' => false,
        ]];

        $this->open()->assertOk()
            ->assertSee('AUTOTEST-EMPTY-1')
            ->assertSee('N/A')       // chassis, engine and customer
            ->assertSee('- / -')     // type and fuel
            ->assertSee('None');     // no GPS device

        $row = VehicleAd::find($id);
        $this->assertNull($row->customer_id);
        $this->assertFalse($row->has_gps_device);
    }

    public function test_a_large_list_is_saved_completely(): void
    {
        $this->apiRows = [];
        for ($i = 1; $i <= 120; $i++) {
            $this->apiRows[] = $this->apiVehicle(['vehicleNumber' => sprintf('AUTOTEST-BULK-%03d', $i)]);
        }
        $before = VehicleAd::count();

        $this->open()->assertOk();

        $this->assertSame($before + 120, VehicleAd::count());
        $this->assertSame(120, VehicleAd::where('vehicle_number', 'like', 'AUTOTEST-BULK-%')->count());
    }

    // ------------------------------------------------------------------ GPS badge and times

    public function test_the_gps_badge_shows_the_imei_when_linked_and_none_when_not(): void
    {
        $this->apiRows = [
            $this->apiVehicle(['vehicleNumber' => 'AUTOTEST-GPS-ON', 'hasGpsDevice' => true, 'imei' => '864876170999991']),
            $this->apiVehicle(['vehicleNumber' => 'AUTOTEST-GPS-OFF', 'hasGpsDevice' => false, 'imei' => null]),
            $this->apiVehicle(['vehicleNumber' => 'AUTOTEST-GPS-NOIMEI', 'hasGpsDevice' => true, 'imei' => null]),
        ];

        $response = $this->open()->assertOk();

        $response->assertSee('864876170999991')
            ->assertSee('Linked');   // a device is linked but the IMEI is not known yet
        $this->assertGreaterThanOrEqual(1, substr_count($response->getContent(), '>None<'));
    }

    public function test_the_last_synced_time_is_shown_in_sri_lanka_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00', 'UTC'));
        $this->apiRows = [$this->apiVehicle(['vehicleNumber' => 'AUTOTEST-TIME-1'])];

        try {
            $this->open()->assertOk()->assertSee('2026-11-11 15:30');   // UTC+05:30
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_a_vehicle_that_was_never_synced_shows_a_dash_for_the_time(): void
    {
        $this->apiMode = 'no_data';   // nothing new comes in
        $this->local(['vehicle_number' => 'AUTOTEST-NEVER-1', 'last_synced_at' => null]);

        $response = $this->open()->assertOk()->assertSee('AUTOTEST-NEVER-1');

        $this->assertMatchesRegularExpression('/AUTOTEST-NEVER-1.*?<td class="px-6 py-4">-<\/td>\s*<\/tr>/s', $response->getContent());
    }

    // ------------------------------------------------------------------ order and pages

    public function test_the_newest_vehicle_is_first(): void
    {
        $this->apiMode = 'no_data';
        $this->local(['vehicle_number' => 'AUTOTEST-ORD-OLD', 'created_at' => '2026-01-01 08:00:00']);
        $this->local(['vehicle_number' => 'AUTOTEST-ORD-MID', 'created_at' => '2026-06-01 08:00:00']);
        $this->local(['vehicle_number' => 'AUTOTEST-ORD-NEW', 'created_at' => '2026-10-01 08:00:00']);

        $content = $this->open()->assertOk()->getContent();

        $this->assertLessThan(strpos($content, 'AUTOTEST-ORD-MID'), strpos($content, 'AUTOTEST-ORD-NEW'));
        $this->assertLessThan(strpos($content, 'AUTOTEST-ORD-OLD'), strpos($content, 'AUTOTEST-ORD-MID'));
    }

    public function test_the_list_is_split_in_pages_of_ten(): void
    {
        $this->apiMode = 'no_data';
        VehicleAd::query()->delete();   // only this test's rows (rolled back after the test)
        for ($i = 1; $i <= 23; $i++) {
            $this->local(['vehicle_number' => sprintf('AUTOTEST-PG-%02d', $i), 'created_at' => Carbon::parse('2026-10-01 08:00:00')->addMinutes($i)]);
        }
        $this->actingAs($this->makeAdmin('ADMIN'));

        $page1 = $this->get(route('admin.vehicles.details'))->assertOk();
        $page3 = $this->get(route('admin.vehicles.details', ['page' => 3]))->assertOk();

        $this->assertSame(23, $page1->viewData('vehicles')->total());
        $this->assertCount(10, $page1->viewData('vehicles')->items());
        $this->assertCount(3, $page3->viewData('vehicles')->items());
        $page1->assertSee('AUTOTEST-PG-23')->assertDontSee('AUTOTEST-PG-13');   // newest first: 23..14 on page 1
        $page3->assertSee('AUTOTEST-PG-01')->assertSee('AUTOTEST-PG-03')->assertDontSee('AUTOTEST-PG-04');
    }

    public function test_a_page_number_past_the_end_shows_the_empty_message_not_an_error(): void
    {
        $this->apiMode = 'no_data';
        $this->local();
        $this->actingAs($this->makeAdmin('ADMIN'));

        $this->get(route('admin.vehicles.details', ['page' => 999]))->assertOk()->assertSee('No Vehicles Found.');
    }

    public function test_strange_page_values_do_not_crash_the_page(): void
    {
        $this->apiMode = 'no_data';
        $this->local();
        $this->actingAs($this->makeAdmin('ADMIN'));

        foreach (['abc', '-5', '0', '1.5', '99999999999999999999'] as $value) {
            $this->get(route('admin.vehicles.details', ['page' => $value]))->assertOk();
        }
        $this->get(route('admin.vehicles.details') . '?page[]=2')->assertOk();
    }

    public function test_with_no_vehicles_at_all_the_page_says_so(): void
    {
        $this->apiRows = [];
        VehicleAd::query()->delete();

        $this->open()->assertOk()->assertSee('No Vehicles Found.');
    }

    // ------------------------------------------------------------------ when the app server fails

    public static function brokenAnswers(): array
    {
        return [
            'server is down'        => ['down'],
            'server error 500'      => ['error'],
            'answer is not JSON'    => ['not_json'],
            'answer has no data'    => ['no_data'],
        ];
    }

    #[DataProvider('brokenAnswers')]
    public function test_a_broken_app_server_never_empties_or_breaks_the_list(string $mode): void
    {
        $this->local(['vehicle_number' => 'AUTOTEST-KEEP-1']);
        $this->local(['vehicle_number' => 'AUTOTEST-KEEP-2']);
        $before = VehicleAd::count();
        $this->apiMode = $mode;

        $this->open()->assertOk()->assertSee('AUTOTEST-KEEP-1')->assertSee('AUTOTEST-KEEP-2');

        $this->assertSame($before, VehicleAd::count(), 'a failed sync must not delete or change anything');
    }

    public function test_a_vehicle_the_app_server_no_longer_lists_is_not_deleted_here(): void
    {
        $this->local(['vehicle_number' => 'AUTOTEST-GONE-1']);
        $this->apiRows = [$this->apiVehicle(['vehicleNumber' => 'AUTOTEST-STAYS-1'])];

        $this->open()->assertOk()->assertSee('AUTOTEST-GONE-1')->assertSee('AUTOTEST-STAYS-1');
    }

    // ------------------------------------------------------------------ safety

    public function test_names_from_the_app_are_shown_as_text_never_run_as_a_script(): void
    {
        $evil = '<script>alert(1)</script>';
        $this->apiRows = [$this->apiVehicle([
            'vehicleNumber' => 'AUTOTEST-XSS-1', 'make' => $evil, 'model' => '<img src=x onerror=alert(2)>', 'customerName' => $evil, 'color' => $evil,
        ])];

        $response = $this->open()->assertOk();

        $response->assertDontSee($evil, false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_a_visitor_who_is_not_signed_in_is_sent_to_login_and_nothing_is_synced(): void
    {
        $this->apiRows = [$this->apiVehicle()];

        $this->get(route('admin.vehicles.details'))->assertRedirect(route('login'));

        $this->assertSame(0, $this->syncCalls());
    }

    public static function otherRoles(): array
    {
        return ['dealer' => ['DEALER'], 'distributor' => ['DISTRIBUTOR'], 'finance' => ['FINANCE'], 'technician' => ['TECHNICIAN'], 'supplier' => ['SUPPLIER']];
    }

    #[DataProvider('otherRoles')]
    public function test_only_the_company_admin_may_open_it(string $role): void
    {
        $this->apiRows = [$this->apiVehicle()];
        $this->actingAs($this->makeAdmin($role));

        $this->get(route('admin.vehicles.details'))->assertForbidden();

        $this->assertSame(0, $this->syncCalls(), 'a refused role must not trigger the sync');
    }
}