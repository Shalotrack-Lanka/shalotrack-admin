<?php

namespace App\Http\Controllers\Admin\Technicians;

use App\Enums\TechnicianJobStatus;
use App\Http\Controllers\Controller;
use App\Models\Technician;
use App\Services\Audit;
use Illuminate\Http\Request;

/**
 * The list of field technicians. They are names the admin schedules jobs for, not login accounts.
 * A technician is deactivated, never deleted, so old jobs keep their history.
 */
class TechnicianController extends Controller
{
    public function index()
    {
        $technicians = Technician::query()
            ->withCount(['jobs as open_jobs' => fn ($q) => $q->where('status', TechnicianJobStatus::Scheduled->value)])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return view('admin.technicians.index', ['technicians' => $technicians]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());

        $technician = Technician::create($data + ['is_active' => true]);

        Audit::record('technician.created', 'technician', $technician->id, $technician->name, [], ['phone' => $technician->phone]);

        return back()->with('success', "{$technician->name} added.");
    }

    public function update(Request $request, Technician $technician)
    {
        $data = $request->validate($this->rules($technician) + ['is_active' => ['sometimes', 'boolean']]);

        $before = $technician->only(['name', 'phone', 'is_active']);
        $technician->update($data);
        $changes = Audit::diff($before, $technician->fresh()->only(['name', 'phone', 'is_active']), ['name', 'phone', 'is_active']);

        if ($changes !== []) {
            Audit::record('technician.updated', 'technician', $technician->id, $technician->name, $changes);
        }

        return back()->with('success', "{$technician->name} updated.");
    }

    private function rules(?Technician $technician = null): array
    {
        return [
            'name'  => ['bail', 'required', 'string', 'max:100', function ($attribute, $value, $fail) use ($technician) {
                // Case-insensitive: "Kamal" and "kamal" are the same person on the job sheet. (A plain unique rule is
                // case-sensitive on PostgreSQL and would accept both.)
                $taken = Technician::whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $value))])
                    ->when($technician, fn ($q) => $q->where('id', '!=', $technician->id))
                    ->exists();

                if ($taken) {
                    $fail('A technician with this name already exists.');
                }
            }],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{7,20}$/'],
        ];
    }
}