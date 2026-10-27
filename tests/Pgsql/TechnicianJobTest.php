<?php

namespace Tests\Pgsql;

use App\Models\ActivatedDevice;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\CustomerAd;
use App\Models\TechnicianJob;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsTechnicianData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * TECHNICIAN JOBS (schedule, move, finish, cancel), on real PostgreSQL.
 *
 * A job sends a technician to one vehicle to Install, Repair, Replace a device or Remove it. Rules in simple words:
 *   - Repair, Replace and Removal need a device already bound to the vehicle. Install does not.
 *   - A vehicle can have only ONE open job of each type at a time. Finished or cancelled jobs do not count.
 *   - The technician must be Active. The day cannot be in the past (Sri Lanka's calendar, not the server's).
 *   - Only a scheduled job can change. Completed and cancelled jobs are final.
 *   - Finishing an Install writes the install date and installer on the device, but only if the device has none yet.
 *   - Cancelling needs a reason. Finishing needs a date that is not in the future.
 *   - Everything is written to the audit log. Bad ids and bad values are messages on the screen, never database errors
 *     (the vehicle id is a uuid in the database, so text there used to crash).
 *   - Only an ADMIN may do any of this.
 *
 * Nothing real is touched: all data is made up with Autotest names and rolled back after every test.
 */
class TechnicianJobTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsTechnicianData;

    private ?Admin $admin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->quietApi();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): Admin
    {
        return $this->admin ??= $this->makeAdmin('ADMIN');
    }

    private function form($vehicle, $technician, array $overrides = []): array
    {
        return array_merge([
            'job_type'      => 'install',
            'vehicle_id'    => $vehicle->vehicle_id,
            'technician_id' => $technician->id,
            'scheduled_for' => $this->slToday(1),
            'time_slot'     => 'morning',
            'location_note' => 'Near the town hall',
            'instructions'  => 'Call the owner first',
        ], $overrides);
    }

    private function schedule(array $form)
    {
        return $this->actingAs($this->admin())->from(route('admin.technician-jobs'))->post(route('admin.technician-jobs.store'), $form);
    }

    // ================================================================== list and tabs

    public function test_the_tabs_show_the_right_jobs_and_counts(): void
    {
        $t = $this->makeTechnician();
        $today = $this->makeJob($this->makeVehicle(), $t, ['scheduled_for' => $this->slToday()]);
        $later = $this->makeJob($this->makeVehicle(), $t, ['scheduled_for' => $this->slToday(3)]);
        $late = $this->makeJob($this->makeVehicle(), $t, ['scheduled_for' => $this->slToday(-2)]);
        $done = $this->makeJob($this->makeVehicle(), $t, ['status' => 'completed', 'completed_on' => $this->slToday(-1)]);
        $gone = $this->makeJob($this->makeVehicle(), $t, ['status' => 'cancelled', 'cancel_reason' => 'x']);

        $ids = fn (string $tab) => $this->actingAs($this->admin())->get(route('admin.technician-jobs', ['tab' => $tab, 'technician_id' => $t->id]))
            ->assertOk()->viewData('jobs')->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$today->id, $later->id, $late->id], $ids('open'));
        $this->assertSame([$today->id], $ids('today'));
        $this->assertSame([$late->id], $ids('overdue'));
        $this->assertSame([$done->id], $ids('completed'));
        $this->assertSame([$gone->id], $ids('cancelled'));
        $this->assertCount(5, $ids('all'));

        $this->actingAs($this->admin())->get(route('admin.technician-jobs', ['technician_id' => $t->id]))
            ->assertViewHas('counts', fn ($c) => $c['open'] === 3 && $c['today'] === 1 && $c['overdue'] === 1 && $c['completed'] === 1 && $c['cancelled'] === 1 && $c['all'] === 5);
    }

    public function test_open_jobs_are_listed_oldest_day_first(): void
    {
        $t = $this->makeTechnician();
        $later = $this->makeJob($this->makeVehicle(), $t, ['scheduled_for' => $this->slToday(5)]);
        $sooner = $this->makeJob($this->makeVehicle(), $t, ['scheduled_for' => $this->slToday(1)]);

        $ids = $this->actingAs($this->admin())->get(route('admin.technician-jobs', ['technician_id' => $t->id]))->viewData('jobs')->pluck('id')->all();

        $this->assertSame([$sooner->id, $later->id], $ids);
    }

    public function test_the_list_can_be_limited_to_one_technician(): void
    {
        $a = $this->makeTechnician();
        $b = $this->makeTechnician();
        $ofA = $this->makeJob($this->makeVehicle(), $a);
        $ofB = $this->makeJob($this->makeVehicle(), $b);

        $this->actingAs($this->admin())->get(route('admin.technician-jobs', ['technician_id' => $a->id]))
            ->assertViewHas('jobs', fn ($j) => $j->pluck('id')->all() === [$ofA->id] && ! $j->pluck('id')->contains($ofB->id));
    }

    public function test_the_customers_phone_is_shown_from_the_customer_record(): void
    {
        $vehicle = $this->makeVehicle();
        CustomerAd::create(['customer_id' => $vehicle->customer_id, 'full_name' => $vehicle->customer_name, 'phone_number' => '0771112233']);
        $t = $this->makeTechnician();
        $job = $this->makeJob($vehicle, $t);

        $this->actingAs($this->admin())->get(route('admin.technician-jobs', ['technician_id' => $t->id]))
            ->assertViewHas('jobs', fn ($j) => $j->firstWhere('id', $job->id)->customer_phone === '0771112233');
    }

    public function test_a_job_with_no_customer_record_still_lists_without_a_phone(): void
    {
        $t = $this->makeTechnician();
        $job = $this->makeJob($this->makeVehicle(), $t, ['customer_id' => null]);

        $this->actingAs($this->admin())->get(route('admin.technician-jobs', ['technician_id' => $t->id]))
            ->assertOk()->assertViewHas('jobs', fn ($j) => $j->firstWhere('id', $job->id)->customer_phone === null);
    }

    public static function oddQueries(): array
    {
        return [
            'unknown tab'          => [['tab' => 'bogus']],
            'a list as the tab'    => [['tab' => ['a']]],
            'text technician'      => [['technician_id' => 'abc']],
            'huge technician'      => [['technician_id' => '99999999999999999999']],
            'negative technician'  => [['technician_id' => '-5']],
            'a list as technician' => [['technician_id' => ['1']]],
            'text page'            => [['page' => 'abc']],
            'huge page'            => [['page' => '99999999999999999999']],
            'a script'             => [['tab' => '<script>', 'technician_id' => '<script>']],
        ];
    }

    #[DataProvider('oddQueries')]
    public function test_an_odd_list_address_never_causes_an_error(array $query): void
    {
        $this->actingAs($this->admin())->get(route('admin.technician-jobs', $query))->assertOk();
    }

    public function test_a_script_in_a_customer_name_or_note_is_shown_as_text(): void
    {
        $t = $this->makeTechnician();
        $this->makeJob($this->makeVehicle(['customer_name' => '<script>alert(1)</script>']), $t, [
            'customer_name' => '<script>alert(1)</script>', 'location_note' => '<script>alert(2)</script>',
        ]);

        $html = $this->actingAs($this->admin())->get(route('admin.technician-jobs', ['technician_id' => $t->id]))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<script>alert(2)</script>', $html);
    }

    // ================================================================== scheduling

    public function test_an_install_job_is_scheduled_with_copies_of_the_vehicle_and_customer_and_audited(): void
    {
        $t = $this->makeTechnician();
        $vehicle = $this->makeVehicle(['vehicle_number' => 'AUTO-COPY-1', 'customer_name' => 'Autotest Copy Customer']);

        $this->schedule($this->form($vehicle, $t))->assertSessionHasNoErrors()->assertSessionHas('success');

        $job = TechnicianJob::where('vehicle_id', $vehicle->vehicle_id)->sole();
        $this->assertSame('install', $job->job_type->value);
        $this->assertSame('scheduled', $job->status->value);
        $this->assertSame('AUTO-COPY-1', $job->vehicle_number);
        $this->assertSame('Autotest Copy Customer', $job->customer_name);
        $this->assertSame($vehicle->customer_id, $job->customer_id);
        $this->assertNull($job->imei_number, 'no device yet');
        $this->assertSame($this->admin()->admin_id, $job->created_by);
        $this->assertSame($this->slToday(1), $job->scheduled_for->toDateString());
        $this->assertSame('morning', $job->time_slot);
        $this->assertSame(1, AuditLog::where('action', 'job.scheduled')->where('subject_id', $vehicle->vehicle_id)->count());
    }

    #[DataProvider('needsDevice')]
    public function test_repair_replace_and_removal_need_a_bound_device(string $type): void
    {
        $t = $this->makeTechnician();
        $vehicle = $this->makeVehicle();

        $this->schedule($this->form($vehicle, $t, ['job_type' => $type]))->assertSessionHasErrors('vehicle_id');
        $this->assertSame(0, TechnicianJob::where('vehicle_id', $vehicle->vehicle_id)->count());

        $device = $this->bindDevice($vehicle);
        $this->schedule($this->form($vehicle, $t, ['job_type' => $type]))->assertSessionHasNoErrors();
        $this->assertSame($device->imei_number, TechnicianJob::where('vehicle_id', $vehicle->vehicle_id)->sole()->imei_number, 'the bound device is noted on the job');
    }

    public static function needsDevice(): array
    {
        return ['repair' => ['repair'], 'replace' => ['replace'], 'removal' => ['removal']];
    }

    public function test_install_works_even_when_a_device_is_already_bound(): void
    {
        $t = $this->makeTechnician();
        $vehicle = $this->makeVehicle();
        $this->bindDevice($vehicle);

        $this->schedule($this->form($vehicle, $t))->assertSessionHasNoErrors();
    }

    public function test_only_one_open_job_per_vehicle_and_type(): void
    {
        $t = $this->makeTechnician();
        $vehicle = $this->makeVehicle();

        $this->schedule($this->form($vehicle, $t))->assertSessionHasNoErrors();
        $this->schedule($this->form($vehicle, $t, ['scheduled_for' => $this->slToday(4)]))->assertSessionHasErrors('vehicle_id');

        $this->assertSame(1, TechnicianJob::where('vehicle_id', $vehicle->vehicle_id)->count());
    }

    public function test_a_different_type_on_the_same_vehicle_is_allowed(): void
    {
        $t = $this->makeTechnician();
        $vehicle = $this->makeVehicle();
        $this->bindDevice($vehicle);

        $this->schedule($this->form($vehicle, $t, ['job_type' => 'install']))->assertSessionHasNoErrors();
        $this->schedule($this->form($vehicle, $t, ['job_type' => 'repair']))->assertSessionHasNoErrors();

        $this->assertSame(2, TechnicianJob::where('vehicle_id', $vehicle->vehicle_id)->count());
    }

    public function test_the_database_itself_refuses_a_second_open_job_of_the_same_type(): void
    {
        $t = $this->makeTechnician();
        $vehicle = $this->makeVehicle();
        $this->makeJob($vehicle, $t);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->makeJob($vehicle, $t);
    }

    public function test_after_a_job_is_finished_or_cancelled_the_same_job_can_be_scheduled_again(): void
    {
        $t = $this->makeTechnician();
        $vehicle = $this->makeVehicle();
        $this->makeJob($vehicle, $t, ['status' => 'completed', 'completed_on' => $this->slToday(-1)]);
        $this->makeJob($vehicle, $t, ['status' => 'cancelled', 'cancel_reason' => 'x']);

        $this->schedule($this->form($vehicle, $t))->assertSessionHasNoErrors();
    }

    public function test_an_inactive_technician_cannot_be_given_a_job(): void
    {
        $t = $this->makeTechnician(['is_active' => false]);
        $vehicle = $this->makeVehicle();

        $this->schedule($this->form($vehicle, $t))->assertSessionHasErrors('technician_id');

        $this->assertSame(0, TechnicianJob::where('vehicle_id', $vehicle->vehicle_id)->count());
    }

    public function test_today_is_allowed_and_yesterday_is_not(): void
    {
        $t = $this->makeTechnician();
        $vehicle = $this->makeVehicle();

        $this->schedule($this->form($vehicle, $t, ['scheduled_for' => $this->slToday(-1)]))->assertSessionHasErrors('scheduled_for');
        $this->schedule($this->form($vehicle, $t, ['scheduled_for' => $this->slToday()]))->assertSessionHasNoErrors();
    }

    public function test_the_day_is_judged_by_sri_lankas_calendar_not_the_servers(): void
    {
        // 20:00 UTC on the 26th is 01:30 on the 27th in Sri Lanka.
        Carbon::setTestNow(Carbon::parse('2026-10-26 20:00:00', 'UTC'));
        $t = $this->makeTechnician();
        $vehicle = $this->makeVehicle();

        $this->schedule($this->form($vehicle, $t, ['scheduled_for' => '2026-10-26']))->assertSessionHasErrors('scheduled_for');
        $this->schedule($this->form($vehicle, $t, ['scheduled_for' => '2026-10-27']))->assertSessionHasNoErrors();
    }

    public function test_a_far_future_day_is_saved_without_a_database_error(): void
    {
        $t = $this->makeTechnician();
        $vehicle = $this->makeVehicle();

        $this->schedule($this->form($vehicle, $t, ['scheduled_for' => '9999-12-31']))->assertSessionHasNoErrors();

        $this->assertSame('9999-12-31', TechnicianJob::where('vehicle_id', $vehicle->vehicle_id)->first()->scheduled_for->toDateString());
    }

    public function test_optional_details_may_be_left_out(): void
    {
        $t = $this->makeTechnician();
        $vehicle = $this->makeVehicle();

        $this->schedule(['job_type' => 'install', 'vehicle_id' => $vehicle->vehicle_id, 'technician_id' => $t->id, 'scheduled_for' => $this->slToday(1)])
            ->assertSessionHasNoErrors();

        $job = TechnicianJob::where('vehicle_id', $vehicle->vehicle_id)->sole();
        $this->assertNull($job->time_slot);
        $this->assertNull($job->location_note);
    }

    public function test_a_client_cannot_set_the_status_or_other_hidden_fields_when_scheduling(): void
    {
        $t = $this->makeTechnician();
        $vehicle = $this->makeVehicle();

        $this->schedule($this->form($vehicle, $t, ['status' => 'completed', 'completed_on' => $this->slToday(), 'created_by' => 'someone-else', 'customer_name' => 'Fake Name']))
            ->assertSessionHasNoErrors();

        $job = TechnicianJob::where('vehicle_id', $vehicle->vehicle_id)->sole();
        $this->assertSame('scheduled', $job->status->value);
        $this->assertNull($job->completed_on);
        $this->assertSame($this->admin()->admin_id, $job->created_by);
        $this->assertSame($vehicle->customer_name, $job->customer_name);
    }

    public static function badSchedules(): array
    {
        $ok = ['job_type' => 'install'];

        return [
            'no job type'            => [['job_type' => ''], 'job_type'],
            'unknown job type'       => [['job_type' => 'paint'], 'job_type'],
            'a list as job type'     => [['job_type' => ['install']], 'job_type'],
            'no vehicle'             => [['vehicle_id' => ''], 'vehicle_id'],
            'text vehicle'           => [['vehicle_id' => 'abc'], 'vehicle_id'],
            'huge number vehicle'    => [['vehicle_id' => '99999999999999999999'], 'vehicle_id'],
            'vehicle that is not there' => [['vehicle_id' => '5f1d2c3e-1111-4222-8333-444455556666'], 'vehicle_id'],
            'a list as vehicle'      => [['vehicle_id' => ['x']], 'vehicle_id'],
            'no technician'          => [['technician_id' => ''], 'technician_id'],
            'text technician'        => [['technician_id' => 'abc'], 'technician_id'],
            'huge technician'        => [['technician_id' => '99999999999999999999'], 'technician_id'],
            'technician not there'   => [['technician_id' => 987654321], 'technician_id'],
            'zero technician'        => [['technician_id' => 0], 'technician_id'],
            'no day'                 => [['scheduled_for' => ''], 'scheduled_for'],
            'text day'               => [['scheduled_for' => 'tomorrow-ish'], 'scheduled_for'],
            'impossible day'         => [['scheduled_for' => '2026-13-45'], 'scheduled_for'],
            'a long past day'        => [['scheduled_for' => '2001-01-01'], 'scheduled_for'],
            'unknown time slot'      => [['time_slot' => 'midnight'], 'time_slot'],
            'location too long'      => [['location_note' => str_repeat('a', 256)], 'location_note'],
            'instructions too long'  => [['instructions' => str_repeat('a', 501)], 'instructions'],
        ];
    }

    #[DataProvider('badSchedules')]
    public function test_a_bad_schedule_form_is_refused_with_a_message_and_nothing_is_saved(array $override, string $field): void
    {
        $t = $this->makeTechnician();
        $vehicle = $this->makeVehicle();
        $before = TechnicianJob::count();

        $this->schedule($this->form($vehicle, $t, $override))->assertSessionHasErrors($field);

        $this->assertSame($before, TechnicianJob::count());
    }

    // ================================================================== moving a job

    private function reform($technician, array $overrides = []): array
    {
        return array_merge([
            'technician_id' => $technician->id,
            'scheduled_for' => $this->slToday(6),
            'time_slot'     => 'evening',
            'location_note' => 'New address',
            'instructions'  => 'New instructions',
        ], $overrides);
    }

    public function test_a_scheduled_job_can_be_moved_and_only_the_changes_are_audited(): void
    {
        $old = $this->makeTechnician(['name' => 'Autotest Old Tech']);
        $new = $this->makeTechnician(['name' => 'Autotest New Tech']);
        $job = $this->makeJob($this->makeVehicle(), $old, ['time_slot' => 'morning', 'location_note' => 'Old address', 'instructions' => 'Old instructions']);

        $this->actingAs($this->admin())->patch(route('admin.technician-jobs.reschedule', $job->id), $this->reform($new))
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $fresh = $job->fresh();
        $this->assertSame($new->id, $fresh->technician_id);
        $this->assertSame($this->slToday(6), $fresh->scheduled_for->toDateString());
        $this->assertSame('evening', $fresh->time_slot);

        $log = AuditLog::where('action', 'job.rescheduled')->where('subject_id', $job->vehicle_id)->sole();
        $this->assertSame('Autotest New Tech', $log->changes['technician']['to']);
    }

    public function test_saving_a_move_with_nothing_changed_writes_no_audit_line(): void
    {
        $t = $this->makeTechnician();
        $job = $this->makeJob($this->makeVehicle(), $t, ['time_slot' => 'morning', 'location_note' => 'Same', 'instructions' => 'Same']);

        $this->actingAs($this->admin())->patch(route('admin.technician-jobs.reschedule', $job->id), [
            'technician_id' => $t->id, 'scheduled_for' => $job->scheduled_for->toDateString(), 'time_slot' => 'morning', 'location_note' => 'Same', 'instructions' => 'Same',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, AuditLog::where('action', 'job.rescheduled')->where('subject_id', $job->vehicle_id)->count());
    }

    public function test_a_move_cannot_change_the_vehicle_type_or_status(): void
    {
        $t = $this->makeTechnician();
        $other = $this->makeVehicle();
        $job = $this->makeJob($this->makeVehicle(), $t);

        $this->actingAs($this->admin())->patch(route('admin.technician-jobs.reschedule', $job->id), $this->reform($t, [
            'vehicle_id' => $other->vehicle_id, 'job_type' => 'removal', 'status' => 'completed',
        ]));

        $fresh = $job->fresh();
        $this->assertSame($job->vehicle_id, $fresh->vehicle_id);
        $this->assertSame('install', $fresh->job_type->value);
        $this->assertSame('scheduled', $fresh->status->value);
    }

    #[DataProvider('finalStates')]
    public function test_a_finished_or_cancelled_job_cannot_be_moved_finished_or_cancelled_again(string $status): void
    {
        $t = $this->makeTechnician();
        $extra = $status === 'completed' ? ['completed_on' => $this->slToday(-1)] : ['cancel_reason' => 'Original reason'];
        $job = $this->makeJob($this->makeVehicle(), $t, ['status' => $status] + $extra);
        $day = $job->scheduled_for->toDateString();

        $this->actingAs($this->admin())->patch(route('admin.technician-jobs.reschedule', $job->id), $this->reform($t))->assertSessionHasErrors('job');
        $this->actingAs($this->admin())->post(route('admin.technician-jobs.complete', $job->id), ['completed_on' => $this->slToday()])->assertSessionHasErrors('job');
        $this->actingAs($this->admin())->post(route('admin.technician-jobs.cancel', $job->id), ['cancel_reason' => 'Changed my mind'])->assertSessionHasErrors('job');

        $fresh = $job->fresh();
        $this->assertSame($status, $fresh->status->value);
        $this->assertSame($day, $fresh->scheduled_for->toDateString());
        $this->assertNotSame('Changed my mind', $fresh->cancel_reason);
    }

    public static function finalStates(): array
    {
        return ['completed' => ['completed'], 'cancelled' => ['cancelled']];
    }

    public static function badMoves(): array
    {
        return [
            'no technician'        => [['technician_id' => ''], 'technician_id'],
            'text technician'      => [['technician_id' => 'abc'], 'technician_id'],
            'huge technician'      => [['technician_id' => '99999999999999999999'], 'technician_id'],
            'technician not there' => [['technician_id' => 987654321], 'technician_id'],
            'no day'               => [['scheduled_for' => ''], 'scheduled_for'],
            'a past day'           => [['scheduled_for' => '2001-01-01'], 'scheduled_for'],
            'text day'             => [['scheduled_for' => 'soon'], 'scheduled_for'],
            'unknown time slot'    => [['time_slot' => 'noon'], 'time_slot'],
            'location too long'    => [['location_note' => str_repeat('a', 256)], 'location_note'],
            'instructions too long' => [['instructions' => str_repeat('a', 501)], 'instructions'],
        ];
    }

    #[DataProvider('badMoves')]
    public function test_a_bad_move_is_refused_and_nothing_changes(array $override, string $field): void
    {
        $t = $this->makeTechnician();
        $job = $this->makeJob($this->makeVehicle(), $t);
        $day = $job->scheduled_for->toDateString();

        $this->actingAs($this->admin())->from(route('admin.technician-jobs'))
            ->patch(route('admin.technician-jobs.reschedule', $job->id), $this->reform($t, $override))->assertSessionHasErrors($field);

        $this->assertSame($day, $job->fresh()->scheduled_for->toDateString());
    }

    public function test_a_job_cannot_be_moved_to_an_inactive_technician(): void
    {
        $t = $this->makeTechnician();
        $off = $this->makeTechnician(['is_active' => false]);
        $job = $this->makeJob($this->makeVehicle(), $t);

        $this->actingAs($this->admin())->patch(route('admin.technician-jobs.reschedule', $job->id), $this->reform($off))->assertSessionHasErrors('technician_id');

        $this->assertSame($t->id, $job->fresh()->technician_id);
    }

    // ================================================================== finishing a job

    public function test_finishing_an_install_fills_the_device_install_details_and_audits_both(): void
    {
        $t = $this->makeTechnician(['name' => 'Autotest Installer']);
        $vehicle = $this->makeVehicle();
        $device = $this->bindDevice($vehicle);
        $job = $this->makeJob($vehicle, $t, ['imei_number' => $device->imei_number]);
        $day = $this->slToday(-1);

        $this->actingAs($this->admin())->post(route('admin.technician-jobs.complete', $job->id), ['completed_on' => $day, 'completion_notes' => 'All good'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $fresh = $job->fresh();
        $this->assertSame('completed', $fresh->status->value);
        $this->assertSame($day, $fresh->completed_on->toDateString());
        $this->assertSame('All good', $fresh->completion_notes);
        $this->assertSame($this->admin()->admin_id, $fresh->decided_by);

        $device = ActivatedDevice::where('vehicle_id', $vehicle->vehicle_id)->first();
        $this->assertSame($day, $device->installed_on->toDateString());
        $this->assertSame('Autotest Installer', $device->installed_by);
        $this->assertSame(1, AuditLog::where('action', 'job.completed')->where('subject_id', $vehicle->vehicle_id)->count());
        $this->assertSame(1, AuditLog::where('action', 'install.updated')->where('subject_id', $vehicle->vehicle_id)->count());
    }

    public function test_an_install_date_already_on_the_device_is_never_overwritten(): void
    {
        $t = $this->makeTechnician();
        $vehicle = $this->makeVehicle();
        $this->bindDevice($vehicle, ['installed_on' => '2026-01-05', 'installed_by' => 'Original Installer']);
        $job = $this->makeJob($vehicle, $t);

        $this->actingAs($this->admin())->post(route('admin.technician-jobs.complete', $job->id), ['completed_on' => $this->slToday()])
            ->assertSessionHas('success');

        $device = ActivatedDevice::where('vehicle_id', $vehicle->vehicle_id)->first();
        $this->assertSame('2026-01-05', $device->installed_on->toDateString());
        $this->assertSame('Original Installer', $device->installed_by);
        $this->assertSame(0, AuditLog::where('action', 'install.updated')->where('subject_id', $vehicle->vehicle_id)->count());
        $this->assertStringContainsString('left unchanged', session('success'));
    }

    public function test_finishing_an_install_with_no_bound_device_still_works_and_says_so(): void
    {
        $t = $this->makeTechnician();
        $vehicle = $this->makeVehicle();
        $job = $this->makeJob($vehicle, $t);

        $this->actingAs($this->admin())->post(route('admin.technician-jobs.complete', $job->id), ['completed_on' => $this->slToday()])
            ->assertSessionHasNoErrors();

        $this->assertSame('completed', $job->fresh()->status->value);
        $this->assertStringContainsString('no device bound', session('success'));
    }

    public function test_repair_and_removal_never_touch_the_device(): void
    {
        $t = $this->makeTechnician();
        $vehicle = $this->makeVehicle();
        $device = $this->bindDevice($vehicle);
        $repair = $this->makeJob($vehicle, $t, ['job_type' => 'repair', 'imei_number' => $device->imei_number]);
        $removal = $this->makeJob($vehicle, $t, ['job_type' => 'removal', 'imei_number' => $device->imei_number]);

        foreach ([$repair, $removal] as $job) {
            $this->actingAs($this->admin())->post(route('admin.technician-jobs.complete', $job->id), ['completed_on' => $this->slToday()])->assertSessionHasNoErrors();
        }

        $device = ActivatedDevice::where('vehicle_id', $vehicle->vehicle_id)->first();
        $this->assertNull($device->installed_on);
        $this->assertSame($device->imei_number, $removal->fresh()->imei_number);
    }

    public function test_finishing_a_replace_job_reminds_the_admin_to_record_the_swap_and_changes_no_device(): void
    {
        $t = $this->makeTechnician();
        $vehicle = $this->makeVehicle();
        $device = $this->bindDevice($vehicle);
        $job = $this->makeJob($vehicle, $t, ['job_type' => 'replace', 'imei_number' => $device->imei_number]);

        $this->actingAs($this->admin())->post(route('admin.technician-jobs.complete', $job->id), ['completed_on' => $this->slToday()]);

        $this->assertStringContainsString('Replace Device', session('success'));
        $this->assertSame($device->imei_number, ActivatedDevice::where('vehicle_id', $vehicle->vehicle_id)->value('imei_number'));
    }

    public static function badCompletions(): array
    {
        return [
            'no date'            => [['completed_on' => ''], 'completed_on'],
            'text date'          => [['completed_on' => 'yesterday-ish'], 'completed_on'],
            'impossible date'    => [['completed_on' => '2026-02-31'], 'completed_on'],
            'a list as the date' => [['completed_on' => ['2026-01-01']], 'completed_on'],
            'notes too long'     => [['completion_notes' => str_repeat('a', 501)], 'completion_notes'],
        ];
    }

    #[DataProvider('badCompletions')]
    public function test_a_bad_completion_is_refused_and_the_job_stays_open(array $override, string $field): void
    {
        $t = $this->makeTechnician();
        $job = $this->makeJob($this->makeVehicle(), $t);

        $this->actingAs($this->admin())->from(route('admin.technician-jobs'))
            ->post(route('admin.technician-jobs.complete', $job->id), array_merge(['completed_on' => $this->slToday()], $override))->assertSessionHasErrors($field);

        $this->assertSame('scheduled', $job->fresh()->status->value);
    }

    public function test_a_future_completion_date_is_refused_and_the_device_is_untouched(): void
    {
        $t = $this->makeTechnician();
        $vehicle = $this->makeVehicle();
        $this->bindDevice($vehicle);
        $job = $this->makeJob($vehicle, $t);

        $this->actingAs($this->admin())->post(route('admin.technician-jobs.complete', $job->id), ['completed_on' => $this->slToday(1)])
            ->assertSessionHasErrors('completed_on');

        $this->assertSame('scheduled', $job->fresh()->status->value);
        $this->assertNull(ActivatedDevice::where('vehicle_id', $vehicle->vehicle_id)->value('installed_on'));
    }

    public function test_a_job_can_be_finished_for_a_day_before_it_was_scheduled(): void
    {
        $t = $this->makeTechnician();
        $job = $this->makeJob($this->makeVehicle(), $t, ['scheduled_for' => $this->slToday(5)]);

        $this->actingAs($this->admin())->post(route('admin.technician-jobs.complete', $job->id), ['completed_on' => $this->slToday(-3)])
            ->assertSessionHasNoErrors();

        $this->assertSame('completed', $job->fresh()->status->value);
    }

    // ================================================================== cancelling

    public function test_a_job_is_cancelled_with_a_reason_and_audited(): void
    {
        $t = $this->makeTechnician();
        $job = $this->makeJob($this->makeVehicle(), $t);

        $this->actingAs($this->admin())->post(route('admin.technician-jobs.cancel', $job->id), ['cancel_reason' => 'Customer not available'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $fresh = $job->fresh();
        $this->assertSame('cancelled', $fresh->status->value);
        $this->assertSame('Customer not available', $fresh->cancel_reason);
        $this->assertSame($this->admin()->admin_id, $fresh->decided_by);
        $this->assertSame(1, AuditLog::where('action', 'job.cancelled')->where('subject_id', $job->vehicle_id)->count());
    }

    public static function badReasons(): array
    {
        return ['no reason' => [''], 'too long' => [str_repeat('a', 256)], 'a list' => [['a']]];
    }

    #[DataProvider('badReasons')]
    public function test_a_cancel_without_a_proper_reason_is_refused(mixed $reason): void
    {
        $t = $this->makeTechnician();
        $job = $this->makeJob($this->makeVehicle(), $t);

        $this->actingAs($this->admin())->from(route('admin.technician-jobs'))
            ->post(route('admin.technician-jobs.cancel', $job->id), ['cancel_reason' => $reason])->assertSessionHasErrors('cancel_reason');

        $this->assertSame('scheduled', $job->fresh()->status->value);
    }

    // ================================================================== bad ids

    public static function badIds(): array
    {
        return ['text' => ['abc'], 'too big for the database' => ['99999999999999999999'], 'a job that does not exist' => ['987654321'], 'negative' => ['-3']];
    }

    #[DataProvider('badIds')]
    public function test_a_bad_job_id_is_a_clean_404(string $id): void
    {
        $this->actingAs($this->admin())->patch("/admin/technician-jobs/{$id}/reschedule", $this->reform($this->makeTechnician()))->assertNotFound();
        $this->actingAs($this->admin())->post("/admin/technician-jobs/{$id}/complete", ['completed_on' => $this->slToday()])->assertNotFound();
        $this->actingAs($this->admin())->post("/admin/technician-jobs/{$id}/cancel", ['cancel_reason' => 'x'])->assertNotFound();
    }

    // ================================================================== vehicle search

    private function search(string $q)
    {
        return $this->actingAs($this->admin())->getJson(route('admin.technician-jobs.vehicles', ['q' => $q]));
    }

    public function test_a_vehicle_is_found_however_the_number_is_typed(): void
    {
        $v = $this->makeVehicle(['vehicle_number' => 'WP BGU-1212', 'customer_name' => 'Autotest Finder']);

        foreach (['WP BGU-1212', 'wp bgu 1212', 'wpbgu1212', 'BGU.1212', 'bgu-12'] as $typed) {
            $this->search($typed)->assertOk()->assertJsonFragment(['vehicle_id' => $v->vehicle_id]);
        }
    }

    public function test_a_vehicle_is_found_by_the_customer_name_in_any_case(): void
    {
        $v = $this->makeVehicle(['customer_name' => 'Autotest Zebediah Quill']);

        $this->search('zebediah QUILL')->assertOk()->assertJsonFragment(['vehicle_id' => $v->vehicle_id]);
    }

    public function test_the_answer_has_the_fields_the_form_needs(): void
    {
        $v = $this->makeVehicle(['vehicle_number' => 'AUTO-SHAPE-1', 'customer_name' => 'Autotest Shape', 'make' => 'Honda', 'model' => 'Fit']);

        $row = collect($this->search('AUTO-SHAPE-1')->assertOk()->json())->firstWhere('vehicle_id', $v->vehicle_id);

        $this->assertSame('AUTO-SHAPE-1', $row['vehicle_number']);
        $this->assertSame('Autotest Shape', $row['customer_name']);
        $this->assertStringContainsString('Honda Fit', $row['label']);
    }

    public function test_a_search_shorter_than_two_letters_gives_an_empty_list(): void
    {
        $this->makeVehicle(['vehicle_number' => 'A-1']);

        $this->search('a')->assertOk()->assertExactJson([]);
        $this->search('')->assertOk()->assertExactJson([]);
    }

    public function test_at_most_fifteen_vehicles_come_back_in_number_order(): void
    {
        $tag = 'AUTOLIM' . $this->nextIntakeNumber();
        foreach (range(1, 20) as $i) {
            $this->makeVehicle(['vehicle_number' => sprintf('%s-%02d', $tag, $i)]);
        }

        $rows = $this->search($tag)->assertOk()->json();

        $this->assertCount(15, $rows);
        $this->assertSame(sprintf('%s-01', $tag), $rows[0]['vehicle_number']);
    }

    public function test_percent_and_underscore_are_plain_letters_in_a_customer_name_search(): void
    {
        $plain = $this->makeVehicle(['customer_name' => 'Autotest Plain Customer']);
        $literal = $this->makeVehicle(['customer_name' => 'Autotest 100% Genuine_Co']);

        $ids = collect($this->search('100%')->assertOk()->json())->pluck('vehicle_id')->all();

        $this->assertContains($literal->vehicle_id, $ids);
        $this->assertNotContains($plain->vehicle_id, $ids);

        $ids = collect($this->search('Genuine_Co')->json())->pluck('vehicle_id')->all();
        $this->assertSame([$literal->vehicle_id], array_values(array_intersect($ids, [$plain->vehicle_id, $literal->vehicle_id])));
    }

    public static function oddSearches(): array
    {
        return [
            'quotes'        => ["' OR 1=1 --"],
            'a script'      => ['<script>alert(1)</script>'],
            'only symbols'  => ['!!'],
            'very long'     => [str_repeat('a', 500)],
            'backslashes'   => ['\\\\'],
            'unicode'       => ['සිංහල'],
        ];
    }

    #[DataProvider('oddSearches')]
    public function test_an_odd_search_gives_a_list_and_never_an_error(string $q): void
    {
        $this->search($q)->assertOk();
    }

    public function test_a_list_as_the_search_text_never_causes_an_error(): void
    {
        $this->actingAs($this->admin())->getJson('/admin/technician-jobs/vehicles?q[]=ab')->assertOk();
    }

    public function test_the_search_is_limited_to_sixty_requests_a_minute(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $last = null;
        foreach (range(1, 61) as $i) {
            $last = $this->getJson(route('admin.technician-jobs.vehicles', ['q' => 'zz']));
        }

        $last->assertStatus(429);
    }

    // ================================================================== who may enter

    public static function otherRoles(): array
    {
        return array_map(fn ($r) => [$r], ['DEALER', 'DISTRIBUTOR', 'FINANCE', 'TECHNICIAN', 'SUPPLIER']);
    }

    #[DataProvider('otherRoles')]
    public function test_only_an_admin_can_use_technician_jobs(string $role): void
    {
        $t = $this->makeTechnician();
        $vehicle = $this->makeVehicle();
        $job = $this->makeJob($this->makeVehicle(), $t);
        $user = $this->makeAdmin($role);
        $before = TechnicianJob::count();

        $this->actingAs($user)->get(route('admin.technician-jobs'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.technician-jobs.store'), $this->form($vehicle, $t))->assertForbidden();
        $this->actingAs($user)->getJson(route('admin.technician-jobs.vehicles', ['q' => 'AUTO']))->assertForbidden();
        $this->actingAs($user)->patch(route('admin.technician-jobs.reschedule', $job->id), $this->reform($t))->assertForbidden();
        $this->actingAs($user)->post(route('admin.technician-jobs.complete', $job->id), ['completed_on' => $this->slToday()])->assertForbidden();
        $this->actingAs($user)->post(route('admin.technician-jobs.cancel', $job->id), ['cancel_reason' => 'x'])->assertForbidden();

        $this->assertSame($before, TechnicianJob::count());
        $this->assertSame('scheduled', $job->fresh()->status->value);
    }

    public function test_a_logged_out_visitor_is_sent_to_log_in(): void
    {
        $this->get(route('admin.technician-jobs'))->assertRedirect();
        $this->post(route('admin.technician-jobs.store'), [])->assertRedirect();
        $this->getJson(route('admin.technician-jobs.vehicles', ['q' => 'ab']))->assertUnauthorized();
    }
}