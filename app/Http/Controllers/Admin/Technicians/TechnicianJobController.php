<?php

namespace App\Http\Controllers\Admin\Technicians;

use App\Enums\TechnicianJobStatus;
use App\Enums\TechnicianJobType;
use App\Http\Controllers\Controller;
use App\Models\ActivatedDevice;
use App\Models\Technician;
use App\Models\TechnicianJob;
use App\Models\VehicleAd;
use App\Services\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Technician jobs: schedule a visit (install, repair, replace, removal), move it, finish it or cancel
 * it. Admin only. Only a scheduled job can change; completed and cancelled are final.
 *
 * Completing an install is the one place a job touches another record: it fills the device's install
 * details (installed on / by), but only when the device is already bound and has no install date, so a
 * job can never overwrite what an admin recorded. Replace and removal are not automated: a swap is
 * still recorded with Replace Device, so IMEIs and subscriptions are never changed from here.
 */
class TechnicianJobController extends Controller
{
    public const TABS = [
        'open'      => 'Scheduled',
        'today'     => 'Today',
        'overdue'   => 'Overdue',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'all'       => 'All',
    ];

    public function index(Request $request)
    {
        $tab = (string) $request->query('tab', 'open');
        $tab = array_key_exists($tab, self::TABS) ? $tab : 'open';
        $technicianId = $request->query('technician_id');
        $technicianId = is_numeric($technicianId) ? (int) $technicianId : null;

        $counts = [];
        foreach (array_keys(self::TABS) as $key) {
            $counts[$key] = $this->query($key, $technicianId)->count();
        }

        return view('admin.technicians.jobs.index', [
            'jobs'         => $this->query($tab, $technicianId)->paginate(50)->withQueryString(),
            'tab'          => $tab,
            'tabs'         => self::TABS,
            'counts'       => $counts,
            'technicianId' => $technicianId,
            'technicians'  => Technician::orderByDesc('is_active')->orderBy('name')->get(['id', 'name', 'is_active']),
            'types'        => TechnicianJobType::cases(),
            'slots'        => TechnicianJob::TIME_SLOTS,
            'today'        => $this->today(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'job_type'      => ['required', Rule::in(TechnicianJobType::values())],
            'vehicle_id'    => ['required', 'string', Rule::exists('vehicle_ad', 'vehicle_id')],
            'technician_id' => ['required', 'integer', Rule::exists('technicians', 'id')->where('is_active', true)],
            'scheduled_for' => ['required', 'date', 'after_or_equal:' . $this->today()],
            'time_slot'     => ['nullable', Rule::in(array_keys(TechnicianJob::TIME_SLOTS))],
            'location_note' => ['nullable', 'string', 'max:255'],
            'instructions'  => ['nullable', 'string', 'max:500'],
        ], [
            'scheduled_for.after_or_equal' => 'A job cannot be scheduled for a day that has already passed.',
            'technician_id.exists'         => 'Choose an active technician.',
            'vehicle_id.exists'            => 'Choose a vehicle from the search results.',
        ]);

        $type = TechnicianJobType::from($data['job_type']);
        $vehicle = VehicleAd::findOrFail($data['vehicle_id']);
        $device = ActivatedDevice::where('vehicle_id', $vehicle->vehicle_id)->first();

        if ($type->needsBoundDevice() && ! $device) {
            throw ValidationException::withMessages([
                'vehicle_id' => "{$vehicle->vehicle_number} has no device bound to it, so a {$type->label()} job does not apply. Schedule an Install first.",
            ]);
        }

        $existing = TechnicianJob::where('vehicle_id', $vehicle->vehicle_id)
            ->where('job_type', $type->value)->where('status', TechnicianJobStatus::Scheduled->value)->first();
        if ($existing) {
            throw $this->alreadyOpen($vehicle->vehicle_number, $type, $existing);
        }

        try {
            $job = TechnicianJob::create([
                'job_type'       => $type->value,
                'status'         => TechnicianJobStatus::Scheduled->value,
                'vehicle_id'     => $vehicle->vehicle_id,
                'vehicle_number' => $vehicle->vehicle_number,
                'customer_id'    => $vehicle->customer_id,
                'customer_name'  => $vehicle->customer_name,
                'imei_number'    => $device?->imei_number,
                'technician_id'  => $data['technician_id'],
                'scheduled_for'  => $data['scheduled_for'],
                'time_slot'      => $data['time_slot'] ?? null,
                'location_note'  => $data['location_note'] ?? null,
                'instructions'   => $data['instructions'] ?? null,
                'created_by'     => auth()->user()?->admin_id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two admins scheduling the same job at the same moment: the database index decides.
            throw $this->alreadyOpen($vehicle->vehicle_number, $type, null);
        }

        $technician = Technician::find($data['technician_id']);
        Audit::record('job.scheduled', 'vehicle', $vehicle->vehicle_id, $vehicle->vehicle_number, [], [
            'job_id' => $job->id, 'type' => $type->value, 'technician' => $technician?->name, 'date' => $job->scheduled_for->format('Y-m-d'),
        ]);

        return back()->with('success', "{$type->label()} job scheduled for {$vehicle->vehicle_number} on {$job->scheduled_for->format('Y-m-d')} ({$technician?->name}).");
    }

    public function reschedule(Request $request, TechnicianJob $job)
    {
        $data = $request->validate([
            'technician_id' => ['required', 'integer', Rule::exists('technicians', 'id')->where('is_active', true)],
            'scheduled_for' => ['required', 'date', 'after_or_equal:' . $this->today()],
            'time_slot'     => ['nullable', Rule::in(array_keys(TechnicianJob::TIME_SLOTS))],
            'location_note' => ['nullable', 'string', 'max:255'],
            'instructions'  => ['nullable', 'string', 'max:500'],
        ], [
            'scheduled_for.after_or_equal' => 'A job cannot be moved to a day that has already passed.',
            'technician_id.exists'         => 'Choose an active technician.',
        ]);

        $result = DB::transaction(function () use ($job, $data) {
            $locked = $this->lockOpen($job);
            if (! $locked) {
                return null;
            }

            $before = $this->snapshot($locked);
            $locked->update([
                'technician_id' => $data['technician_id'],
                'scheduled_for' => $data['scheduled_for'],
                'time_slot'     => $data['time_slot'] ?? null,
                'location_note' => $data['location_note'] ?? null,
                'instructions'  => $data['instructions'] ?? null,
            ]);

            return [$locked, $before, $this->snapshot($locked->fresh())];
        });

        if (! $result) {
            return $this->notOpen($job);
        }

        [$locked, $before, $after] = $result;
        $changes = Audit::diff($before, $after, array_keys($before));
        if ($changes !== []) {
            Audit::record('job.rescheduled', 'vehicle', $locked->vehicle_id, $locked->vehicle_number, $changes, ['job_id' => $locked->id]);
        }

        return back()->with('success', "Job for {$locked->vehicle_number} updated.");
    }

    public function complete(Request $request, TechnicianJob $job)
    {
        $data = $request->validate([
            'completed_on'     => ['required', 'date', 'before_or_equal:' . $this->today()],
            'completion_notes' => ['nullable', 'string', 'max:500'],
        ], ['completed_on.before_or_equal' => 'The completion date cannot be in the future.']);

        $note = null;

        $result = DB::transaction(function () use ($job, $data, &$note) {
            $locked = $this->lockOpen($job);
            if (! $locked) {
                return null;
            }

            $locked->update([
                'status'           => TechnicianJobStatus::Completed->value,
                'completed_on'     => $data['completed_on'],
                'completion_notes' => $data['completion_notes'] ?? null,
                'decided_by'       => auth()->user()?->admin_id,
            ]);

            $installChange = null;
            if ($locked->job_type === TechnicianJobType::Install) {
                [$note, $installChange] = $this->recordInstall($locked, $data['completed_on']);
            } elseif ($locked->job_type === TechnicianJobType::Replace) {
                $note = 'If a device was swapped, record it with Replace Device on Customer Device Management.';
            }

            return [$locked, $installChange];
        });

        if (! $result) {
            return $this->notOpen($job);
        }

        [$locked, $installChange] = $result;
        Audit::record('job.completed', 'vehicle', $locked->vehicle_id, $locked->vehicle_number, [], [
            'job_id' => $locked->id, 'type' => $locked->job_type->value, 'completed_on' => $data['completed_on'],
        ]);
        if ($installChange !== null) {
            Audit::record('install.updated', 'vehicle', $locked->vehicle_id, $locked->vehicle_number, $installChange, [
                'imei' => $locked->imei_number, 'via' => "technician job #{$locked->id}",
            ]);
        }

        return back()->with('success', trim("Job for {$locked->vehicle_number} marked completed. {$note}"));
    }

    public function cancel(Request $request, TechnicianJob $job)
    {
        $data = $request->validate(
            ['cancel_reason' => ['required', 'string', 'max:255']],
            ['cancel_reason.required' => 'Say why the job is being cancelled.']
        );

        $locked = DB::transaction(function () use ($job, $data) {
            $locked = $this->lockOpen($job);
            $locked?->update([
                'status'        => TechnicianJobStatus::Cancelled->value,
                'cancel_reason' => $data['cancel_reason'],
                'decided_by'    => auth()->user()?->admin_id,
            ]);

            return $locked;
        });

        if (! $locked) {
            return $this->notOpen($job);
        }

        Audit::record('job.cancelled', 'vehicle', $locked->vehicle_id, $locked->vehicle_number, [], [
            'job_id' => $locked->id, 'type' => $locked->job_type->value, 'reason' => $data['cancel_reason'],
        ]);

        return back()->with('success', "Job for {$locked->vehicle_number} cancelled.");
    }

    /** Vehicle picker for the New job form: a few matches by vehicle number or customer name. */
    public function vehicles(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        // Vehicle numbers are typed many ways ("WP BGU-1212", "WP BGU 1212", "wpbgu1212"), so the number
        // is compared with spaces, dashes and dots removed on both sides.
        $plain = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($q));
        // % and _ are wildcards in LIKE; escape them so "50%" searches for "50%".
        $nameLike = '%' . mb_strtolower(addcslashes($q, '\\%_')) . '%';
        $stripped = "replace(replace(replace(lower(vehicle_number), ' ', ''), '-', ''), '.', '')";

        $rows = VehicleAd::query()
            ->where(function ($w) use ($plain, $nameLike, $stripped) {
                if (mb_strlen($plain) >= 2) {
                    $w->orWhereRaw("$stripped like ?", ['%' . $plain . '%']);
                }
                $w->orWhereRaw("lower(customer_name) like ? escape '\\'", [$nameLike]);
            })
            ->orderBy('vehicle_number')
            ->limit(15)
            ->get(['vehicle_id', 'vehicle_number', 'customer_name', 'make', 'model']);

        return response()->json($rows->map(fn ($v) => [
            'vehicle_id'     => $v->vehicle_id,
            'vehicle_number' => $v->vehicle_number,
            'customer_name'  => $v->customer_name,
            'label'          => trim(($v->vehicle_number ?: 'Vehicle') . ' — ' . ($v->customer_name ?: 'no customer') . ($v->make ? ' (' . trim($v->make . ' ' . $v->model) . ')' : '')),
        ])->values());
    }

    // ---------------------------------------------------------------------------------------

    private function query(string $tab, ?int $technicianId): Builder
    {
        $today = $this->today();
        $scheduled = TechnicianJobStatus::Scheduled->value;

        $q = TechnicianJob::query()
            ->with('technician:id,name,phone')
            // Customer-ad.customer_id is a uuid in Postgres while technician_jobs.customer_id is text, and
            // Postgres will not compare the two directly, so the join compares them as text.
            ->leftJoin('Customer-ad as c', DB::raw('CAST(c.customer_id AS TEXT)'), '=', 'technician_jobs.customer_id')
            ->select(['technician_jobs.*', 'c.phone_number as customer_phone']);

        if ($technicianId !== null) {
            $q->where('technician_jobs.technician_id', $technicianId);
        }

        return match ($tab) {
            'today'     => $q->where('technician_jobs.status', $scheduled)->whereDate('technician_jobs.scheduled_for', $today)->orderBy('technician_jobs.id'),
            'overdue'   => $q->where('technician_jobs.status', $scheduled)->where('technician_jobs.scheduled_for', '<', $today)->orderBy('technician_jobs.scheduled_for'),
            'completed' => $q->where('technician_jobs.status', TechnicianJobStatus::Completed->value)->orderByDesc('technician_jobs.completed_on')->orderByDesc('technician_jobs.id'),
            'cancelled' => $q->where('technician_jobs.status', TechnicianJobStatus::Cancelled->value)->orderByDesc('technician_jobs.updated_at'),
            'all'       => $q->orderByDesc('technician_jobs.scheduled_for')->orderByDesc('technician_jobs.id'),
            default     => $q->where('technician_jobs.status', $scheduled)->orderBy('technician_jobs.scheduled_for')->orderBy('technician_jobs.id'),
        };
    }

    /** Sri Lanka's calendar day: the app clock is UTC and would be a day behind early in the morning. */
    private function today(): string
    {
        return now()->local()->toDateString();
    }

    /** Re-reads the job under a row lock and returns it only while it is still scheduled. */
    private function lockOpen(TechnicianJob $job): ?TechnicianJob
    {
        $locked = TechnicianJob::whereKey($job->id)->lockForUpdate()->first();

        return $locked && $locked->status === TechnicianJobStatus::Scheduled ? $locked : null;
    }

    private function notOpen(TechnicianJob $job)
    {
        $label = TechnicianJob::find($job->id)?->status->label();

        return back()->withErrors(['job' => 'This job is already ' . strtolower($label ?? 'removed') . ' and cannot be changed. Refresh the page.']);
    }

    private function alreadyOpen(?string $vehicleNumber, TechnicianJobType $type, ?TechnicianJob $existing): ValidationException
    {
        $when = $existing ? ' on ' . $existing->scheduled_for->format('Y-m-d') : '';

        return ValidationException::withMessages([
            'vehicle_id' => "{$vehicleNumber} already has a scheduled {$type->label()} job{$when}. Change or cancel that one first.",
        ]);
    }

    /** @return array{technician: ?string, scheduled_for: string, time_slot: ?string, location_note: ?string, instructions: ?string} */
    private function snapshot(TechnicianJob $job): array
    {
        return [
            'technician'    => Technician::whereKey($job->technician_id)->value('name'),
            'scheduled_for' => $job->scheduled_for->format('Y-m-d'),
            'time_slot'     => $job->time_slot,
            'location_note' => $job->location_note,
            'instructions'  => $job->instructions,
        ];
    }

    /**
     * Fills the bound device's install details from a completed install job, only if it has none yet.
     *
     * @return array{0: string, 1: ?array}  [message for the admin, audit changes or null]
     */
    private function recordInstall(TechnicianJob $job, string $completedOn): array
    {
        $device = ActivatedDevice::where('vehicle_id', $job->vehicle_id)->lockForUpdate()->first();

        if (! $device) {
            return ['This vehicle has no device bound yet, so enter the install details when you bind it.', null];
        }
        if ($device->installed_on) {
            return ['The device already has an install date, so it was left unchanged.', null];
        }

        $name = Technician::whereKey($job->technician_id)->value('name');
        $device->update(['installed_on' => $completedOn, 'installed_by' => $name]);

        return ['The device\'s install details were filled in.', [
            'installed_on' => ['from' => null, 'to' => $completedOn],
            'installed_by' => ['from' => null, 'to' => $name],
        ]];
    }
}