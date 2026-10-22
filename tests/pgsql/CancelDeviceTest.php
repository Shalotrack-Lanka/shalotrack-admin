<?php

namespace Tests\Pgsql;

use App\Enums\DeviceStatus;
use App\Models\ActivatedDevice;
use App\Models\AuditLog;
use App\Models\Dealer;
use App\Models\SetupShalotrackDevice;
use App\Models\Sim;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * Admin > Cancel Requests: the Cancel Device page, the SIM attach form and the Cancel SIM page.
 *   - status moves an admin may make (Not Activated -> Activated -> Temporarily Stopped -> Activated), nothing else
 *   - a stop needs a reason; re-activating clears it
 *   - every change is audited and pushed to the app server; a failed push never undoes the saved change
 *   - a spare SIM can be put into a device that has none, once, and only into the right kind of device
 *   - only an ADMIN can touch any of it
 * The app server is faked: nothing leaves the test. Runs in the isolated "autotest" schema; every row is
 * rolled back by DatabaseTransactions.
 */
class CancelDeviceTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    private const SYNC_URL = '/api/internal/setup-devices-sync';

    private ?\App\Models\Admin $admin = null;
    private int $n = 0;
    /** What the faked app server answers: ok | fail (HTTP 500) | down (connection error). */
    private string $api = 'ok';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (HttpRequest $request) {
            return match ($this->api) {
                'fail'  => Http::response(['error' => 'boom'], 500),
                'down'  => throw new ConnectionException('app server unreachable'),
                default => Http::response([], 200),
            };
        });
    }

    // ---------------------------------------------------------------- helpers

    /** One admin per test (a second login mid-test would be logged out, see PaidActivationTest). */
    private function admin(): \App\Models\Admin
    {
        return $this->admin ??= $this->makeAdmin('ADMIN');
    }

    private function digits(int $len): string
    {
        return substr(str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT) . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT), 0, $len);
    }

    private function device(string $status = 'Activated', ?string $sim = 'auto', ?int $dealerId = null, array $extra = []): SetupShalotrackDevice
    {
        $k = ++$this->n;

        return SetupShalotrackDevice::create(array_merge([
            'device_category' => 'AUTOTEST-CAT',
            'imei_number'     => '99900' . $this->digits(7) . str_pad((string) $k, 3, '0', STR_PAD_LEFT),
            'sim_number'      => $sim === 'auto' ? '07' . $this->digits(5) . str_pad((string) $k, 3, '0', STR_PAD_LEFT) : $sim,
            'iccid'           => $sim === null ? null : '8994' . $this->digits(8) . str_pad((string) $k, 3, '0', STR_PAD_LEFT),
            'status'          => $status,
            'dealer_id'       => $dealerId,
        ], $extra));
    }

    /** A spare SIM in the pool. Real SIM sizes: number 10 characters, IMSI 15, ICCID up to 20. */
    private function spareSim(string $status = 'Activated', ?string $iccid = null): Sim
    {
        $k = ++$this->n;

        return Sim::create([
            'sim_number' => '07' . $this->digits(5) . str_pad((string) $k, 3, '0', STR_PAD_LEFT),
            'sim_type'   => 'Dialog',
            'imsi'       => '41200' . $this->digits(7) . str_pad((string) $k, 3, '0', STR_PAD_LEFT),
            'iccid'      => $iccid ?? '8994' . $this->digits(8) . str_pad((string) $k, 3, '0', STR_PAD_LEFT),
            'sim_status' => $status,
        ]);
    }

    private function dealer(string $name = 'Autotest Dealer'): Dealer
    {
        $k = ++$this->n;

        return Dealer::create([
            'full_name'     => $name . ' ' . $k,
            'dealer_status' => 'Authorized Dealer',
            'region'        => 'colombo',
            'country'       => 'Sri Lanka',
            'contact_email' => "autotest+cancel-{$k}-" . substr(md5((string) microtime(true)), 0, 6) . '@example.test',
            'status'        => 'active',
            'created_by'    => 'autotest',
        ]);
    }

    private function bind(SetupShalotrackDevice $device, string $customer = 'Autotest Bound Customer'): ActivatedDevice
    {
        return ActivatedDevice::create([
            'vehicle_id'      => (string) Str::uuid(),
            'customer_id'     => (string) Str::uuid(),
            'customer_name'   => $customer,
            'vehicle_number'  => 'AUTO-C' . ++$this->n,
            'model'           => 'Autotest Model',
            'has_gps_device'  => false,
            'imei_number'     => $device->imei_number,
            'sim_number'      => $device->sim_number,
            'device_category' => $device->device_category,
            'status'          => DeviceStatus::Activated->value,
            'payment_status'  => 'not-Paid',
            'original_activated_at' => now(),
        ]);
    }

    private function change(SetupShalotrackDevice $device, string $status, array $extra = [])
    {
        return $this->actingAs($this->admin())
            ->from(route('admin.cancel_device.index'))
            ->patch(route('admin.cancel_device.update', $device->shdevice_id), array_merge(['status' => $status], $extra));
    }

    private function attach(SetupShalotrackDevice $device, string $simNumber)
    {
        return $this->actingAs($this->admin())
            ->from(route('admin.cancel_device.index'))
            ->patch(route('admin.cancel_device.attach_sim', $device->shdevice_id), ['sim_number' => $simNumber]);
    }

    private function statusAudits(SetupShalotrackDevice $device)
    {
        return AuditLog::where('action', 'device.status_changed')->where('subject_id', (string) $device->shdevice_id)->get();
    }

    private function syncs(): int
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), self::SYNC_URL))->count();
    }

    // ---------------------------------------------------------------- the allowed moves

    public function test_a_not_activated_device_can_be_activated(): void
    {
        $device = $this->device('Not Activated');

        $this->change($device, 'Activated')
            ->assertRedirect(route('admin.cancel_device.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $fresh = $device->fresh();
        $this->assertSame('Activated', $fresh->status);
        $this->assertNull($fresh->cancel_reason);
        $this->assertNull($fresh->canceled_date);
    }

    public function test_stopping_a_device_needs_a_reason_and_records_it(): void
    {
        $device = $this->device('Activated');

        $this->change($device, 'Temporarily Stopped')->assertSessionHasErrors('cancel_reason');
        $this->assertSame('Activated', $device->fresh()->status, 'no reason, no stop');

        $this->change($device, 'Temporarily Stopped', ['cancel_reason' => str_repeat('x', 501)])->assertSessionHasErrors('cancel_reason');
        $this->assertSame('Activated', $device->fresh()->status, 'a reason longer than 500 characters is refused');

        $this->change($device, 'Temporarily Stopped', ['cancel_reason' => 'Customer has not paid'])->assertSessionHasNoErrors();

        $fresh = $device->fresh();
        $this->assertSame('Temporarily Stopped', $fresh->status);
        $this->assertSame('Customer has not paid', $fresh->cancel_reason);
        $this->assertNotNull($fresh->canceled_date);
        $this->assertTrue($fresh->canceled_date->greaterThan(now()->subMinutes(5)), 'the stop time is now');
    }

    public function test_activating_a_stopped_device_clears_the_reason_and_the_date(): void
    {
        $device = $this->device('Temporarily Stopped', 'auto', null, ['cancel_reason' => 'Old reason', 'canceled_date' => now()->subDay()]);

        $this->change($device, 'Activated', ['cancel_reason' => 'ignored text'])->assertSessionHasNoErrors();

        $fresh = $device->fresh();
        $this->assertSame('Activated', $fresh->status);
        $this->assertNull($fresh->cancel_reason);
        $this->assertNull($fresh->canceled_date);
    }

    public function test_a_stop_does_not_move_the_device_to_another_dealer(): void
    {
        $holder = $this->dealer('Holder');
        $other  = $this->dealer('Other');
        $device = $this->device('Activated', 'auto', $holder->id);

        $this->change($device, 'Temporarily Stopped', ['cancel_reason' => 'Test stop', 'dealer_id' => $other->id])->assertSessionHasNoErrors();

        $this->assertSame($holder->id, (int) $device->fresh()->dealer_id, 'the dealer field only matters when activating');
    }

    public function test_an_unknown_dealer_is_refused(): void
    {
        $device = $this->device('Not Activated');

        $this->change($device, 'Activated', ['dealer_id' => 999999999])->assertSessionHasErrors('dealer_id');

        $this->assertSame('Not Activated', $device->fresh()->status);
    }

    // ---------------------------------------------------------------- the refused moves

    public static function moves(): array
    {
        $na = 'Not Activated';
        $ac = 'Activated';
        $ts = 'Temporarily Stopped';
        $as = 'Assigned to Customer';
        $pr = 'Pending Repair';
        $bd = 'Broken Device';

        return [
            'Not Activated -> Temporarily Stopped' => [$na, $ts],
            'Activated -> Not Activated'           => [$ac, $na],
            'Temporarily Stopped -> Not Activated' => [$ts, $na],
            'Assigned to Customer -> Activated'    => [$as, $ac],
            'Assigned to Customer -> Stopped'      => [$as, $ts],
            'Pending Repair -> Activated'          => [$pr, $ac],
            'Pending Repair -> Stopped'            => [$pr, $ts],
            'Broken Device -> Activated'           => [$bd, $ac],
            'Broken Device -> Not Activated'       => [$bd, $na],
        ];
    }

    #[DataProvider('moves')]
    public function test_a_move_the_rules_do_not_allow_is_refused_and_changes_nothing(string $from, string $to): void
    {
        $device = $this->device($from);

        $this->change($device, $to, ['cancel_reason' => 'Some reason'])->assertSessionHasErrors('status');

        $fresh = $device->fresh();
        $this->assertSame($from, $fresh->status);
        $this->assertNull($fresh->canceled_date);
        $this->assertCount(0, $this->statusAudits($device), 'a refused move writes no audit entry');
        $this->assertSame(0, $this->syncs(), 'and nothing is sent to the app server');
    }

    public function test_choosing_the_status_it_already_has_is_refused(): void
    {
        $device = $this->device('Activated');

        $this->change($device, 'Activated')->assertSessionHasErrors('status');

        $this->assertCount(0, $this->statusAudits($device));
    }

    public function test_a_status_the_form_never_offers_is_refused(): void
    {
        $device = $this->device('Activated');

        foreach (['Broken Device', 'Assigned to Customer', 'Pending Repair', 'Deleted', '', 'activated'] as $bad) {
            $this->change($device, $bad)->assertSessionHasErrors('status');
        }

        $this->assertSame('Activated', $device->fresh()->status);
    }

    // ---------------------------------------------------------------- audit and app server

    public function test_a_status_change_is_audited_and_pushed_to_the_app_server(): void
    {
        $device = $this->device('Activated');

        $this->change($device, 'Temporarily Stopped', ['cancel_reason' => 'Lost vehicle'])
            ->assertSessionHas('success')
            ->assertSessionMissing('warning');

        $logs = $this->statusAudits($device);
        $this->assertCount(1, $logs);
        $log = $logs->first();
        $this->assertSame($this->admin()->admin_id, $log->actor_id);
        $this->assertSame($device->imei_number, $log->subject_label);
        $this->assertSame('Activated', $log->changes['status']['from']);
        $this->assertSame('Temporarily Stopped', $log->changes['status']['to']);

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), self::SYNC_URL)
            && $r['imeiNumber'] === $device->imei_number
            && $r['status'] === 'Temporarily Stopped'
            && $r['cancelReason'] === 'Lost vehicle');
    }

    public function test_if_the_app_server_says_no_the_change_is_still_saved_and_the_admin_is_warned(): void
    {
        $this->api = 'fail';
        $device = $this->device('Not Activated');

        $this->change($device, 'Activated')
            ->assertSessionHas('success')
            ->assertSessionHas('warning');

        $this->assertSame('Activated', $device->fresh()->status, 'the local change must stay');
        $this->assertCount(1, $this->statusAudits($device));
    }

    public function test_if_the_app_server_is_unreachable_the_change_is_still_saved_and_the_admin_is_warned(): void
    {
        $this->api = 'down';
        $device = $this->device('Activated');

        $this->change($device, 'Temporarily Stopped', ['cancel_reason' => 'Server down test'])
            ->assertSessionHas('success')
            ->assertSessionHas('warning')
            ->assertSessionHasNoErrors();

        $this->assertSame('Temporarily Stopped', $device->fresh()->status);
    }

    // ---------------------------------------------------------------- the page

    public function test_the_page_lists_in_service_and_not_activated_devices_and_hides_the_rest(): void
    {
        $holder   = $this->dealer('Page Holder');
        $inStock  = $this->device('Not Activated');
        $dealerNA = $this->device('Not Activated', 'auto', $holder->id);
        $live     = $this->device('Activated');
        $stopped  = $this->device('Temporarily Stopped', 'auto', null, ['cancel_reason' => 'Page test', 'canceled_date' => now()]);
        $sold     = $this->device('Activated');
        $this->bind($sold, 'Autotest Page Customer');
        $hidden   = [$this->device('Assigned to Customer'), $this->device('Pending Repair'), $this->device('Broken Device')];

        $page = $this->actingAs($this->admin())->get(route('admin.cancel_device.index'))->assertOk();

        foreach ([$inStock, $dealerNA, $live, $stopped, $sold] as $d) {
            $page->assertSee($d->imei_number);
        }
        foreach ($hidden as $d) {
            $page->assertDontSee($d->imei_number);
        }
        $page->assertSee($holder->full_name);
        $page->assertSee('Autotest Page Customer');
    }

    public function test_the_attach_form_shows_only_where_it_applies(): void
    {
        $noSim   = $this->device('Activated', null);
        $pending = $this->device('Not Activated', null);
        $withSim = $this->device('Activated');
        $this->spareSim();

        $html = $this->actingAs($this->admin())->get(route('admin.cancel_device.index'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'data-attach-sim-form'), 'one form for each device that has no SIM');
        $this->assertStringContainsString(route('admin.cancel_device.attach_sim', $noSim->shdevice_id), $html);
        $this->assertStringContainsString(route('admin.cancel_device.attach_sim', $pending->shdevice_id), $html);
        $this->assertStringNotContainsString(route('admin.cancel_device.attach_sim', $withSim->shdevice_id), $html);
    }

    public function test_with_no_spare_sim_the_page_points_to_add_sim(): void
    {
        $this->device('Activated', null);

        $html = $this->actingAs($this->admin())->get(route('admin.cancel_device.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-no-spare-sim', $html);
        $this->assertStringNotContainsString('data-attach-sim-form', $html);
    }

    public function test_the_two_pdf_reports_download(): void
    {
        $this->device('Not Activated');
        $this->device('Activated');
        $this->actingAs($this->admin());

        $this->get(route('admin.cancel_device.export_not_activated'))
            ->assertOk()->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition');
        $this->get(route('admin.cancel_device.export_activated'))
            ->assertOk()->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition');
    }

    // ---------------------------------------------------------------- attach a spare SIM

    public function test_a_spare_sim_goes_into_a_device_without_one_and_leaves_the_pool(): void
    {
        $device = $this->device('Activated', null);
        $sim    = $this->spareSim();

        $this->attach($device, $sim->sim_number)
            ->assertRedirect(route('admin.cancel_device.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $fresh = $device->fresh();
        $this->assertSame($sim->sim_number, $fresh->sim_number);
        $this->assertSame($sim->iccid, $fresh->iccid);
        $this->assertSame($sim->imsi, $fresh->imsi);
        $this->assertNull(Sim::where('sim_number', $sim->sim_number)->first(), 'the SIM leaves the spare pool');

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), self::SYNC_URL)
            && $r['imeiNumber'] === $device->imei_number && $r['simNumber'] === $sim->sim_number);
    }

    public function test_a_not_activated_device_can_take_a_sim_too(): void
    {
        $device = $this->device('Not Activated', null);
        $sim    = $this->spareSim();

        $this->attach($device, $sim->sim_number)->assertSessionHas('success');

        $this->assertSame($sim->sim_number, $device->fresh()->sim_number);
    }

    public function test_a_device_that_already_has_a_sim_is_left_alone(): void
    {
        $device = $this->device('Activated');
        $had    = $device->sim_number;
        $spare  = $this->spareSim();

        $this->attach($device, $spare->sim_number)->assertSessionHasErrors('sim_number');

        $this->assertSame($had, $device->fresh()->sim_number);
        $this->assertNotNull(Sim::where('sim_number', $spare->sim_number)->first(), 'the spare SIM is not consumed');
    }

    public function test_a_sim_that_is_not_activated_or_not_in_the_pool_is_refused(): void
    {
        $device  = $this->device('Activated', null);
        $notYet  = $this->spareSim('Not Activated');
        $blocked = $this->spareSim('Temporary Blocked');

        $this->attach($device, $notYet->sim_number)->assertSessionHasErrors('sim_number');
        $this->attach($device, $blocked->sim_number)->assertSessionHasErrors('sim_number');
        $this->attach($device, '0700000000')->assertSessionHasErrors('sim_number');

        $this->assertNull($device->fresh()->sim_number);
        $this->assertSame(2, Sim::whereIn('sim_number', [$notYet->sim_number, $blocked->sim_number])->count(), 'no SIM was consumed');
    }

    public function test_a_sim_whose_iccid_is_already_inside_another_device_is_refused(): void
    {
        $other  = $this->device('Activated');
        $device = $this->device('Activated', null);
        $clone  = $this->spareSim('Activated', $other->iccid);

        $this->attach($device, $clone->sim_number)->assertSessionHasErrors('sim_number');

        $this->assertNull($device->fresh()->sim_number);
        $this->assertNotNull(Sim::where('sim_number', $clone->sim_number)->first());
    }

    public function test_a_device_on_a_customer_or_stopped_or_dealer_side_cannot_take_a_sim(): void
    {
        $bound   = $this->device('Activated', null);
        $this->bind($bound);
        $stopped = $this->device('Temporarily Stopped', null);
        $assigned = $this->device('Assigned to Customer', null);
        $broken  = $this->device('Broken Device', null);
        $sim     = $this->spareSim();

        foreach ([$bound, $stopped, $assigned, $broken] as $d) {
            $this->attach($d, $sim->sim_number)->assertSessionHasErrors('sim_number');
            $this->assertNull($d->fresh()->sim_number);
        }

        $this->assertNotNull(Sim::where('sim_number', $sim->sim_number)->first());
    }

    public function test_the_same_spare_sim_cannot_go_into_two_devices(): void
    {
        $a   = $this->device('Activated', null);
        $b   = $this->device('Activated', null);
        $sim = $this->spareSim();

        $this->attach($a, $sim->sim_number)->assertSessionHas('success');
        $this->attach($b, $sim->sim_number)->assertSessionHasErrors('sim_number');

        $this->assertNull($b->fresh()->sim_number);
        $this->assertSame($sim->sim_number, $a->fresh()->sim_number);
    }

    public function test_the_attach_form_needs_a_sim_number(): void
    {
        $device = $this->device('Activated', null);

        $this->actingAs($this->admin())
            ->patch(route('admin.cancel_device.attach_sim', $device->shdevice_id), [])
            ->assertSessionHasErrors('sim_number');
        $this->patch(route('admin.cancel_device.attach_sim', $device->shdevice_id), ['sim_number' => ['x']])
            ->assertSessionHasErrors('sim_number');

        $this->assertNull($device->fresh()->sim_number);
    }

    public function test_a_failed_attach_never_loses_the_sim(): void
    {
        $device = $this->device('Temporarily Stopped', null);
        $sim    = $this->spareSim();

        $this->attach($device, $sim->sim_number)->assertSessionHasErrors('sim_number');

        $this->assertNotNull(Sim::where('sim_number', $sim->sim_number)->first(), 'all or nothing: a refusal leaves the SIM in the pool');
        $this->assertSame(0, $this->syncs());
    }

    public function test_if_the_app_server_is_down_the_sim_attach_is_still_saved_and_the_admin_is_warned(): void
    {
        $this->api = 'down';
        $device = $this->device('Activated', null);
        $sim    = $this->spareSim();

        $this->attach($device, $sim->sim_number)
            ->assertSessionHas('success')
            ->assertSessionHas('warning');

        $this->assertSame($sim->sim_number, $device->fresh()->sim_number);
    }

    // ---------------------------------------------------------------- Cancel SIM page

    public function test_the_cancel_sim_page_lists_sims_and_changes_their_status(): void
    {
        $sim = $this->spareSim('Not Activated');
        $this->actingAs($this->admin());

        $this->getJson(route('admin.cancel_sim.index'))->assertOk()->assertJsonFragment(['sim_number' => $sim->sim_number]);
        $this->get(route('admin.cancel_sim.index'))->assertOk()->assertSee($sim->sim_number);

        foreach (['Activated', 'Temporary Blocked', 'Canceled', 'Not Activated'] as $status) {
            $this->patchJson(route('admin.cancel_sim.update', $sim->id), ['status' => $status])
                ->assertOk()->assertJson(['success' => true, 'sim_status' => $status]);
            $this->assertSame($status, $sim->fresh()->sim_status);
        }
    }

    public function test_the_cancel_sim_page_refuses_an_unknown_status(): void
    {
        $sim = $this->spareSim('Activated');
        $this->actingAs($this->admin());

        foreach (['Deleted', '', 'activated'] as $bad) {
            $this->patchJson(route('admin.cancel_sim.update', $sim->id), ['status' => $bad])->assertStatus(422);
        }

        $this->assertSame('Activated', $sim->fresh()->sim_status);
    }

    // ---------------------------------------------------------------- who may do it

    public static function notAdmins(): array
    {
        return [
            'a dealer'        => ['DEALER'],
            'a distributor'   => ['DISTRIBUTOR'],
            'a finance login' => ['FINANCE'],
            'a technician'    => ['TECHNICIAN'],
            'a supplier'      => ['SUPPLIER'],
        ];
    }

    #[DataProvider('notAdmins')]
    public function test_only_an_admin_can_use_the_cancel_pages(string $role): void
    {
        $device = $this->device('Activated', null);
        $sim    = $this->spareSim();
        $login  = $this->makeAdmin($role);
        $this->actingAs($login);

        $this->get(route('admin.cancel_device.index'))->assertForbidden();
        $this->patch(route('admin.cancel_device.update', $device->shdevice_id), ['status' => 'Temporarily Stopped', 'cancel_reason' => 'x'])->assertForbidden();
        $this->patch(route('admin.cancel_device.attach_sim', $device->shdevice_id), ['sim_number' => $sim->sim_number])->assertForbidden();
        $this->get(route('admin.cancel_device.export_not_activated'))->assertForbidden();
        $this->get(route('admin.cancel_device.export_activated'))->assertForbidden();
        $this->get(route('admin.cancel_sim.index'))->assertForbidden();
        $this->patchJson(route('admin.cancel_sim.update', $sim->id), ['status' => 'Canceled'])->assertForbidden();

        $fresh = $device->fresh();
        $this->assertSame('Activated', $fresh->status);
        $this->assertNull($fresh->sim_number);
        $this->assertSame('Activated', $sim->fresh()->sim_status);
        $this->assertSame(0, $this->syncs(), 'nothing may reach the app server');
    }

    public function test_a_visitor_who_is_not_signed_in_is_sent_to_the_login_page(): void
    {
        $device = $this->device('Activated');

        $this->get(route('admin.cancel_device.index'))->assertRedirect(route('login'));
        $this->patch(route('admin.cancel_device.update', $device->shdevice_id), ['status' => 'Temporarily Stopped', 'cancel_reason' => 'x'])->assertRedirect(route('login'));
        $this->get(route('admin.cancel_sim.index'))->assertRedirect(route('login'));

        $this->assertSame('Activated', $device->fresh()->status);
    }

    // ---------------------------------------------------------------- bad ids

    /** May reveal a bug: the id column is a number, so text in the address could reach the database and give a 500. */
    public function test_an_unknown_or_malformed_device_id_is_a_404(): void
    {
        $this->actingAs($this->admin());

        $this->patch(route('admin.cancel_device.update', 999999999), ['status' => 'Activated'])->assertNotFound();
        $this->patch(route('admin.cancel_device.update', 'abc'), ['status' => 'Activated'])->assertNotFound();
        $this->patch(route('admin.cancel_device.attach_sim', 'abc'), ['sim_number' => '0700000000'])->assertNotFound();
    }

    /** May reveal the same bug on the SIM page. */
    public function test_an_unknown_or_malformed_sim_id_is_a_404(): void
    {
        $this->actingAs($this->admin());

        $this->patchJson(route('admin.cancel_sim.update', 999999999), ['status' => 'Canceled'])->assertNotFound();
        $this->patchJson(route('admin.cancel_sim.update', 'abc'), ['status' => 'Canceled'])->assertNotFound();
    }
}