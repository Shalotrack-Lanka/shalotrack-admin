<?php

namespace Tests\Pgsql;

use App\Models\CustomerAd;
use App\Models\Dealer;
use App\Models\DealerCustomerAd;
use App\Models\VehicleAd;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsReportData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * GPS TRACKING, on real PostgreSQL (autotest schema).
 *
 *   GET admin.vehicles.gps                 ADMIN: search a vehicle (plate or 15-digit IMEI) and a date range, see its trips
 *   GET admin.vehicles.gps.resolve-address ADMIN: turn a latitude/longitude into an address (used by the page)
 *   GET admin.vehicles.gps.export          ADMIN: the same trips as a PDF report
 *   GET dealer.gps-tracking                DEALER: live position of one of the dealer's own customers' vehicles
 *
 * What the tests prove (simple words):
 *   - what is typed in the search box and the date boxes is cleaned before it is sent to the app server,
 *   - a 15-digit number is searched as an IMEI, anything else as a plate,
 *   - a trip ends when the vehicle stands still (speed 2 km/h or less) for 5 minutes or more, not before,
 *   - trip time, length and distance are worked out right and shown newest first,
 *   - long histories are read page by page (500 points) and stop at 20 pages; if a later page fails we keep what we have,
 *   - when the app server says "not found", "error" or cannot be reached the person is told, and the page does not crash,
 *   - the address lookup uses Google first, then OpenStreetMap, then shows the plain numbers, and remembers answers,
 *   - the PDF opens, and asks for an address of every trip start and end,
 *   - a dealer sees and tracks ONLY vehicles of its own customers; asking for another dealer's vehicle id gets its own vehicle,
 *   - a vehicle with no signal is shown as "no signal", never as a fake position at 0,0,
 *   - text from the app is shown as text, never run as a script.
 *
 * The app server and the address services are FAKED (no real network). Everything is rolled back after each test.
 */
class GpsTrackingTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsReportData;

    private const API   = 'https://api.test';
    private const IMEI  = '864876170981409';
    private const IMEI2 = '868159941856606';

    /** Points of the faked tracking history, OLDEST first: [time, lat, lng, speed]. Served newest first, 500 per page. */
    private array $history = [];
    /** What the faked app server says about the vehicle and where it is now. */
    private ?array $vehicleInfo = null;
    private ?array $currentLocation = null;
    /** Make the fake answer with this status for the page number (page => [status, body]). */
    private array $pageStatus = [];
    /** The first N tracking requests fail with 500 (to prove the retry). */
    private int $failFirst = 0;
    private int $trackingCalls = 0;
    /** Serve 500 points on EVERY page, for ever (to prove the 20 page limit). */
    private bool $endless = false;
    private bool $trackingDown = false;
    /** The list of plates the faked app server gives for the search box. */
    private array $suggestions = [];
    private bool $suggestionsDown = false;
    /** Address services: google answer (null = refuses), osm answer (null = refuses), osm down. */
    private ?string $googleAddress = null;
    private ?string $osmAddress = 'Autotest Road, Colombo';
    private bool $osmDown = false;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Sleep::fake();   // the app retries after a pause; do not really wait

        config([
            'services.shalotrack_api.base_url' => self::API,
            'services.shalotrack_api.sync_key' => 'sync-key-for-tests',
            'services.google.maps_api_key'     => null,
        ]);

        Http::fake(function (ClientRequest $request) {
            $url = $request->url();

            if (str_starts_with($url, self::API . '/api/internal/vehicles-sync')) {
                if ($this->suggestionsDown) {
                    throw new ConnectionException('cannot connect');
                }

                return Http::response(['data' => array_map(fn ($p) => ['vehicleNumber' => $p], $this->suggestions)], 200);
            }

            if (str_starts_with($url, self::API . '/api/internal/gps-tracking-sync')) {
                return $this->trackingAnswer($request);
            }

            if (str_contains($url, 'maps.googleapis.com')) {
                return $this->googleAddress === null
                    ? Http::response(['status' => 'REQUEST_DENIED', 'results' => []], 200)
                    : Http::response(['status' => 'OK', 'results' => [['formatted_address' => $this->googleAddress]]], 200);
            }

            if (str_contains($url, 'nominatim.openstreetmap.org')) {
                if ($this->osmDown) {
                    throw new ConnectionException('osm is down');
                }

                return $this->osmAddress === null
                    ? Http::response([], 500)
                    : Http::response(['display_name' => $this->osmAddress], 200);
            }

            return Http::response(['data' => []], 200);   // the sidebar's complaint count
        });
    }

    // ------------------------------------------------------------------ the faked app server

    private function trackingAnswer(ClientRequest $request)
    {
        $this->trackingCalls++;
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
        $page = (int) ($q['page'] ?? 1);

        if ($this->trackingDown) {
            throw new ConnectionException('cannot connect');
        }

        if ($this->failFirst > 0 && $this->trackingCalls <= $this->failFirst) {
            return Http::response(['message' => 'hiccup'], 500);
        }

        if (isset($this->pageStatus[$page])) {
            [$status, $body] = $this->pageStatus[$page];

            return Http::response($body, $status);
        }

        if ($this->endless) {
            $points = [];
            for ($i = 0; $i < 500; $i++) {
                $minute = ($page - 1) * 500 + $i;
                $points[] = $this->row(Carbon::parse('2026-01-01 00:00:00')->addMinutes($minute)->format('Y-m-d\TH:i:s'), 6.9 + $minute * 0.00001, 79.85, 40);
            }

            return Http::response(['vehicle' => $this->vehicleInfo, 'currentLocation' => $this->currentLocation, 'trackingHistory' => $points], 200);
        }

        $newestFirst = array_reverse($this->history);
        $slice = array_slice($newestFirst, ($page - 1) * 500, 500);

        return Http::response([
            'vehicle'         => $this->vehicleInfo,
            'currentLocation' => $this->currentLocation,
            'trackingHistory' => array_map(fn ($p) => $this->row(...$p), $slice),
        ], 200);
    }

    private function row(string $time, float $lat, float $lng, float $speed): array
    {
        return ['eventTime' => $time, 'latitude' => $lat, 'longitude' => $lng, 'speed' => $speed, 'heading' => 90, 'satellites' => 9];
    }

    // ------------------------------------------------------------------ helpers

    private function point(string $time, float $lat, float $speed = 40, float $lng = 79.85): array
    {
        return ["2026-11-10T{$time}", $lat, $lng, $speed];
    }

    private function vehicleCard(array $overrides = []): array
    {
        return array_merge([
            'vehicleNumber' => 'AUTOTEST-GPS-1', 'make' => 'Toyota', 'model' => 'Aqua', 'customerName' => 'AUTOTEST Kamal',
            'hasGpsDevice' => true, 'imei' => self::IMEI,
        ], $overrides);
    }

    /** All the tracking requests the page made, as arrays of their query values. */
    private function trackingQueries(): array
    {
        return Http::recorded(fn (ClientRequest $r) => str_starts_with($r->url(), self::API . '/api/internal/gps-tracking-sync'))
            ->map(function ($pair) {
                parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $q);

                return $q;
            })->values()->all();
    }

    private function admin(): void
    {
        $this->actingAs($this->makeAdmin('ADMIN'));
    }

    private function search(array $query)
    {
        $this->admin();

        return $this->get(route('admin.vehicles.gps', $query));
    }

    /** Two clear trips (oldest first): 08:00-08:04 (1.1 km) then a 6 minute stop, then 08:20-08:25 (2.2 km). */
    private function twoTrips(): array
    {
        return [
            $this->point('08:00:00', 6.9000, 40),
            $this->point('08:02:00', 6.9050, 40),
            $this->point('08:04:00', 6.9100, 0),
            $this->point('08:10:00', 6.9100, 0),
            $this->point('08:20:00', 7.1000, 40),
            $this->point('08:25:00', 7.1200, 40),
        ];
    }

    // ================================================================== ADMIN: the search page

    public function test_the_page_opens_without_a_search_and_asks_nothing_from_the_app_server_for_tracking(): void
    {
        $this->admin();

        $this->get(route('admin.vehicles.gps'))->assertOk()
            ->assertSee('GPS Tracking History')
            ->assertSee('Search a Vehicle ID or IMEI to view its trip history.')
            ->assertSee('0 trips detected')
            ->assertDontSee('Download PDF');

        $this->assertSame([], $this->trackingQueries());
    }

    public function test_the_search_box_suggests_the_plates_the_app_server_knows(): void
    {
        $this->suggestions = ['AUTOTEST-SUG-1', 'AUTOTEST-SUG-2', '', 'AUTOTEST-SUG-1'];

        $this->admin();
        $response = $this->get(route('admin.vehicles.gps'))->assertOk();

        $response->assertSee('<option value="AUTOTEST-SUG-1"></option>', false)
            ->assertSee('<option value="AUTOTEST-SUG-2"></option>', false);

        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/api/internal/vehicles-sync') && $r->header('X-Admin-Sync-Key') === ['sync-key-for-tests']);
    }

    public function test_when_the_app_server_gives_no_suggestions_the_plates_saved_here_are_used(): void
    {
        $this->suggestionsDown = true;
        VehicleAd::forceCreate(['vehicle_id' => (string) Str::uuid(), 'vehicle_number' => 'AUTOTEST-LOCAL-1']);
        VehicleAd::forceCreate(['vehicle_id' => (string) Str::uuid(), 'vehicle_number' => 'AUTOTEST-LOCAL-2']);
        VehicleAd::forceCreate(['vehicle_id' => (string) Str::uuid(), 'vehicle_number' => null]);
        $this->makeBound(['vehicle_number' => 'AUTOTEST-ACTIVE-1']);
        $this->makeBound(['vehicle_number' => 'AUTOTEST-LOCAL-1']);   // same plate twice: listed once

        $this->admin();
        $response = $this->get(route('admin.vehicles.gps'))->assertOk();

        $response->assertSee('<option value="AUTOTEST-LOCAL-1"></option>', false)
            ->assertSee('<option value="AUTOTEST-LOCAL-2"></option>', false)
            ->assertSee('<option value="AUTOTEST-ACTIVE-1"></option>', false);
        $this->assertSame(1, substr_count($response->getContent(), '<option value="AUTOTEST-LOCAL-1"></option>'));
        $this->assertSame(0, substr_count($response->getContent(), '<option value=""></option>'));
    }

    public function test_searching_a_plate_asks_for_that_plate_with_the_secret_key_and_shows_the_vehicle(): void
    {
        $this->vehicleInfo = $this->vehicleCard();
        $this->history = $this->twoTrips();

        $this->search(['search' => 'AUTOTEST-GPS-1'])->assertOk()
            ->assertSee('Vehicle & Device Summary', false)
            ->assertSee('AUTOTEST-GPS-1')
            ->assertSee('Toyota Aqua')
            ->assertSee('AUTOTEST Kamal')
            ->assertSee(self::IMEI);

        $queries = $this->trackingQueries();
        $this->assertCount(1, $queries);
        $this->assertSame('AUTOTEST-GPS-1', $queries[0]['vehicleNumber']);
        $this->assertArrayNotHasKey('imei', $queries[0]);
        $this->assertSame('1', $queries[0]['page']);
        $this->assertSame('500', $queries[0]['pageSize']);
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'gps-tracking-sync') && $r->header('X-Admin-Sync-Key') === ['sync-key-for-tests']);
    }

    public function test_a_15_digit_number_is_searched_as_an_imei(): void
    {
        $this->vehicleInfo = $this->vehicleCard();
        $this->history = $this->twoTrips();

        $this->search(['search' => self::IMEI])->assertOk()->assertSee('Vehicle & Device Summary', false);

        $q = $this->trackingQueries()[0];
        $this->assertSame(self::IMEI, $q['imei']);
        $this->assertArrayNotHasKey('vehicleNumber', $q);
    }

    public static function notAnImei(): array
    {
        return [
            '14 digits'            => ['86487617098140'],
            '16 digits'            => ['8648761709814099'],
            '15 characters, a letter inside' => ['86487617098140A'],
            'digits with a space'  => ['864876 170981409'],
        ];
    }

    #[DataProvider('notAnImei')]
    public function test_anything_else_is_searched_as_a_plate(string $value): void
    {
        $this->vehicleInfo = $this->vehicleCard();

        $this->search(['search' => $value])->assertOk();

        $q = $this->trackingQueries()[0];
        $this->assertSame($value, $q['vehicleNumber']);
        $this->assertArrayNotHasKey('imei', $q);
    }

    public function test_the_dates_become_the_start_and_the_end_of_those_days(): void
    {
        $this->vehicleInfo = $this->vehicleCard();

        $this->search(['search' => 'AUTOTEST-GPS-1', 'from_date' => '2026-11-01', 'to_date' => '2026-11-10'])->assertOk()
            ->assertSee('value="2026-11-01"', false)
            ->assertSee('value="2026-11-10"', false);

        $q = $this->trackingQueries()[0];
        $this->assertSame('2026-11-01T00:00:00', $q['from']);
        $this->assertSame('2026-11-10T23:59:59', $q['to']);
    }

    public function test_one_date_alone_is_enough(): void
    {
        $this->vehicleInfo = $this->vehicleCard();

        $this->search(['search' => 'AUTOTEST-GPS-1', 'from_date' => '2026-11-05'])->assertOk();

        $q = $this->trackingQueries()[0];
        $this->assertSame('2026-11-05T00:00:00', $q['from']);
        $this->assertArrayNotHasKey('to', $q);
    }

    public static function badDates(): array
    {
        return [
            'a day that does not exist' => ['2026-02-30'],
            'day first'                 => ['10-11-2026'],
            'no zero padding'           => ['2026-1-1'],
            'words'                     => ['yesterday'],
            'a sql try'                 => ["2026-11-01' OR '1'='1"],
            'empty'                     => [''],
        ];
    }

    #[DataProvider('badDates')]
    public function test_a_date_that_is_not_a_real_date_is_ignored_not_sent(string $value): void
    {
        $this->vehicleInfo = $this->vehicleCard();

        $this->search(['search' => 'AUTOTEST-GPS-1', 'from_date' => $value, 'to_date' => $value])->assertOk();

        $q = $this->trackingQueries()[0];
        $this->assertArrayNotHasKey('from', $q);
        $this->assertArrayNotHasKey('to', $q);
    }

    public function test_dates_without_a_search_do_nothing(): void
    {
        $this->admin();

        $this->get(route('admin.vehicles.gps', ['from_date' => '2026-11-01', 'to_date' => '2026-11-10']))->assertOk()
            ->assertSee('Search a Vehicle ID or IMEI');

        $this->assertSame([], $this->trackingQueries());
    }

    public function test_the_search_text_is_trimmed_cut_to_40_characters_and_cleaned(): void
    {
        $this->vehicleInfo = $this->vehicleCard();

        $this->search(['search' => '   AUTOTEST-TRIM-1   '])->assertOk();
        $this->assertSame('AUTOTEST-TRIM-1', $this->trackingQueries()[0]['vehicleNumber']);
    }

    public function test_a_very_long_search_is_cut_before_it_is_sent(): void
    {
        $this->search(['search' => str_repeat('A', 100)])->assertOk();

        $this->assertSame(str_repeat('A', 40), $this->trackingQueries()[0]['vehicleNumber']);
    }

    public function test_hidden_control_characters_are_removed_from_the_search(): void
    {
        $this->search(['search' => "AUTO\x00TEST\x07-1"])->assertOk();

        $this->assertSame('AUTOTEST-1', $this->trackingQueries()[0]['vehicleNumber']);
    }

    public function test_a_search_sent_as_a_list_is_treated_as_empty_and_does_not_crash(): void
    {
        $this->admin();

        $this->get(route('admin.vehicles.gps') . '?search[]=AUTOTEST-1&search[]=x&from_date[]=2026-11-01')->assertOk()
            ->assertSee('Search a Vehicle ID or IMEI');

        $this->assertSame([], $this->trackingQueries());
    }

    public function test_a_blank_search_asks_nothing(): void
    {
        $this->search(['search' => '    '])->assertOk();

        $this->assertSame([], $this->trackingQueries());
    }

    // ================================================================== ADMIN: trips

    public function test_two_trips_are_found_with_their_times_duration_and_distance_newest_first(): void
    {
        $this->vehicleInfo = $this->vehicleCard();
        $this->history = $this->twoTrips();

        $response = $this->search(['search' => 'AUTOTEST-GPS-1'])->assertOk()->assertSee('2 trips detected');

        $response->assertSeeInOrder(['08:20 AM', '08:25 AM', '5m', '2.2 km', '08:00 AM', '08:04 AM', '4m', '1.1 km'], false);
        $this->assertCount(2, $response->viewData('trips'));
        $this->assertSame(2.2, $response->viewData('trips')[0]['distance_km']);
        $this->assertSame(1.1, $response->viewData('trips')[1]['distance_km']);
        $this->assertSame(5, $response->viewData('trips')[0]['duration_min']);
        $this->assertSame(4, $response->viewData('trips')[1]['duration_min']);
    }

    public function test_the_points_of_a_trip_are_listed_with_their_details(): void
    {
        $this->vehicleInfo = $this->vehicleCard();
        $this->history = [
            $this->point('09:00:00', 6.9000, 41.5),
            $this->point('09:01:00', 6.9010, 38),
        ];

        $this->search(['search' => 'AUTOTEST-GPS-1'])->assertOk()
            ->assertSee('2026-11-10 09:00:00')
            ->assertSee('2026-11-10 09:01:00')
            ->assertSee('41.5')
            ->assertSee('90&deg;', false)
            ->assertSee('1 trips detected');
    }

    public function test_a_stop_of_4_minutes_does_not_end_the_trip_but_5_minutes_does(): void
    {
        $this->vehicleInfo = $this->vehicleCard();

        $this->history = [
            $this->point('08:00:00', 6.90, 40), $this->point('08:02:00', 6.91, 0), $this->point('08:06:00', 6.91, 0),
            $this->point('08:08:00', 6.92, 40), $this->point('08:10:00', 6.93, 40),
        ];
        $four = $this->search(['search' => 'AUTOTEST-GPS-1'])->assertOk();
        $this->assertCount(1, $four->viewData('trips'), 'a stop of 4 minutes is part of the same trip');
    }

    public function test_a_stop_of_exactly_5_minutes_ends_the_trip(): void
    {
        $this->vehicleInfo = $this->vehicleCard();
        $this->history = [
            $this->point('08:00:00', 6.90, 40), $this->point('08:02:00', 6.91, 0), $this->point('08:07:00', 6.91, 0),
            $this->point('08:09:00', 6.92, 40), $this->point('08:10:00', 6.93, 40),
        ];

        $five = $this->search(['search' => 'AUTOTEST-GPS-1'])->assertOk();

        $this->assertCount(2, $five->viewData('trips'));
    }

    public function test_speed_of_2_is_standing_still_and_above_2_is_moving(): void
    {
        $this->vehicleInfo = $this->vehicleCard();

        // 10 minutes at 2.0 km/h in the middle: that is a stop, so two trips.
        $this->history = [
            $this->point('08:00:00', 6.90, 40), $this->point('08:02:00', 6.91, 2.0), $this->point('08:12:00', 6.91, 2.0),
            $this->point('08:14:00', 6.92, 40), $this->point('08:16:00', 6.93, 40),
        ];
        $this->assertCount(2, $this->search(['search' => 'AUTOTEST-GPS-1'])->viewData('trips'));
    }

    public function test_speed_just_above_2_is_still_moving(): void
    {
        $this->vehicleInfo = $this->vehicleCard();
        $this->history = [
            $this->point('08:00:00', 6.90, 40), $this->point('08:02:00', 6.91, 2.1), $this->point('08:12:00', 6.92, 2.1),
            $this->point('08:14:00', 6.93, 40), $this->point('08:16:00', 6.94, 40),
        ];

        $this->assertCount(1, $this->search(['search' => 'AUTOTEST-GPS-1'])->viewData('trips'));
    }

    public function test_a_trip_longer_than_an_hour_is_shown_in_hours_and_minutes(): void
    {
        $this->vehicleInfo = $this->vehicleCard();
        $this->history = [
            $this->point('08:00:00', 6.90, 40), $this->point('08:30:00', 6.95, 40), $this->point('09:05:00', 7.00, 40),
        ];

        $response = $this->search(['search' => 'AUTOTEST-GPS-1'])->assertOk()->assertSee('1h 5m');

        $this->assertSame(65, $response->viewData('trips')[0]['duration_min']);
    }

    public function test_the_distance_follows_the_real_road_between_the_points_not_a_straight_line(): void
    {
        $this->vehicleInfo = $this->vehicleCard();
        // out 0.1 degree north and back again: 22.2 km driven, although the start and the end are the same place.
        $this->history = [
            $this->point('08:00:00', 6.90, 40), $this->point('08:10:00', 7.00, 40), $this->point('08:20:00', 6.90, 40),
        ];

        $trip = $this->search(['search' => 'AUTOTEST-GPS-1'])->assertOk()->viewData('trips')[0];

        $this->assertEqualsWithDelta(22.2, $trip['distance_km'], 0.1);
    }

    public function test_one_single_point_is_not_a_trip(): void
    {
        $this->vehicleInfo = $this->vehicleCard();
        $this->history = [$this->point('08:00:00', 6.90, 40)];

        $this->search(['search' => 'AUTOTEST-GPS-1'])->assertOk()
            ->assertSee('0 trips detected')
            ->assertSee('No distinct trips detected in this range');
    }

    public function test_a_vehicle_with_no_history_says_so_and_has_no_pdf_button(): void
    {
        $this->vehicleInfo = $this->vehicleCard();
        $this->history = [];

        $this->search(['search' => 'AUTOTEST-GPS-1'])->assertOk()
            ->assertSee('Vehicle & Device Summary', false)
            ->assertSee('Search a Vehicle ID or IMEI to view the route on map.')
            ->assertDontSee('Download PDF');
    }

    public function test_the_pdf_button_carries_the_same_search_and_dates(): void
    {
        $this->vehicleInfo = $this->vehicleCard();
        $this->history = $this->twoTrips();

        $content = $this->search(['search' => 'AUTOTEST-GPS-1', 'from_date' => '2026-11-01', 'to_date' => '2026-11-10'])->assertOk()->getContent();

        $this->assertStringContainsString('vehicles/gps/export?search=AUTOTEST-GPS-1&amp;from_date=2026-11-01&amp;to_date=2026-11-10', $content);
    }

    // ================================================================== ADMIN: vehicle summary and location

    public function test_the_current_location_is_shown_with_how_long_ago_it_was(): void
    {
        $this->vehicleInfo = $this->vehicleCard();
        $this->currentLocation = ['latitude' => 6.9271, 'longitude' => 79.8612, 'eventTime' => Carbon::now()->subMinutes(5)->format('Y-m-d\TH:i:s')];

        $this->search(['search' => 'AUTOTEST-GPS-1'])->assertOk()
            ->assertSee('6.9271, 79.8612')
            ->assertSee('data-lat="6.9271"', false)
            ->assertSee('5 minutes ago');
    }

    public function test_no_current_location_says_no_recent_data(): void
    {
        $this->vehicleInfo = $this->vehicleCard();
        $this->currentLocation = null;

        $this->search(['search' => 'AUTOTEST-GPS-1'])->assertOk()->assertSee('No recent data');
    }

    public function test_a_vehicle_without_a_gps_device_shows_none(): void
    {
        $this->vehicleInfo = $this->vehicleCard(['hasGpsDevice' => false, 'imei' => null]);

        $this->search(['search' => 'AUTOTEST-GPS-1'])->assertOk()->assertSee('None');
    }

    public function test_a_vehicle_with_a_device_but_no_imei_shows_linked(): void
    {
        $this->vehicleInfo = $this->vehicleCard(['imei' => null]);

        $this->search(['search' => 'AUTOTEST-GPS-1'])->assertOk()->assertSee('Linked');
    }

    // ================================================================== ADMIN: long histories

    public function test_a_long_history_is_read_page_by_page_until_the_last_page(): void
    {
        $this->vehicleInfo = $this->vehicleCard();
        $this->history = [];
        for ($i = 0; $i < 1000; $i++) {   // exactly two full pages: a third (empty) request ends it
            $this->history[] = ["2026-11-10T" . gmdate('H:i:s', $i * 30), 6.9 + $i * 0.0001, 79.85, 40.0];
        }

        $response = $this->search(['search' => 'AUTOTEST-GPS-1'])->assertOk();

        $pages = array_map(fn ($q) => $q['page'], $this->trackingQueries());
        $this->assertSame(['1', '2', '3'], $pages);
        $this->assertSame(1000, $response->viewData('historyData')->count());
    }

    public function test_a_history_under_one_page_is_read_with_one_request(): void
    {
        $this->vehicleInfo = $this->vehicleCard();
        $this->history = $this->twoTrips();

        $this->search(['search' => 'AUTOTEST-GPS-1'])->assertOk();

        $this->assertCount(1, $this->trackingQueries());
    }

    public function test_reading_stops_after_20_pages_even_if_the_server_never_stops(): void
    {
        $this->vehicleInfo = $this->vehicleCard();
        $this->endless = true;

        $response = $this->search(['search' => 'AUTOTEST-GPS-1'])->assertOk();

        $this->assertCount(20, $this->trackingQueries());
        $this->assertSame(10000, $response->viewData('historyData')->count());
    }

    public function test_the_vehicle_and_current_location_come_from_the_first_page_only(): void
    {
        $this->vehicleInfo = $this->vehicleCard();
        $this->currentLocation = ['latitude' => 6.9271, 'longitude' => 79.8612, 'eventTime' => '2026-11-10T08:00:00'];
        $this->endless = true;

        $response = $this->search(['search' => 'AUTOTEST-GPS-1'])->assertOk();

        $this->assertSame('AUTOTEST-GPS-1', $response->viewData('vehicle')['vehicleNumber']);
        $this->assertSame(6.9271, $response->viewData('currentLocation')['latitude']);
    }

    public function test_if_a_later_page_fails_the_points_already_read_are_kept_and_no_error_is_shown(): void
    {
        $this->vehicleInfo = $this->vehicleCard();
        $this->history = [];
        for ($i = 0; $i < 600; $i++) {
            $this->history[] = ["2026-11-10T" . gmdate('H:i:s', $i * 20), 6.9 + $i * 0.0001, 79.85, 40.0];
        }
        $this->pageStatus[2] = [500, ['message' => 'boom']];

        $response = $this->search(['search' => 'AUTOTEST-GPS-1'])->assertOk();

        $this->assertSame(500, $response->viewData('historyData')->count());
        $this->assertNull($response->viewData('errorMessage'));
        $response->assertDontSee('Could not load tracking data');
    }

    // ================================================================== ADMIN: when the app server says no

    public static function trackingFailures(): array
    {
        return [
            'not found with its own words'  => [404, ['message' => 'No vehicle with plate XYZ.'], 'No vehicle with plate XYZ.'],
            'not found without a message'   => [404, [], 'Vehicle or device not found.'],
            'server error'                  => [500, ['message' => 'boom'], 'Could not load tracking data (status 500). Please try again.'],
            'bad gateway'                   => [502, [], 'Could not load tracking data (status 502). Please try again.'],
            'key refused'                   => [401, [], 'Could not load tracking data (status 401). Please try again.'],
        ];
    }

    #[DataProvider('trackingFailures')]
    public function test_the_person_is_told_what_went_wrong(int $status, array $body, string $expected): void
    {
        $this->pageStatus[1] = [$status, $body];

        $response = $this->search(['search' => 'AUTOTEST-GPS-1'])->assertOk()->assertSee($expected);

        $response->assertDontSee('Vehicle & Device Summary', false);
        $this->assertSame(0, $response->viewData('historyData')->count());
    }

    public function test_a_hiccup_on_the_first_try_is_retried_and_the_data_is_shown(): void
    {
        $this->vehicleInfo = $this->vehicleCard();
        $this->history = $this->twoTrips();
        $this->failFirst = 1;

        $this->search(['search' => 'AUTOTEST-GPS-1'])->assertOk()
            ->assertSee('2 trips detected')
            ->assertDontSee('Could not load tracking data');

        $this->assertCount(2, $this->trackingQueries(), 'one failed try and one good try');
    }

    public function test_when_the_app_server_cannot_be_reached_the_page_still_opens_with_a_message(): void
    {
        $this->trackingDown = true;

        $this->search(['search' => 'AUTOTEST-GPS-1'])->assertOk()
            ->assertSee('Could not reach the tracking server. Please try again.');
    }

    public function test_the_message_from_the_app_server_is_shown_as_text_never_run(): void
    {
        $this->pageStatus[1] = [404, ['message' => '<script>alert(1)</script>']];

        $this->search(['search' => 'AUTOTEST-GPS-1'])->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_names_and_the_search_text_are_shown_as_text_never_run(): void
    {
        $evil = '<script>alert(1)</script>';
        $this->vehicleInfo = $this->vehicleCard(['vehicleNumber' => $evil, 'make' => $evil, 'model' => '<img src=x onerror=alert(2)>', 'customerName' => $evil]);
        $this->history = $this->twoTrips();

        $this->search(['search' => '"><script>alert(3)</script>'])->assertOk()
            ->assertDontSee($evil, false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false)
            ->assertDontSee('"><script>alert(3)', false);
    }

    // ================================================================== ADMIN: address lookup

    public function test_an_address_is_found_from_openstreetmap_when_google_is_not_set_up(): void
    {
        $this->osmAddress = 'Galle Road, Colombo 03';
        $this->admin();

        $this->getJson(route('admin.vehicles.gps.resolve-address', ['lat' => 6.9271, 'lng' => 79.8612]))
            ->assertOk()->assertExactJson(['address' => 'Galle Road, Colombo 03']);

        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'nominatim.openstreetmap.org') && $r['lat'] == 6.9271 && $r['lon'] == 79.8612);
        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), 'maps.googleapis.com'));
    }

    public function test_google_is_asked_first_when_a_key_is_set(): void
    {
        config(['services.google.maps_api_key' => 'test-google-key']);
        $this->googleAddress = 'Google Street, Colombo';
        $this->admin();

        $this->getJson(route('admin.vehicles.gps.resolve-address', ['lat' => 6.9271, 'lng' => 79.8612]))
            ->assertOk()->assertExactJson(['address' => 'Google Street, Colombo']);

        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), 'nominatim.openstreetmap.org'));
    }

    public function test_if_google_has_no_answer_openstreetmap_is_used(): void
    {
        config(['services.google.maps_api_key' => 'test-google-key']);
        $this->googleAddress = null;   // REQUEST_DENIED
        $this->osmAddress = 'Backup Road, Kandy';
        $this->admin();

        $this->getJson(route('admin.vehicles.gps.resolve-address', ['lat' => 7.29, 'lng' => 80.63]))
            ->assertOk()->assertExactJson(['address' => 'Backup Road, Kandy']);
    }

    public function test_if_no_service_answers_the_plain_numbers_are_shown(): void
    {
        $this->osmAddress = null;
        $this->admin();

        $this->getJson(route('admin.vehicles.gps.resolve-address', ['lat' => 6.9271, 'lng' => 79.8612]))
            ->assertOk()->assertExactJson(['address' => '6.9271, 79.8612']);
    }

    public function test_if_the_address_service_is_down_the_page_does_not_crash(): void
    {
        $this->osmDown = true;
        $this->admin();

        $this->getJson(route('admin.vehicles.gps.resolve-address', ['lat' => 6.9271, 'lng' => 79.8612]))
            ->assertOk()->assertExactJson(['address' => '6.9271, 79.8612']);
    }

    public function test_an_address_that_was_found_is_remembered_and_not_asked_again(): void
    {
        $this->osmAddress = 'Remembered Road';
        $this->admin();

        $url = route('admin.vehicles.gps.resolve-address', ['lat' => 6.9271, 'lng' => 79.8612]);
        $this->getJson($url)->assertOk();
        $this->getJson($url)->assertOk()->assertExactJson(['address' => 'Remembered Road']);

        $osmCalls = Http::recorded(fn (ClientRequest $r) => str_contains($r->url(), 'nominatim.openstreetmap.org'))->count();
        $this->assertSame(1, $osmCalls);
    }

    public function test_a_failed_lookup_is_not_remembered(): void
    {
        $this->osmAddress = null;
        $this->admin();
        $url = route('admin.vehicles.gps.resolve-address', ['lat' => 6.9271, 'lng' => 79.8612]);

        $this->getJson($url)->assertExactJson(['address' => '6.9271, 79.8612']);
        $this->osmAddress = 'Now It Works Road';
        $this->getJson($url)->assertExactJson(['address' => 'Now It Works Road']);
    }

    public static function badCoordinates(): array
    {
        return [
            'nothing at all'        => [[]],
            'latitude missing'      => [['lng' => 79.8]],
            'longitude missing'     => [['lat' => 6.9]],
            'words'                 => [['lat' => 'north', 'lng' => 'east']],
            'a list'                => [['lat' => ['6.9'], 'lng' => ['79.8']]],
            'a sql try'             => [['lat' => "6.9'; DROP TABLE x;--", 'lng' => 79.8]],
        ];
    }

    #[DataProvider('badCoordinates')]
    public function test_bad_coordinates_are_refused_and_nothing_is_asked_from_the_address_services(array $query): void
    {
        $this->admin();

        $this->getJson(route('admin.vehicles.gps.resolve-address', $query))->assertStatus(422);

        Http::assertNothingSent();
    }

    // ================================================================== ADMIN: the PDF

    public function test_the_pdf_report_opens_and_is_a_real_pdf(): void
    {
        $this->vehicleInfo = $this->vehicleCard();
        $this->history = $this->twoTrips();
        $this->admin();

        $response = $this->get(route('admin.vehicles.gps.export', ['search' => 'AUTOTEST-GPS-1', 'from_date' => '2026-11-01', 'to_date' => '2026-11-10']));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertMatchesRegularExpression('/^inline; filename="?vehicle_trips_report_\d{4}-\d{2}-\d{2}_\d{6}\.pdf"?$/', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertGreaterThan(1500, strlen($response->getContent()));
    }

    public function test_the_pdf_asks_the_app_server_the_same_way_the_page_does(): void
    {
        $this->vehicleInfo = $this->vehicleCard();
        $this->history = $this->twoTrips();
        $this->admin();

        $this->get(route('admin.vehicles.gps.export', ['search' => self::IMEI, 'from_date' => '2026-11-01', 'to_date' => '2026-11-10']))->assertOk();

        $q = $this->trackingQueries()[0];
        $this->assertSame(self::IMEI, $q['imei']);
        $this->assertArrayNotHasKey('vehicleNumber', $q);
        $this->assertSame('2026-11-01T00:00:00', $q['from']);
        $this->assertSame('2026-11-10T23:59:59', $q['to']);
    }

    public function test_the_pdf_looks_up_an_address_for_the_start_and_the_end_of_every_trip(): void
    {
        $this->vehicleInfo = $this->vehicleCard();
        $this->history = $this->twoTrips();
        $this->admin();

        $this->get(route('admin.vehicles.gps.export', ['search' => 'AUTOTEST-GPS-1']))->assertOk();

        $lookups = Http::recorded(fn (ClientRequest $r) => str_contains($r->url(), 'nominatim.openstreetmap.org'))
            ->map(fn ($pair) => (string) $pair[0]['lat'])->sort()->values()->all();
        // trip 1: 6.9 -> 6.91 ; trip 2: 7.1 -> 7.12
        $this->assertSame(['6.9', '6.91', '7.1', '7.12'], $lookups);
    }

    public function test_the_pdf_without_a_search_is_an_empty_report_not_an_error(): void
    {
        $this->admin();

        $response = $this->get(route('admin.vehicles.gps.export'))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertSame([], $this->trackingQueries());
    }

    public function test_the_pdf_when_the_vehicle_is_not_found_is_still_a_pdf(): void
    {
        $this->pageStatus[1] = [404, ['message' => 'not found']];
        $this->admin();

        $response = $this->get(route('admin.vehicles.gps.export', ['search' => 'NOPE-1']))->assertOk();

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_pdf_when_the_app_server_cannot_be_reached_is_still_a_pdf(): void
    {
        $this->trackingDown = true;
        $this->admin();

        $response = $this->get(route('admin.vehicles.gps.export', ['search' => 'AUTOTEST-GPS-1']))->assertOk();

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_pdf_page_shows_text_as_text_never_runs_it(): void
    {
        $evil = '<script>alert(1)</script>';
        $html = view('admin.vehicles.reports.gps_tracking_pdf', [
            'vehicle'          => $this->vehicleCard(['vehicleNumber' => $evil, 'customerName' => $evil, 'make' => $evil]),
            'tripsWithAddress' => collect([[
                'start_time' => Carbon::parse('2026-11-10 08:00:00'), 'end_time' => Carbon::parse('2026-11-10 08:30:00'), 'duration_min' => 30,
                'start_lat' => 6.9, 'start_lng' => 79.85, 'end_lat' => 6.95, 'end_lng' => 79.86, 'distance_km' => 5.6,
                'start_address' => $evil, 'end_address' => $evil, 'points' => [],
            ]]),
            'fromDate'   => '2026-11-01',
            'toDate'     => '2026-11-10',
            'title'      => 'VEHICLE TRIP & ROUTE HISTORY REPORT',
            'logoBase64' => '',
        ])->render();

        $this->assertStringNotContainsString($evil, $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('1 Trips', $html);
    }

    public function test_a_visitor_who_is_not_signed_in_is_sent_to_login_on_all_three_admin_urls(): void
    {
        $this->get(route('admin.vehicles.gps'))->assertRedirect(route('login'));
        $this->get(route('admin.vehicles.gps.export'))->assertRedirect(route('login'));
        $this->get(route('admin.vehicles.gps.resolve-address', ['lat' => 6.9, 'lng' => 79.8]))->assertRedirect(route('login'));

        Http::assertNothingSent();
    }

    public static function everyOtherRole(): array
    {
        return ['dealer' => ['DEALER'], 'distributor' => ['DISTRIBUTOR'], 'finance' => ['FINANCE'], 'technician' => ['TECHNICIAN'], 'supplier' => ['SUPPLIER']];
    }

    #[DataProvider('everyOtherRole')]
    public function test_only_the_company_admin_may_use_the_admin_gps_pages(string $role): void
    {
        $this->actingAs($this->makeAdmin($role));

        $this->get(route('admin.vehicles.gps', ['search' => 'AUTOTEST-GPS-1']))->assertForbidden();
        $this->get(route('admin.vehicles.gps.export', ['search' => 'AUTOTEST-GPS-1']))->assertForbidden();
        $this->get(route('admin.vehicles.gps.resolve-address', ['lat' => 6.9, 'lng' => 79.8]))->assertForbidden();

        $this->assertSame([], $this->trackingQueries(), 'a refused role must not reach the tracking data');
    }

    // ================================================================== DEALER: live position

    private function dealer(): Dealer
    {
        $n = ++$this->seq;

        return Dealer::create([
            'full_name' => "Autotest gps dealer {$n}", 'dealer_status' => 'retailer', 'region' => 'colombo', 'country' => 'Sri Lanka',
            'contact_email' => "autotest+gps-{$n}@example.test", 'status' => 'active', 'created_by' => 'autotest',
        ]);
    }

    /** A customer of the mobile app (by e-mail) with one vehicle. */
    private function appVehicle(string $plate, ?string $imei, string $email, array $extra = []): string
    {
        $customerId = (string) Str::uuid();
        $vehicleId  = (string) Str::uuid();

        CustomerAd::forceCreate(['customer_id' => $customerId, 'email' => $email, 'phone_number' => null]);
        VehicleAd::forceCreate(array_merge([
            'vehicle_id' => $vehicleId, 'customer_id' => $customerId, 'vehicle_number' => $plate, 'imei' => $imei,
            'has_gps_device' => $imei !== null, 'make' => 'Toyota', 'model' => 'Aqua', 'customer_name' => 'AUTOTEST Owner',
        ], $extra));

        return $vehicleId;
    }

    private function lead(Dealer $dealer, ?string $email, string $contact = 'n/a'): void
    {
        DealerCustomerAd::create(['dealer_id' => $dealer->id, 'name' => 'Lead', 'email' => $email, 'contact' => $contact]);
    }

    /** A dealer who owns two vehicles (plates sorted: AAA-1 then BBB-2) and a stranger who owns a third. Logs in as the dealer. */
    private function dealerWithVehicles(): array
    {
        $dealer = $this->dealer();
        $a = $this->appVehicle('AUTOTEST-AAA-1', self::IMEI, 'own1@example.test');
        $b = $this->appVehicle('AUTOTEST-BBB-2', '864876170000002', 'own2@example.test');
        $this->lead($dealer, 'own1@example.test');
        $this->lead($dealer, 'own2@example.test');
        $other = $this->appVehicle('AUTOTEST-OTHER-9', self::IMEI2, 'stranger@example.test');

        $this->actingAs($this->makeAdmin('DEALER', ['dealer_id' => $dealer->id]));

        return [$dealer, $a, $b, $other];
    }

    public function test_the_dealer_sees_only_its_own_vehicles_and_the_first_one_is_selected(): void
    {
        $this->dealerWithVehicles();

        $response = $this->get(route('dealer.gps-tracking'))->assertOk();

        $response->assertSee('AUTOTEST-AAA-1')->assertSee('AUTOTEST-BBB-2')->assertDontSee('AUTOTEST-OTHER-9')->assertDontSee(self::IMEI2);
        $this->assertSame('AUTOTEST-AAA-1', $response->viewData('selectedVehicle')->vehicle_number);
        $this->assertSame(['AUTOTEST-AAA-1', 'AUTOTEST-BBB-2'], $response->viewData('vehicles')->pluck('vehicle_number')->all());
    }

    public function test_the_dealer_can_pick_another_of_its_own_vehicles(): void
    {
        [, , $b] = $this->dealerWithVehicles();

        $response = $this->get(route('dealer.gps-tracking', ['vehicle_id' => $b]))->assertOk();

        $this->assertSame('AUTOTEST-BBB-2', $response->viewData('selectedVehicle')->vehicle_number);
        $q = $this->trackingQueries()[0];
        $this->assertSame('AUTOTEST-BBB-2', $q['vehicleNumber']);
        $this->assertSame('864876170000002', $q['imei']);
    }

    public function test_asking_for_another_dealers_vehicle_gives_the_dealers_own_vehicle_and_never_asks_for_the_stranger(): void
    {
        [, , , $other] = $this->dealerWithVehicles();

        $response = $this->get(route('dealer.gps-tracking', ['vehicle_id' => $other]))->assertOk();

        $this->assertSame('AUTOTEST-AAA-1', $response->viewData('selectedVehicle')->vehicle_number);
        $response->assertDontSee('AUTOTEST-OTHER-9')->assertDontSee(self::IMEI2);
        foreach ($this->trackingQueries() as $q) {
            $this->assertNotSame(self::IMEI2, $q['imei'] ?? null);
            $this->assertNotSame('AUTOTEST-OTHER-9', $q['vehicleNumber'] ?? null);
        }
    }

    public function test_a_vehicle_id_that_is_junk_or_a_list_gives_the_first_own_vehicle(): void
    {
        $this->dealerWithVehicles();

        $this->get(route('dealer.gps-tracking', ['vehicle_id' => "x' OR '1'='1"]))->assertOk()
            ->assertViewHas('selectedVehicle', fn ($v) => $v->vehicle_number === 'AUTOTEST-AAA-1');
        $this->get(route('dealer.gps-tracking') . '?vehicle_id[]=1')->assertOk()
            ->assertViewHas('selectedVehicle', fn ($v) => $v->vehicle_number === 'AUTOTEST-AAA-1');
    }

    public function test_the_live_position_speed_address_and_time_are_shown(): void
    {
        $this->dealerWithVehicles();
        $this->currentLocation = ['latitude' => 6.9271, 'longitude' => 79.8612, 'speed' => 37, 'heading' => 120, 'eventTime' => Carbon::now()->subMinutes(3)->format('Y-m-d\TH:i:s')];
        $this->osmAddress = 'Galle Road, Colombo 03';

        $response = $this->get(route('dealer.gps-tracking'))->assertOk();

        $response->assertSee('Live GPS Location')
            ->assertSee('37 km/h')
            ->assertSee('Galle Road, Colombo 03')
            ->assertSee('6.92710, 79.86120')
            ->assertDontSee('Default Location');
        $this->assertTrue($response->viewData('locationData')['is_online']);
    }

    public static function noSignal(): array
    {
        return [
            'the app server has no location'  => ['no_location'],
            'the app server says 404'         => ['not_found'],
            'the app server fails'            => ['error'],
            'the app server cannot be reached' => ['down'],
        ];
    }

    #[DataProvider('noSignal')]
    public function test_a_vehicle_with_no_signal_is_shown_as_no_signal_and_not_as_a_fake_position(string $case): void
    {
        $this->dealerWithVehicles();
        match ($case) {
            'no_location' => $this->currentLocation = null,
            'not_found'   => $this->pageStatus[1] = [404, ['message' => 'not found']],
            'error'       => $this->pageStatus[1] = [500, []],
            'down'        => $this->trackingDown = true,
        };

        $response = $this->get(route('dealer.gps-tracking'))->assertOk();

        $response->assertSee('Default Location')
            ->assertSee('No active GPS signal or location updated for this vehicle.')
            ->assertDontSee('Live GPS Location')
            ->assertDontSee('0.00000, 0.00000')   // would put the vehicle in the sea off Africa
            ->assertSee('No location to show on the map yet.');
        $this->assertFalse($response->viewData('locationData')['is_online']);
        $this->assertNull($response->viewData('locationData')['latitude']);
        // The map script must not be left with an empty number ("const vehLat = ;"): that is a JavaScript error and the map dies.
        $this->assertStringNotContainsString('const vehLat = ;', $response->getContent());
        $this->assertStringNotContainsString('const vehLng = ;', $response->getContent());
    }

    public function test_a_location_at_zero_zero_is_not_trusted(): void
    {
        $this->dealerWithVehicles();
        $this->currentLocation = ['latitude' => 0, 'longitude' => 0, 'speed' => 0, 'eventTime' => '2026-11-10T08:00:00'];

        $this->get(route('dealer.gps-tracking'))->assertOk()
            ->assertSee('No active GPS signal or location updated for this vehicle.')
            ->assertDontSee('Live GPS Location');
    }

    public function test_if_the_address_service_is_down_the_dealer_sees_the_numbers_instead(): void
    {
        $this->dealerWithVehicles();
        $this->currentLocation = ['latitude' => 6.9271, 'longitude' => 79.8612, 'speed' => 0, 'eventTime' => '2026-11-10T08:00:00'];
        $this->osmDown = true;

        $this->get(route('dealer.gps-tracking'))->assertOk()->assertSee('Live GPS Location')->assertSee('6.9271, 79.8612');
    }

    public function test_a_dealer_with_no_vehicles_sees_the_empty_message_and_nothing_is_asked(): void
    {
        $dealer = $this->dealer();
        $this->appVehicle('AUTOTEST-OTHER-9', self::IMEI2, 'stranger@example.test');
        $this->actingAs($this->makeAdmin('DEALER', ['dealer_id' => $dealer->id]));

        $this->get(route('dealer.gps-tracking'))->assertOk()
            ->assertSee('No active GPS vehicles assigned to your registered customers.')
            ->assertDontSee('AUTOTEST-OTHER-9');

        $this->assertSame([], $this->trackingQueries());
    }

    public function test_a_lead_with_nothing_to_match_on_never_turns_into_every_vehicle(): void
    {
        $dealer = $this->dealer();
        $this->appVehicle('AUTOTEST-OTHER-9', self::IMEI2, 'stranger@example.test');
        $this->lead($dealer, null, 'n/a');
        $this->actingAs($this->makeAdmin('DEALER', ['dealer_id' => $dealer->id]));

        $this->get(route('dealer.gps-tracking'))->assertOk()->assertDontSee('AUTOTEST-OTHER-9');
    }

    public function test_a_vehicle_without_a_device_is_not_listed_for_tracking(): void
    {
        $dealer = $this->dealer();
        $this->appVehicle('AUTOTEST-NODEVICE-1', null, 'own1@example.test');
        $this->appVehicle('AUTOTEST-EMPTYIMEI-1', '', 'own1@example.test');
        $this->lead($dealer, 'own1@example.test');
        $this->actingAs($this->makeAdmin('DEALER', ['dealer_id' => $dealer->id]));

        $this->get(route('dealer.gps-tracking'))->assertOk()
            ->assertDontSee('AUTOTEST-NODEVICE-1')
            ->assertDontSee('AUTOTEST-EMPTYIMEI-1')
            ->assertSee('No active GPS vehicles');
    }

    public function test_a_dealer_login_with_no_dealer_record_is_sent_back_with_a_message(): void
    {
        $this->actingAs($this->makeAdmin('DEALER', ['dealer_id' => null]));

        $this->from('/somewhere-before')->get(route('dealer.gps-tracking'))
            ->assertRedirect(url('/somewhere-before'))
            ->assertSessionHas('error', 'Dealer profile not found.');

        $this->assertSame([], $this->trackingQueries());
    }

    public function test_the_dealer_page_shows_text_as_text_never_runs_it(): void
    {
        $evil = '<script>alert(1)</script>';
        $dealer = $this->dealer();
        $this->appVehicle($evil, self::IMEI, 'own1@example.test', ['make' => $evil, 'model' => '<img src=x onerror=alert(2)>', 'customer_name' => $evil]);
        $this->lead($dealer, 'own1@example.test');
        $this->actingAs($this->makeAdmin('DEALER', ['dealer_id' => $dealer->id]));
        $this->currentLocation = ['latitude' => 6.9271, 'longitude' => 79.8612, 'speed' => 0, 'eventTime' => '2026-11-10T08:00:00'];
        $this->osmAddress = $evil;

        $this->get(route('dealer.gps-tracking'))->assertOk()
            ->assertDontSee($evil, false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false);
    }

    public static function notDealers(): array
    {
        return ['admin' => ['ADMIN'], 'distributor' => ['DISTRIBUTOR'], 'finance' => ['FINANCE'], 'technician' => ['TECHNICIAN'], 'supplier' => ['SUPPLIER']];
    }

    #[DataProvider('notDealers')]
    public function test_only_a_dealer_login_may_open_the_dealer_page(string $role): void
    {
        $this->actingAs($this->makeAdmin($role));

        $this->get(route('dealer.gps-tracking'))->assertForbidden();

        $this->assertSame([], $this->trackingQueries());
    }

    public function test_a_visitor_who_is_not_signed_in_is_sent_to_login_on_the_dealer_page(): void
    {
        $this->get(route('dealer.gps-tracking'))->assertRedirect(route('login'));
    }
}