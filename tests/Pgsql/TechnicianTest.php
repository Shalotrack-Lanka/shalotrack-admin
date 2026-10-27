<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Technician;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsTechnicianData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * TECHNICIANS (the list of field technicians), on real PostgreSQL.
 *
 * A technician is a name the admin schedules jobs for, not a login account. Rules in simple words:
 *   - The admin adds a technician (name required, phone optional). New ones start Active.
 *   - Two technicians cannot share a name, even if the capital letters differ.
 *   - A technician is deactivated, never deleted, so old jobs keep their history. An inactive technician cannot be
 *     given new jobs (that is checked in the job suite).
 *   - Every add and every real change is written to the audit log (nothing is written when nothing changed).
 *   - A bad id in the address is a clean 404, never a database error.
 *   - Only an ADMIN may do any of this.
 *
 * Nothing real is touched: all data is made up with Autotest names and rolled back after every test.
 */
class TechnicianTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsTechnicianData;

    private ?Admin $admin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->quietApi();
    }

    private function admin(): Admin
    {
        return $this->admin ??= $this->makeAdmin('ADMIN');
    }

    // ================================================================== list

    public function test_the_list_shows_active_technicians_first_then_by_name_with_their_open_jobs(): void
    {
        $zed = $this->makeTechnician(['name' => 'ZZZ Autotest Active']);
        $abe = $this->makeTechnician(['name' => 'AAA Autotest Inactive', 'is_active' => false]);
        $vehicle = $this->makeVehicle();
        $this->makeJob($vehicle, $zed);
        $this->makeJob($this->makeVehicle(), $zed, ['status' => 'completed']);
        $this->makeJob($this->makeVehicle(), $zed, ['status' => 'cancelled']);

        $this->actingAs($this->admin())->get(route('admin.technicians'))
            ->assertOk()
            ->assertViewHas('technicians', function ($list) use ($zed, $abe) {
                $ids = $list->pluck('id')->all();

                return array_search($zed->id, $ids) < array_search($abe->id, $ids)
                    && $list->firstWhere('id', $zed->id)->open_jobs === 1
                    && $list->firstWhere('id', $abe->id)->open_jobs === 0;
            });
    }

    public function test_a_script_in_a_technician_name_is_shown_as_text_and_never_run(): void
    {
        $this->makeTechnician(['name' => '<script>alert(1)</script>']);

        $html = $this->actingAs($this->admin())->get(route('admin.technicians'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    // ================================================================== add

    public function test_a_technician_is_added_active_and_audited(): void
    {
        $this->actingAs($this->admin())->post(route('admin.technicians.store'), ['name' => 'Autotest Kamal', 'phone' => '+94 (77) 123-4567'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $t = Technician::where('name', 'Autotest Kamal')->sole();
        $this->assertTrue($t->is_active);
        $this->assertSame('+94 (77) 123-4567', $t->phone);
        $this->assertSame(1, AuditLog::where('action', 'technician.created')->where('subject_id', (string) $t->id)->count());
    }

    public function test_the_phone_is_optional(): void
    {
        $this->actingAs($this->admin())->post(route('admin.technicians.store'), ['name' => 'Autotest No Phone'])
            ->assertSessionHasNoErrors();

        $this->assertNull(Technician::where('name', 'Autotest No Phone')->value('phone'));
    }

    public function test_a_name_cannot_be_used_twice_even_with_different_capitals(): void
    {
        $this->makeTechnician(['name' => 'Autotest Duplicate']);

        foreach (['Autotest Duplicate', 'AUTOTEST DUPLICATE', 'autotest duplicate'] as $name) {
            $this->actingAs($this->admin())->post(route('admin.technicians.store'), ['name' => $name])
                ->assertSessionHasErrors('name');
        }

        $this->assertSame(1, Technician::whereRaw('LOWER(name) = ?', ['autotest duplicate'])->count());
    }

    public function test_a_client_cannot_add_an_inactive_technician_or_pick_the_id(): void
    {
        $this->actingAs($this->admin())->post(route('admin.technicians.store'), ['name' => 'Autotest Forced', 'is_active' => 0, 'id' => 987654321])
            ->assertSessionHasNoErrors();

        $t = Technician::where('name', 'Autotest Forced')->sole();
        $this->assertTrue($t->is_active);
        $this->assertNotSame(987654321, $t->id);
    }

    public static function badForms(): array
    {
        return [
            'no name'               => [['name' => ''], 'name'],
            'name too long'         => [['name' => str_repeat('a', 101)], 'name'],
            'a list as the name'    => [['name' => ['a', 'b']], 'name'],
            'letters in the phone'  => [['name' => 'Autotest Bad Phone', 'phone' => 'call me'], 'phone'],
            'phone too short'       => [['name' => 'Autotest Short Phone', 'phone' => '12345'], 'phone'],
            'phone too long'        => [['name' => 'Autotest Long Phone', 'phone' => str_repeat('1', 21)], 'phone'],
            'a list as the phone'   => [['name' => 'Autotest List Phone', 'phone' => ['1']], 'phone'],
        ];
    }

    #[DataProvider('badForms')]
    public function test_a_bad_add_form_is_refused_and_nothing_is_saved(array $form, string $field): void
    {
        $before = Technician::count();

        $this->actingAs($this->admin())->from(route('admin.technicians'))
            ->post(route('admin.technicians.store'), $form)->assertSessionHasErrors($field);

        $this->assertSame($before, Technician::count());
    }

    // ================================================================== update

    public function test_a_technician_can_be_renamed_and_the_change_is_audited(): void
    {
        $t = $this->makeTechnician(['name' => 'Autotest Before']);

        $this->actingAs($this->admin())->patch(route('admin.technicians.update', $t->id), ['name' => 'Autotest After', 'phone' => '0771234567'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame('Autotest After', $t->fresh()->name);
        $this->assertSame('0771234567', $t->fresh()->phone);
        $this->assertSame(1, AuditLog::where('action', 'technician.updated')->where('subject_id', (string) $t->id)->count());
    }

    public function test_saving_with_no_change_writes_no_audit_line(): void
    {
        $t = $this->makeTechnician();

        $this->actingAs($this->admin())->patch(route('admin.technicians.update', $t->id), ['name' => $t->name, 'phone' => $t->phone, 'is_active' => 1])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, AuditLog::where('action', 'technician.updated')->where('subject_id', (string) $t->id)->count());
    }

    public function test_a_technician_can_be_deactivated_and_activated_again_and_old_jobs_stay(): void
    {
        $t = $this->makeTechnician();
        $job = $this->makeJob($this->makeVehicle(), $t);

        $this->actingAs($this->admin())->patch(route('admin.technicians.update', $t->id), ['name' => $t->name, 'phone' => $t->phone, 'is_active' => 0]);
        $this->assertFalse($t->fresh()->is_active);
        $this->assertSame($t->id, $job->fresh()->technician_id, 'the job keeps its technician');

        $this->actingAs($this->admin())->patch(route('admin.technicians.update', $t->id), ['name' => $t->name, 'phone' => $t->phone, 'is_active' => 1]);
        $this->assertTrue($t->fresh()->is_active);
    }

    public function test_a_technician_may_keep_their_own_name_but_not_take_another_ones(): void
    {
        $a = $this->makeTechnician(['name' => 'Autotest Alpha']);
        $this->makeTechnician(['name' => 'Autotest Beta']);

        $this->actingAs($this->admin())->patch(route('admin.technicians.update', $a->id), ['name' => 'Autotest Alpha'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin())->patch(route('admin.technicians.update', $a->id), ['name' => 'autotest BETA'])->assertSessionHasErrors('name');

        $this->assertSame('Autotest Alpha', $a->fresh()->name);
    }

    public function test_the_active_flag_must_be_a_yes_or_no(): void
    {
        $t = $this->makeTechnician();

        $this->actingAs($this->admin())->patch(route('admin.technicians.update', $t->id), ['name' => $t->name, 'is_active' => 'maybe'])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($t->fresh()->is_active);
    }

    #[DataProvider('badForms')]
    public function test_a_bad_update_form_is_refused_and_nothing_changes(array $form, string $field): void
    {
        $t = $this->makeTechnician(['name' => 'Autotest Keep']);

        $this->actingAs($this->admin())->from(route('admin.technicians'))
            ->patch(route('admin.technicians.update', $t->id), $form)->assertSessionHasErrors($field);

        $this->assertSame('Autotest Keep', $t->fresh()->name);
    }

    public static function badIds(): array
    {
        return ['text' => ['abc'], 'too big for the database' => ['99999999999999999999'], 'a technician that does not exist' => ['987654321'], 'negative' => ['-3']];
    }

    #[DataProvider('badIds')]
    public function test_a_bad_technician_id_is_a_clean_404(string $id): void
    {
        $this->actingAs($this->admin())->patch("/admin/technicians/{$id}", ['name' => 'Autotest Ghost'])->assertNotFound();
    }

    // ================================================================== who may enter

    public static function otherRoles(): array
    {
        return array_map(fn ($r) => [$r], ['DEALER', 'DISTRIBUTOR', 'FINANCE', 'TECHNICIAN', 'SUPPLIER']);
    }

    #[DataProvider('otherRoles')]
    public function test_only_an_admin_can_manage_technicians(string $role): void
    {
        $t = $this->makeTechnician(['name' => 'Autotest Protected']);
        $user = $this->makeAdmin($role);
        $before = Technician::count();

        $this->actingAs($user)->get(route('admin.technicians'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.technicians.store'), ['name' => 'Autotest Intruder'])->assertForbidden();
        $this->actingAs($user)->patch(route('admin.technicians.update', $t->id), ['name' => 'Autotest Hacked'])->assertForbidden();

        $this->assertSame($before, Technician::count());
        $this->assertSame('Autotest Protected', $t->fresh()->name);
    }

    public function test_a_logged_out_visitor_is_sent_to_log_in(): void
    {
        $this->get(route('admin.technicians'))->assertRedirect();
        $this->post(route('admin.technicians.store'), ['name' => 'x'])->assertRedirect();
    }
}