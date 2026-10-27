<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\Sim;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * SIM INTAKE (Add SIM page), on real PostgreSQL.
 *
 * A SIM card enters the SIM pool here. Later it is picked for a device and leaves the pool.
 * Rules in simple words:
 *   - sim_number is exactly 10 digits, imsi exactly 15, iccid 19 or 20. None of the three may already exist.
 *   - A SIM is either Activated (can be put in a device) or Not Activated (waiting for the carrier).
 *   - An admin can flip the status. Nothing else about a SIM can be changed from this page.
 *   - The two PDF lists show only SIMs of their own status.
 *   - Only an ADMIN may use any of it.
 *
 * (The barcode "add a SIM on the spot" form is tested in DeviceIntakeTest. The Excel upload is in IntakeImportsTest.)
 */
class SimIntakeTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData;

    private ?Admin $admin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakeIntakeApi();
    }

    private function admin(): Admin
    {
        return $this->admin ??= $this->makeAdmin('ADMIN');
    }

    private function addSim(array $form)
    {
        return $this->actingAs($this->admin())->post(route('admin.stock.sim.store'), $form);
    }

    private function goodForm(array $overrides = []): array
    {
        return array_merge($this->simIdentity(), ['sim_type' => 'Dialog', 'sim_status' => 'Activated'], $overrides);
    }

    // ------------------------------------------------------------------ adding

    public function test_a_sim_is_added_and_the_page_says_so(): void
    {
        $form = $this->goodForm();

        $this->addSim($form)->assertRedirect()->assertSessionHas('success', 'SIM Product Registered Successfully!');

        $sim = Sim::where('iccid', $form['iccid'])->sole();
        $this->assertSame($form['sim_number'], $sim->sim_number);
        $this->assertSame($form['imsi'], $sim->imsi);
        $this->assertSame('Dialog', $sim->sim_type);
        $this->assertSame('Activated', $sim->sim_status);
        $this->assertFalse($sim->activation_required, 'when the box is not ticked it must be saved as false');
    }

    public function test_the_activation_required_box_is_saved_as_ticked_or_not(): void
    {
        $ticked = $this->goodForm(['activation_required' => '1']);
        $notTicked = $this->goodForm();

        $this->addSim($ticked)->assertSessionHasNoErrors();
        $this->addSim($notTicked)->assertSessionHasNoErrors();

        $this->assertTrue(Sim::where('iccid', $ticked['iccid'])->sole()->activation_required);
        $this->assertFalse(Sim::where('iccid', $notTicked['iccid'])->sole()->activation_required);
    }

    public function test_both_statuses_are_accepted_and_nothing_else(): void
    {
        $this->addSim($this->goodForm(['sim_status' => 'Not Activated']))->assertSessionHasNoErrors();
        $this->addSim($this->goodForm(['sim_status' => 'Activated']))->assertSessionHasNoErrors();
        $this->addSim($this->goodForm(['sim_status' => 'activated']))->assertSessionHasErrors('sim_status');
        $this->addSim($this->goodForm(['sim_status' => 'Cancelled']))->assertSessionHasErrors('sim_status');
    }

    public static function badSimForms(): array
    {
        return [
            'type missing'            => [['sim_type' => '__missing__'], 'sim_type'],
            'type is a list'          => [['sim_type' => ['Dialog']], 'sim_type'],
            'type over 255 letters'   => [['sim_type' => '__LONG__'], 'sim_type'],
            'number missing'          => [['sim_number' => '__missing__'], 'sim_number'],
            'number 9 digits'         => [['sim_number' => '070123456'], 'sim_number'],
            'number 11 digits'        => [['sim_number' => '07012345678'], 'sim_number'],
            'number has a letter'     => [['sim_number' => '070123456A'], 'sim_number'],
            'number has a space'      => [['sim_number' => '070 123456'], 'sim_number'],
            'number already used'     => [['sim_number' => '{taken_number}'], 'sim_number'],
            'imsi missing'            => [['imsi' => '__missing__'], 'imsi'],
            'imsi 14 digits'          => [['imsi' => '41299000000001'], 'imsi'],
            'imsi 16 digits'          => [['imsi' => '4129900000000001'], 'imsi'],
            'imsi has a letter'       => [['imsi' => '41299000000000A'], 'imsi'],
            'imsi already used'       => [['imsi' => '{taken_imsi}'], 'imsi'],
            'iccid missing'           => [['iccid' => '__missing__'], 'iccid'],
            'iccid 18 digits'         => [['iccid' => '899490000000000001'], 'iccid'],
            'iccid 21 digits'         => [['iccid' => '899490000000000000001'], 'iccid'],
            'iccid has a letter'      => [['iccid' => '8994900000000000A01'], 'iccid'],
            'iccid already used'      => [['iccid' => '{taken_iccid}'], 'iccid'],
            'status missing'          => [['sim_status' => '__missing__'], 'sim_status'],
        ];
    }

    #[DataProvider('badSimForms')]
    public function test_a_bad_sim_form_is_refused_and_no_sim_is_saved(array $overrides, string $errorField): void
    {
        $taken = $this->makeSim();
        $before = Sim::count();

        $tokens = ['{taken_number}' => $taken->sim_number, '{taken_imsi}' => $taken->imsi, '{taken_iccid}' => $taken->iccid, '__LONG__' => str_repeat('d', 256)];
        $overrides = array_map(fn ($v) => is_string($v) ? strtr($v, $tokens) : $v, $overrides);
        $form = array_filter($this->goodForm($overrides), fn ($v) => $v !== '__missing__');

        $this->addSim($form)->assertSessionHasErrors($errorField);

        $this->assertSame($before, Sim::count());
    }

    public function test_the_limits_are_exact(): void
    {
        $this->addSim($this->goodForm(['iccid' => '8994900000000000001']))->assertSessionHasNoErrors();      // 19 digits
        $this->addSim($this->goodForm(['iccid' => '89949000000000000002']))->assertSessionHasNoErrors();     // 20 digits
        $this->addSim($this->goodForm(['sim_type' => str_repeat('d', 255)]))->assertSessionHasNoErrors();
    }

    public function test_the_same_sim_cannot_be_added_twice(): void
    {
        $form = $this->goodForm();
        $this->addSim($form)->assertSessionHasNoErrors();
        $before = Sim::count();

        $this->addSim($form)->assertSessionHasErrors(['sim_number', 'imsi', 'iccid']);

        $this->assertSame($before, Sim::count());
    }

    // ------------------------------------------------------------------ status change

    public function test_a_sim_can_be_marked_activated_and_back(): void
    {
        $sim = $this->makeSim('Not Activated');

        $this->actingAs($this->admin())->patch(route('admin.stock.sim.update-status', $sim->id), ['sim_status' => 'Activated'])
            ->assertRedirect()->assertSessionHas('success', 'SIM Status Updated Successfully!');
        $this->assertSame('Activated', $sim->fresh()->sim_status);

        $this->actingAs($this->admin())->patch(route('admin.stock.sim.update-status', $sim->id), ['sim_status' => 'Not Activated'])
            ->assertSessionHas('success');
        $this->assertSame('Not Activated', $sim->fresh()->sim_status);
    }

    public function test_changing_the_status_changes_nothing_else_about_the_sim(): void
    {
        $sim = $this->makeSim('Not Activated', ['sim_type' => 'Hutch', 'activation_required' => true]);

        $this->actingAs($this->admin())->patch(route('admin.stock.sim.update-status', $sim->id), [
            'sim_status' => 'Activated', 'sim_number' => '0700000000', 'imsi' => '999999999999999', 'iccid' => '1', 'sim_type' => 'Hacked',
        ])->assertSessionHas('success');

        $fresh = $sim->fresh();
        $this->assertSame('Activated', $fresh->sim_status);
        $this->assertSame($sim->sim_number, $fresh->sim_number);
        $this->assertSame($sim->imsi, $fresh->imsi);
        $this->assertSame($sim->iccid, $fresh->iccid);
        $this->assertSame('Hutch', $fresh->sim_type);
        $this->assertTrue($fresh->activation_required);
    }

    public static function badStatuses(): array
    {
        return ['missing' => [null], 'unknown word' => ['Cancelled'], 'wrong case' => ['activated'], 'empty' => [''], 'a list' => [['Activated']]];
    }

    #[DataProvider('badStatuses')]
    public function test_a_bad_status_is_refused_and_the_sim_does_not_change(mixed $status): void
    {
        $sim = $this->makeSim('Not Activated');
        $body = $status === null ? [] : ['sim_status' => $status];

        $this->actingAs($this->admin())->patch(route('admin.stock.sim.update-status', $sim->id), $body)->assertSessionHasErrors('sim_status');

        $this->assertSame('Not Activated', $sim->fresh()->sim_status);
    }

    public static function badSimIds(): array
    {
        return ['a sim that does not exist' => ['987654321'], 'text' => ['abc'], 'a number too big for the database' => ['99999999999999999999'], 'negative' => ['-1'], 'decimal' => ['1.5']];
    }

    #[DataProvider('badSimIds')]
    public function test_a_bad_sim_id_is_a_clean_404_never_a_server_error(string $id): void
    {
        $url = '/admin/master-pages/admin/stock/sim/' . $id . '/update-status';

        $this->actingAs($this->admin())->patch($url, ['sim_status' => 'Activated'])->assertNotFound();
    }

    // ------------------------------------------------------------------ pages and PDF lists

    public function test_the_add_sim_page_splits_the_sims_by_status(): void
    {
        $on = $this->makeSim('Activated');
        $off = $this->makeSim('Not Activated');

        $this->actingAs($this->admin())->get(route('admin.add-sim'))
            ->assertOk()
            ->assertViewHas('activatedSims', fn ($l) => $l->pluck('id')->contains($on->id) && ! $l->pluck('id')->contains($off->id))
            ->assertViewHas('notActivatedSims', fn ($l) => $l->pluck('id')->contains($off->id) && ! $l->pluck('id')->contains($on->id));
    }

    public function test_a_script_in_a_sim_type_is_not_run_on_the_page(): void
    {
        $this->makeSim('Activated', ['sim_type' => '<script>alert(1)</script>']);

        $html = $this->actingAs($this->admin())->get(route('admin.add-sim'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_the_two_pdf_lists_download_as_pdf(): void
    {
        $this->makeSim('Activated');
        $this->makeSim('Not Activated');

        foreach (['admin.sim.export-activated' => 'activated_sims_', 'admin.sim.export-not-activated' => 'not_activated_sims_'] as $route => $prefix) {
            $response = $this->actingAs($this->admin())->get(route($route))->assertOk();
            $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
            $this->assertStringContainsString($prefix, $response->headers->get('content-disposition'));
        }
    }

    // ------------------------------------------------------------------ who may use it

    public static function otherRoles(): array
    {
        return array_map(fn ($r) => [$r], ['DEALER', 'DISTRIBUTOR', 'FINANCE', 'TECHNICIAN', 'SUPPLIER']);
    }

    #[DataProvider('otherRoles')]
    public function test_nobody_but_an_admin_can_add_change_or_list_sims(string $role): void
    {
        $sim = $this->makeSim('Not Activated');
        $before = Sim::count();
        $this->actingAs($this->makeAdmin($role));

        $this->get(route('admin.add-sim'))->assertForbidden();
        $this->get(route('admin.sim.export-activated'))->assertForbidden();
        $this->get(route('admin.sim.export-not-activated'))->assertForbidden();
        $this->post(route('admin.stock.sim.store'), $this->goodForm())->assertForbidden();
        $this->patch(route('admin.stock.sim.update-status', $sim->id), ['sim_status' => 'Activated'])->assertForbidden();

        $this->assertSame($before, Sim::count());
        $this->assertSame('Not Activated', $sim->fresh()->sim_status);
    }

    public function test_a_logged_out_visitor_is_sent_to_login(): void
    {
        $sim = $this->makeSim();

        $this->get(route('admin.add-sim'))->assertRedirect('/login');
        $this->post(route('admin.stock.sim.store'), [])->assertRedirect('/login');
        $this->patch(route('admin.stock.sim.update-status', $sim->id), ['sim_status' => 'Activated'])->assertRedirect('/login');
        $this->get(route('admin.sim.export-activated'))->assertRedirect('/login');
    }
}