<?php

namespace Tests\Pgsql;

use App\Models\CustomerAd;
use App\Models\Dealer;
use App\Models\DealerCustomerAd;
use App\Models\VehicleAd;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * DEVICE COMMANDS (the Command Center), on real PostgreSQL (autotest schema).
 *
 * A command goes from a person in the portal to the gateway and then to a real vehicle (for example
 * "relay_on", "reset"). So the rules that matter most are WHO may command WHICH device:
 *   - ADMIN: any device, may add parameters, may use every command.
 *   - DEALER: only devices of the customers the dealer registered (matched by e-mail or phone), plain commands only.
 *   - DISTRIBUTOR: devices of its own customers and its dealers' customers, plain commands only.
 *   - everyone else: nothing.
 *
 * The gateway and the app server are FAKED (nothing real is called). The tests prove what we send to the gateway,
 * what the person sees when the gateway says yes / offline / error / is down, and what lands in the audit log.
 * ONE login per test (a second login mid-test is logged out by AuthenticateSession). Everything is rolled back.
 */
class DeviceCommandTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    private const GATEWAY = 'http://gateway.test:8001';
    private const API     = 'https://api.test';
    private const IMEI_A  = '864876170981409';
    private const IMEI_B  = '868159941856606';

    /** What the fake gateway answers to a command: ok | offline | error | error_no_message | down */
    private string $gateway = 'ok';
    /** Devices the fake gateway reports as connected right now. */
    private array $connected = [];
    private bool $gatewayListDown = false;
    /** What the fake app server answers to the command history: ok | fail | down */
    private string $history = 'ok';

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'services.gateway.command_url'     => self::GATEWAY,
            'services.shalotrack_api.base_url' => self::API,
            'services.shalotrack_api.sync_key' => 'sync-key-for-tests',
        ]);

        Http::fake(function (ClientRequest $request) {
            $url = $request->url();

            if (str_starts_with($url, self::GATEWAY . '/command')) {
                return match ($this->gateway) {
                    'offline'          => Http::response(['error' => 'device offline'], 503),
                    'error'            => Http::response(['error' => 'Bad command for this device'], 500),
                    'error_no_message' => Http::response([], 502),
                    'down'             => throw new ConnectionException('gateway is down'),
                    default            => Http::response(['success' => true], 200),
                };
            }

            if (str_starts_with($url, self::GATEWAY . '/devices')) {
                if ($this->gatewayListDown) {
                    throw new ConnectionException('gateway is down');
                }

                return Http::response(['connected_devices' => $this->connected], 200);
            }

            if (str_starts_with($url, self::API . '/api/internal/command-history')) {
                return match ($this->history) {
                    'fail'  => Http::response([], 500),
                    'down'  => throw new ConnectionException('api is down'),
                    default => Http::response(['data' => ['history' => [['command' => 'status']], 'count' => 1]], 200),
                };
            }

            return Http::response([], 404);
        });
    }

    // ------------------------------------------------------------------ helpers

    private function dealer(string $status = 'retailer', ?Dealer $distributor = null, string $state = 'active'): Dealer
    {
        $n = ++$this->seq;

        return Dealer::create([
            'full_name'      => "Autotest {$status} {$n}",
            'dealer_status'  => $status,
            'distributor_id' => $distributor?->id,
            'region'         => 'colombo',
            'country'        => 'Sri Lanka',
            'contact_email'  => "autotest+cmd-{$n}@example.test",
            'status'         => $state,
            'created_by'     => 'autotest',
        ]);
    }

    /** A customer of the mobile app with one vehicle carrying this device. Returns the vehicle id. */
    private function vehicle(string $imei, string $plate, ?string $email = null, ?string $phone = null): string
    {
        $customerId = (string) Str::uuid();
        $vehicleId  = (string) Str::uuid();

        CustomerAd::forceCreate(['customer_id' => $customerId, 'email' => $email, 'phone_number' => $phone]);
        VehicleAd::forceCreate([
            'vehicle_id' => $vehicleId, 'customer_id' => $customerId, 'vehicle_number' => $plate,
            'imei' => $imei, 'has_gps_device' => true,
        ]);

        return $vehicleId;
    }

    /** A customer lead registered by a dealer or distributor. */
    private function lead(Dealer $owner, ?string $email, ?string $contact = 'n/a'): DealerCustomerAd
    {
        return DealerCustomerAd::create(['dealer_id' => $owner->id, 'name' => 'Lead', 'email' => $email, 'contact' => $contact ?? 'n/a']);
    }

    /** A dealer who owns IMEI_A (lead e-mail matches the vehicle owner) and a stranger's vehicle IMEI_B. Logs in as the dealer. */
    private function dealerWithOwnDevice(): array
    {
        $dealer = $this->dealer();
        $own    = $this->vehicle(self::IMEI_A, 'OWN-1111', 'owner@example.test');
        $this->lead($dealer, 'owner@example.test');
        $other  = $this->vehicle(self::IMEI_B, 'OTHER-2222', 'stranger@example.test', '0771234567');

        $this->actingAs($this->makeAdmin('DEALER', ['dealer_id' => $dealer->id]));

        return [$dealer, $own, $other];
    }

    /** A distributor with one dealer under it who own devices; logs in as the distributor. */
    private function distributorNetwork(): array
    {
        $distributor = $this->dealer('distributor');
        $dealer      = $this->dealer('retailer', $distributor);
        $this->lead($distributor, 'direct@example.test');
        $this->lead($dealer, 'viaDealer@example.test');

        $direct  = $this->vehicle('864876170000001', 'DIRECT-1', 'direct@example.test');
        $viaDeal = $this->vehicle('864876170000002', 'VIADEALER-2', 'viadealer@example.test');

        $this->actingAs($this->makeAdmin('DISTRIBUTOR', ['dealer_id' => $distributor->id]));

        return [$distributor, $dealer, $direct, $viaDeal];
    }

    private function commandsSent(): int
    {
        return Http::recorded(fn (ClientRequest $r) => str_starts_with($r->url(), self::GATEWAY . '/command'))->count();
    }

    private function audit(string $action, ?string $subjectId = null)
    {
        $q = DB::table('audit_logs')->where('action', $action);
        if ($subjectId !== null) {
            $q->where('subject_id', $subjectId);
        }

        return $q->get();
    }

    private function send(string $routeName, array $body)
    {
        return $this->postJson(route($routeName), $body);
    }

    // ------------------------------------------------------------------ ADMIN

    public function test_admin_sends_a_command_and_the_gateway_gets_exactly_that(): void
    {
        $this->actingAs($this->makeAdmin('ADMIN'));

        $this->send('admin.vehicles.device-commands.send', ['imei' => self::IMEI_A, 'command' => 'status'])
            ->assertOk()
            ->assertJson(['success' => true, 'message' => "Command 'status' sent to device successfully."]);

        Http::assertSent(fn (ClientRequest $r) => $r->url() === self::GATEWAY . '/command'
            && $r->method() === 'POST'
            && $r['imei'] === self::IMEI_A
            && $r['command'] === 'status'
            && $r['params'] === []);

        $this->assertSame(1, $this->commandsSent());
        $this->assertCount(0, $this->audit('device.command_sent'), 'Company staff commands are not part of the dealer audit trail.');
    }

    public function test_admin_can_command_a_device_that_no_customer_owns_and_can_add_parameters(): void
    {
        $this->actingAs($this->makeAdmin('ADMIN'));

        $this->send('admin.vehicles.device-commands.send', [
            'imei' => self::IMEI_B, 'command' => 'url', 'params' => ['host' => 'gps.example.test', 'port' => 7018],
        ])->assertOk()->assertJson(['success' => true]);

        Http::assertSent(fn (ClientRequest $r) => $r->url() === self::GATEWAY . '/command'
            && $r['command'] === 'url'
            && $r['params'] === ['host' => 'gps.example.test', 'port' => 7018]);
    }

    public static function badCommandBodies(): array
    {
        return [
            'imei too short'         => [['imei' => '86487617098140', 'command' => 'status'], 'imei'],
            'imei too long'          => [['imei' => '8648761709814091', 'command' => 'status'], 'imei'],
            'imei with letters'      => [['imei' => 'ABCDEFGHIJKLMNO', 'command' => 'status'], 'imei'],
            'imei with spaces'       => [['imei' => '864 876 170 98 1409', 'command' => 'status'], 'imei'],
            'imei missing'           => [['command' => 'status'], 'imei'],
            'command missing'        => [['imei' => self::IMEI_A], 'command'],
            'command unknown'        => [['imei' => self::IMEI_A, 'command' => 'format_disk'], 'command'],
            'command wrong case'     => [['imei' => self::IMEI_A, 'command' => 'STATUS'], 'command'],
            'command with a comma'   => [['imei' => self::IMEI_A, 'command' => 'status,url,reset'], 'command'],
            'params not a list'      => [['imei' => self::IMEI_A, 'command' => 'status', 'params' => 'rm -rf'], 'params'],
        ];
    }

    #[DataProvider('badCommandBodies')]
    public function test_a_bad_command_is_refused_before_anything_reaches_the_gateway(array $body, string $badField): void
    {
        $this->actingAs($this->makeAdmin('ADMIN'));

        $this->send('admin.vehicles.device-commands.send', $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors($badField);

        $this->assertSame(0, $this->commandsSent());
    }

    // ------------------------------------------------------------------ what the person sees when the gateway answers

    public static function gatewayAnswers(): array
    {
        return [
            'gateway says ok'               => ['ok', 200, true, "Command 'status' sent to device successfully.", 'delivered'],
            'device is offline (503)'       => ['offline', 503, false, 'Device is currently offline.', 'device_offline'],
            'gateway error with a message'  => ['error', 500, false, 'Bad command for this device', 'failed'],
            'gateway error with no message' => ['error_no_message', 502, false, 'Command could not be delivered.', 'failed'],
            'gateway is down'               => ['down', 503, false, 'Gateway is temporarily unavailable. Please try again.', 'gateway_unavailable'],
        ];
    }

    #[DataProvider('gatewayAnswers')]
    public function test_the_person_is_told_the_truth_and_a_dealer_command_is_audited(string $mode, int $status, bool $success, string $message, string $auditResult): void
    {
        $this->gateway = $mode;
        $this->dealerWithOwnDevice();

        $this->send('dealer.device-commands.send', ['imei' => self::IMEI_A, 'command' => 'status'])
            ->assertStatus($status)
            ->assertJson(['success' => $success, 'message' => $message]);

        $rows = $this->audit('device.command_sent', self::IMEI_A);
        $this->assertCount(1, $rows, 'Every dealer command is on the audit trail, delivered or not.');
        $this->assertSame('DEALER', $rows[0]->actor_role);
        $this->assertStringContainsString($auditResult, (string) $rows[0]->meta);
        $this->assertStringContainsString('status', (string) $rows[0]->meta);
    }

    // ------------------------------------------------------------------ DEALER: whose devices

    public function test_a_dealer_commands_own_device_but_cannot_add_parameters_or_use_admin_only_commands(): void
    {
        $this->dealerWithOwnDevice();

        // Parameters are thrown away: a hand-made request cannot repoint a customer's device.
        $this->send('dealer.device-commands.send', [
            'imei' => self::IMEI_A, 'command' => 'reset', 'params' => ['host' => 'evil.example.test'],
        ])->assertOk()->assertJson(['success' => true]);

        Http::assertSent(fn (ClientRequest $r) => $r->url() === self::GATEWAY . '/command'
            && $r['command'] === 'reset' && $r['params'] === []);

        $this->assertSame(1, $this->commandsSent());
    }

    public static function adminOnlyCommands(): array
    {
        $out = [];
        foreach (['DEALER', 'DISTRIBUTOR'] as $role) {
            foreach (['url', 'fence_circle', 'sos_add', 'distance'] as $command) {
                $out["$role may not use $command"] = [$role, $command];
            }
        }

        return $out;
    }

    #[DataProvider('adminOnlyCommands')]
    public function test_commands_that_can_rewrite_a_device_are_for_the_company_only(string $role, string $command): void
    {
        if ($role === 'DEALER') {
            $this->dealerWithOwnDevice();
            $route = 'dealer.device-commands.send';
            $imei  = self::IMEI_A;
        } else {
            $this->distributorNetwork();
            $route = 'distributor.device-commands.send';
            $imei  = '864876170000001';
        }

        $this->send($route, ['imei' => $imei, 'command' => $command])
            ->assertStatus(422)
            ->assertJsonValidationErrors('command');

        $this->assertSame(0, $this->commandsSent());
    }

    public function test_a_dealer_cannot_command_someone_elses_device_and_the_attempt_is_audited(): void
    {
        $this->dealerWithOwnDevice();

        $this->send('dealer.device-commands.send', ['imei' => self::IMEI_B, 'command' => 'reset'])
            ->assertForbidden()
            ->assertJson(['success' => false, 'message' => 'You do not have access to that device.']);

        $this->assertSame(0, $this->commandsSent(), 'The gateway must never be called for a device the dealer does not own.');

        $denied = $this->audit('device.command_denied', self::IMEI_B);
        $this->assertCount(1, $denied);
        $this->assertSame('DEALER', $denied[0]->actor_role);
        $this->assertCount(0, $this->audit('device.command_sent'));
    }

    public function test_one_wrong_digit_is_not_the_dealers_device(): void
    {
        $this->dealerWithOwnDevice();

        $this->send('dealer.device-commands.send', ['imei' => '864876170981408', 'command' => 'status'])->assertForbidden();
        $this->assertSame(0, $this->commandsSent());
    }

    public static function phoneWrittenInDifferentWays(): array
    {
        return [
            'with +94 and spaces' => ['+94 77 123 4567'],
            'with 0 and dashes'   => ['077-123-4567'],
            'without the 0'       => ['771234567'],
            'with brackets'       => ['(0094) 77 1234567'],
        ];
    }

    #[DataProvider('phoneWrittenInDifferentWays')]
    public function test_a_lead_phone_matches_the_customer_whatever_way_it_is_written(string $leadPhone): void
    {
        $dealer = $this->dealer();
        $this->vehicle(self::IMEI_A, 'PHONE-1', null, '0771234567');
        $this->lead($dealer, null, $leadPhone);
        $this->actingAs($this->makeAdmin('DEALER', ['dealer_id' => $dealer->id]));

        $this->send('dealer.device-commands.send', ['imei' => self::IMEI_A, 'command' => 'status'])
            ->assertOk()->assertJson(['success' => true]);
    }

    public function test_a_lead_email_matches_whatever_the_capital_letters_and_spaces(): void
    {
        $dealer = $this->dealer();
        $this->vehicle(self::IMEI_A, 'MAIL-1', 'owner@example.test');
        $this->lead($dealer, '  Owner@Example.TEST ');
        $this->actingAs($this->makeAdmin('DEALER', ['dealer_id' => $dealer->id]));

        $this->send('dealer.device-commands.send', ['imei' => self::IMEI_A, 'command' => 'status'])
            ->assertOk()->assertJson(['success' => true]);
    }

    public static function leadsThatMatchNobody(): array
    {
        return [
            'text instead of a phone' => ['n/a'],
            'too short for a phone'   => ['12345'],
            'only dashes'             => ['--'],
            'only zeros (8 digits)'   => ['00000000'],
        ];
    }

    #[DataProvider('leadsThatMatchNobody')]
    public function test_a_junk_lead_never_turns_into_every_customer(string $contact): void
    {
        $dealer = $this->dealer();
        $this->vehicle(self::IMEI_B, 'STRANGER-1', 'stranger@example.test', '0771234567');
        $this->lead($dealer, null, $contact);
        $this->actingAs($this->makeAdmin('DEALER', ['dealer_id' => $dealer->id]));

        $this->send('dealer.device-commands.send', ['imei' => self::IMEI_B, 'command' => 'status'])->assertForbidden();
        $this->assertSame(0, $this->commandsSent());
    }

    public function test_a_dealer_with_no_customers_controls_nothing_and_a_removed_lead_stops_working(): void
    {
        $dealer = $this->dealer();
        $this->vehicle(self::IMEI_A, 'GONE-1', 'owner@example.test');
        $lead = $this->lead($dealer, 'owner@example.test');
        $this->actingAs($this->makeAdmin('DEALER', ['dealer_id' => $dealer->id]));

        $this->send('dealer.device-commands.send', ['imei' => self::IMEI_A, 'command' => 'status'])->assertOk();

        $lead->delete();   // the dealer removes the customer (soft delete)

        $this->send('dealer.device-commands.send', ['imei' => self::IMEI_A, 'command' => 'status'])->assertForbidden();
        $this->assertSame(1, $this->commandsSent());
    }

    public function test_a_dealer_login_with_no_dealer_record_controls_nothing(): void
    {
        $this->vehicle(self::IMEI_A, 'NOBODY-1', 'owner@example.test');
        $this->actingAs($this->makeAdmin('DEALER'));   // no dealer_id

        $this->send('dealer.device-commands.send', ['imei' => self::IMEI_A, 'command' => 'status'])->assertForbidden();
        $this->get(route('dealer.device-commands'))->assertRedirect();
        $this->assertSame(0, $this->commandsSent());
    }

    public function test_a_dealer_cannot_see_the_status_or_history_of_another_dealers_device(): void
    {
        [, $own, $other] = $this->dealerWithOwnDevice();
        $this->connected = [['imei' => self::IMEI_A, 'last_seen' => '2026-10-23 10:00:00', 'ip' => '10.0.0.5']];

        $this->getJson(route('dealer.device-commands.status', self::IMEI_B))->assertForbidden();
        $this->getJson(route('dealer.device-commands.history', $other))->assertForbidden();
        $this->getJson(route('dealer.device-commands.history', 'not-a-uuid'))->assertNotFound();   // a malformed id never reaches the controller or the database

        $this->getJson(route('dealer.device-commands.status', self::IMEI_A))
            ->assertOk()->assertJson(['online' => true, 'ip' => '10.0.0.5']);
        $this->getJson(route('dealer.device-commands.history', $own))->assertOk();
    }

    // ------------------------------------------------------------------ status and history (admin)

    public function test_status_says_online_or_offline_from_the_gateway_list_and_never_crashes_when_it_is_down(): void
    {
        $this->actingAs($this->makeAdmin('ADMIN'));
        $this->connected = [['imei' => self::IMEI_A, 'last_seen' => '2026-10-23 10:00:00', 'ip' => '10.0.0.5']];

        $this->getJson(route('admin.vehicles.device-commands.status', self::IMEI_A))
            ->assertOk()->assertJson(['online' => true, 'last_seen' => '2026-10-23 10:00:00', 'ip' => '10.0.0.5']);

        $this->getJson(route('admin.vehicles.device-commands.status', self::IMEI_B))
            ->assertOk()->assertJson(['online' => false, 'last_seen' => null]);

        $this->gatewayListDown = true;
        $this->getJson(route('admin.vehicles.device-commands.status', self::IMEI_A))
            ->assertOk()->assertJson(['online' => false, 'last_seen' => null]);
    }

    public function test_history_is_fetched_from_the_app_server_with_the_secret_key_and_fails_softly(): void
    {
        $this->actingAs($this->makeAdmin('ADMIN'));
        $vehicleId = (string) Str::uuid();

        $this->getJson(route('admin.vehicles.device-commands.history', $vehicleId) . '?limit=5')
            ->assertOk()->assertJson(['count' => 1]);

        Http::assertSent(fn (ClientRequest $r) => str_starts_with($r->url(), self::API . '/api/internal/command-history')
            && $r->hasHeader('X-Admin-Sync-Key', 'sync-key-for-tests')
            && $r['vehicleId'] === $vehicleId
            && (string) $r['limit'] === '5');

        $this->history = 'fail';
        $this->getJson(route('admin.vehicles.device-commands.history', $vehicleId))
            ->assertOk()->assertExactJson(['history' => [], 'count' => 0]);

        $this->history = 'down';
        $this->getJson(route('admin.vehicles.device-commands.history', $vehicleId))
            ->assertOk()->assertExactJson(['history' => [], 'count' => 0]);
    }

    // ------------------------------------------------------------------ DISTRIBUTOR

    public function test_a_distributor_commands_its_own_and_its_dealers_customers_only(): void
    {
        $this->distributorNetwork();

        // Another distributor's network, a dealer with no distributor, and a customer nobody registered.
        $otherDistributor = $this->dealer('distributor');
        $otherDealer      = $this->dealer('retailer', $otherDistributor);
        $this->lead($otherDealer, 'rival@example.test');
        $this->vehicle('864876170000003', 'RIVAL-3', 'rival@example.test');
        $direct = $this->dealer('retailer');
        $this->lead($direct, 'direct-dealer@example.test');
        $this->vehicle('864876170000004', 'DIRECTDEALER-4', 'direct-dealer@example.test');
        $this->vehicle('864876170000005', 'NOBODY-5', 'nobody@example.test');

        foreach (['864876170000001', '864876170000002'] as $mine) {
            $this->send('distributor.device-commands.send', ['imei' => $mine, 'command' => 'status'])
                ->assertOk()->assertJson(['success' => true]);
        }
        $this->assertSame(2, $this->commandsSent());

        foreach (['864876170000003', '864876170000004', '864876170000005'] as $notMine) {
            $this->send('distributor.device-commands.send', ['imei' => $notMine, 'command' => 'status'])->assertForbidden();
        }
        $this->assertSame(2, $this->commandsSent(), 'No command may reach the gateway for a device outside the network.');

        $this->assertCount(2, $this->audit('device.command_sent'));
        $this->assertCount(3, $this->audit('device.command_denied'));
    }

    public function test_the_distributor_page_lists_only_the_network_vehicles(): void
    {
        $this->distributorNetwork();
        $otherDealer = $this->dealer('retailer', $this->dealer('distributor'));
        $this->lead($otherDealer, 'rival@example.test');
        $this->vehicle('864876170000003', 'RIVAL-3', 'rival@example.test');

        $this->get(route('distributor.device-commands'))
            ->assertOk()
            ->assertSee('DIRECT-1')
            ->assertSee('VIADEALER-2')
            ->assertDontSee('RIVAL-3');
    }

    public function test_a_distributor_login_that_is_not_an_active_distributor_controls_nothing(): void
    {
        $inactive = $this->dealer('distributor', null, 'inactive');
        $this->lead($inactive, 'owner@example.test');
        $this->vehicle(self::IMEI_A, 'INACTIVE-1', 'owner@example.test');
        $this->actingAs($this->makeAdmin('DISTRIBUTOR', ['dealer_id' => $inactive->id]));

        $this->send('distributor.device-commands.send', ['imei' => self::IMEI_A, 'command' => 'status'])->assertForbidden();
        $this->get(route('distributor.device-commands'))->assertForbidden();
        $this->assertSame(0, $this->commandsSent());
    }

    // ------------------------------------------------------------------ the pages

    public function test_the_admin_page_lists_every_vehicle_with_a_device_and_shows_the_gateway_state(): void
    {
        $this->actingAs($this->makeAdmin('ADMIN'));
        $this->vehicle(self::IMEI_A, 'ADMIN-AAA', 'a@example.test');
        $this->vehicle(self::IMEI_B, 'ADMIN-BBB', 'b@example.test');
        $noDevice = (string) Str::uuid();
        VehicleAd::forceCreate(['vehicle_id' => $noDevice, 'customer_id' => null, 'vehicle_number' => 'NODEVICE-9', 'imei' => '']);
        $this->connected = [['imei' => self::IMEI_A, 'last_seen' => '2026-10-23 10:00:00']];

        $this->get(route('admin.vehicles.device-commands'))
            ->assertOk()
            ->assertSee('ADMIN-AAA')
            ->assertSee('ADMIN-BBB')
            ->assertDontSee('NODEVICE-9')
            ->assertSee('Gateway Online');

        $this->gatewayListDown = true;
        $this->get(route('admin.vehicles.device-commands'))
            ->assertOk()
            ->assertSee('ADMIN-AAA')
            ->assertSee('Gateway Offline');
    }

    public function test_the_dealer_page_lists_only_the_dealers_own_vehicles_and_still_opens_when_the_gateway_is_down(): void
    {
        $this->dealerWithOwnDevice();
        $this->gatewayListDown = true;

        $this->get(route('dealer.device-commands'))
            ->assertOk()
            ->assertSee('OWN-1111')
            ->assertDontSee('OTHER-2222')
            ->assertSee('Gateway Offline');
    }

    public function test_the_company_login_opening_the_dealer_page_is_sent_back_with_a_message_not_a_crash(): void
    {
        $this->actingAs($this->makeAdmin('ADMIN'));

        $this->get(route('dealer.device-commands'))
            ->assertRedirect()
            ->assertSessionHas('error', 'Dealer profile not found.');
    }
}