@extends('layouts.admin')

@section('title', 'Technician jobs')

@section('content')
@php
    $typeTone = ['install' => 'bg-blue-100 text-blue-700', 'repair' => 'bg-amber-100 text-amber-700', 'replace' => 'bg-purple-100 text-purple-700', 'removal' => 'bg-slate-200 text-slate-700'];
    $activeTechs = $technicians->where('is_active', true);
@endphp

<div class="space-y-5"
     x-data="{
        newOpen: {{ $errors->has('vehicle_id') || $errors->has('technician_id') || $errors->has('scheduled_for') || $errors->has('job_type') ? 'true' : 'false' }},
        q: '', results: [], picked: null, searching: false, failed: false, timer: null,
        search() {
            clearTimeout(this.timer); this.picked = null;
            if (this.q.trim().length < 2) { this.results = []; return; }
            this.timer = setTimeout(async () => {
                this.searching = true; this.failed = false;
                try {
                    const r = await fetch('{{ route('admin.technician-jobs.vehicles') }}?q=' + encodeURIComponent(this.q.trim()), { headers: { 'Accept': 'application/json' } });
                    if (!r.ok) { this.failed = true; this.results = []; } else { this.results = await r.json(); }
                } catch (e) { this.failed = true; this.results = []; }
                this.searching = false;
            }, 250);
        },
        pick(v) { this.picked = v; this.q = v.label; this.results = []; },
        action: null, job: null,
        open(kind, job) { this.action = kind; this.job = job; }
     }"
     @keydown.escape.window="action = null; newOpen = false">

    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm space-y-3">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-black text-blue-950">Technician jobs</h1>
                <p class="text-xs text-slate-500 mt-0.5">
                    Schedule installs, repairs, device replacements and removals. Finishing an install fills the device's install
                    details if it has none. Manage the people on the
                    <a href="{{ route('admin.technicians') }}" class="text-blue-700 underline">Technicians</a> page.
                </p>
            </div>
            <button type="button" @click="newOpen = true" class="bg-blue-950 text-white text-xs font-bold px-4 py-2.5 rounded-lg hover:bg-blue-900">+ New job</button>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @foreach($tabs as $key => $label)
                <a href="{{ route('admin.technician-jobs', array_filter(['tab' => $key, 'technician_id' => $technicianId])) }}"
                   class="px-4 py-2 rounded-xl text-xs font-bold border {{ $tab === $key ? ($key === 'overdue' ? 'bg-red-700 text-white border-red-700' : 'bg-blue-950 text-white border-blue-950') : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50' }}">
                    {{ $label }} ({{ $counts[$key] }})
                </a>
            @endforeach

            <form method="GET" action="{{ route('admin.technician-jobs') }}" class="flex items-center gap-2">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <select name="technician_id" onchange="this.form.submit()" class="text-xs font-bold border border-slate-300 rounded-xl px-3 py-2 bg-slate-50">
                    <option value="">All technicians</option>
                    @foreach($technicians as $t)
                        <option value="{{ $t->id }}" {{ $technicianId === $t->id ? 'selected' : '' }}>{{ $t->name }}{{ $t->is_active ? '' : ' (inactive)' }}</option>
                    @endforeach
                </select>
            </form>
        </div>
    </div>

    @if(session('success'))
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded-xl p-4">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-800 text-sm rounded-xl p-4">
            @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
        </div>
    @endif

    @if($activeTechs->isEmpty())
        <div class="bg-amber-50 border border-amber-300 text-amber-900 text-sm rounded-xl p-4">
            There is no active technician yet. <a href="{{ route('admin.technicians') }}" class="underline font-semibold">Add one</a> before scheduling a job.
        </div>
    @endif

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead class="bg-slate-50 border-b border-slate-200 text-[11px] text-slate-600 uppercase tracking-wide">
                    <tr>
                        <th class="p-3">When</th>
                        <th class="p-3">Job</th>
                        <th class="p-3">Vehicle / customer</th>
                        <th class="p-3">Technician</th>
                        <th class="p-3">Where / notes</th>
                        <th class="p-3">Status</th>
                        <th class="p-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-sm text-slate-700">
                    @forelse($jobs as $j)
                        @php
                            $overdue = $j->isOverdue();
                            $jobData = [
                                'id' => $j->id, 'vehicle' => $j->vehicle_number, 'type' => $j->job_type->label(),
                                'technician_id' => $j->technician_id, 'scheduled_for' => $j->scheduled_for->format('Y-m-d'),
                                'time_slot' => $j->time_slot, 'location_note' => $j->location_note, 'instructions' => $j->instructions,
                                'is_install' => $j->job_type === \App\Enums\TechnicianJobType::Install,
                                'complete_url' => route('admin.technician-jobs.complete', $j),
                                'cancel_url' => route('admin.technician-jobs.cancel', $j),
                                'reschedule_url' => route('admin.technician-jobs.reschedule', $j),
                            ];
                        @endphp
                        <tr class="{{ $overdue ? 'bg-red-50/40' : '' }}">
                            <td class="p-3 whitespace-nowrap">
                                <div class="font-semibold">{{ $j->scheduled_for->format('Y-m-d') }}</div>
                                @if($j->time_slot)<div class="text-[11px] text-slate-500">{{ $slots[$j->time_slot] ?? $j->time_slot }}</div>@endif
                                @if($overdue)<div class="text-[11px] font-bold text-red-700">Overdue</div>@endif
                            </td>
                            <td class="p-3"><span class="text-[11px] font-bold px-2 py-0.5 rounded-full {{ $typeTone[$j->job_type->value] ?? 'bg-slate-100' }}">{{ $j->job_type->label() }}</span></td>
                            <td class="p-3">
                                <div class="font-semibold">{{ $j->vehicle_number ?: '—' }}</div>
                                <div class="text-xs text-slate-600">{{ $j->customer_name ?: '—' }}</div>
                                @if($j->customer_phone)
                                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $j->customer_phone) }}" class="text-xs text-blue-700 hover:underline">{{ $j->customer_phone }}</a>
                                @endif
                            </td>
                            <td class="p-3">
                                {{ $j->technician?->name ?: '—' }}
                                @if($j->technician?->phone)<div class="text-[11px] text-slate-500">{{ $j->technician->phone }}</div>@endif
                            </td>
                            <td class="p-3 max-w-xs text-xs">
                                @if($j->location_note)<div>{{ $j->location_note }}</div>@endif
                                @if($j->instructions)<div class="text-slate-500">{{ $j->instructions }}</div>@endif
                                @if($j->completion_notes)<div class="text-emerald-700">Done: {{ $j->completion_notes }}</div>@endif
                                @if($j->cancel_reason)<div class="text-slate-500">Cancelled: {{ $j->cancel_reason }}</div>@endif
                            </td>
                            <td class="p-3 whitespace-nowrap">
                                @if($j->status === \App\Enums\TechnicianJobStatus::Completed)
                                    <span class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700">Completed</span>
                                    <div class="text-[11px] text-slate-500 mt-0.5">{{ $j->completed_on?->format('Y-m-d') }}</div>
                                @elseif($j->status === \App\Enums\TechnicianJobStatus::Cancelled)
                                    <span class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">Cancelled</span>
                                @else
                                    <span class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-blue-100 text-blue-700">Scheduled</span>
                                @endif
                            </td>
                            <td class="p-3 text-right whitespace-nowrap">
                                @if($j->status === \App\Enums\TechnicianJobStatus::Scheduled)
                                    <button type="button" @click='open("complete", @json($jobData))' class="text-xs font-bold text-emerald-700 hover:underline mr-2">Complete</button>
                                    <button type="button" @click='open("reschedule", @json($jobData))' class="text-xs font-bold text-blue-700 hover:underline mr-2">Change</button>
                                    <button type="button" @click='open("cancel", @json($jobData))' class="text-xs font-bold text-red-700 hover:underline">Cancel</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="p-6 text-center text-sm text-slate-500">Nothing here.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $jobs->links() }}</div>
    </div>

    {{-- ============ NEW JOB ============ --}}
    <div x-show="newOpen" x-cloak class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
        <div @click.outside="newOpen = false" class="bg-white rounded-xl shadow-lg w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-800">New technician job</h3>
                <button type="button" @click="newOpen = false" class="text-gray-400 hover:text-gray-600">&#10005;</button>
            </div>
            <form method="POST" action="{{ route('admin.technician-jobs.store') }}" class="p-5 space-y-4">
                @csrf
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Job type</label>
                    <select name="job_type" required class="w-full rounded-lg border-gray-300 text-sm">
                        @foreach($types as $t)
                            <option value="{{ $t->value }}" {{ old('job_type') === $t->value ? 'selected' : '' }}>{{ $t->label() }}</option>
                        @endforeach
                    </select>
                    <p class="text-[11px] text-gray-400 mt-1">Repair, replace and removal need a vehicle that already has a device bound.</p>
                </div>

                <div class="relative">
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Vehicle (search by number or customer)</label>
                    <input type="text" x-model="q" @input="search()" autocomplete="off" placeholder="e.g. CAB-1234"
                        class="w-full rounded-lg border-gray-300 text-sm">
                    <input type="hidden" name="vehicle_id" :value="picked ? picked.vehicle_id : ''" required>
                    <p x-show="searching" class="text-[11px] text-gray-400 mt-1">Searching…</p>
                    <ul x-show="results.length" class="absolute z-10 left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow max-h-56 overflow-y-auto text-sm">
                        <template x-for="v in results" :key="v.vehicle_id">
                            <li @click="pick(v)" class="px-3 py-2 hover:bg-blue-50 cursor-pointer" x-text="v.label"></li>
                        </template>
                    </ul>
                    <p x-show="failed" class="text-[11px] text-red-700 mt-1">The search failed. Reload the page and try again.</p>
                    <p x-show="!failed && !picked && q.trim().length >= 2 && !searching && !results.length" class="text-[11px] text-amber-700 mt-1">No vehicle found.</p>
                    <p x-show="picked" class="text-[11px] text-emerald-700 mt-1">Vehicle selected.</p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">Technician</label>
                        <select name="technician_id" required class="w-full rounded-lg border-gray-300 text-sm">
                            <option value="" disabled selected>-- Select --</option>
                            @foreach($activeTechs as $t)
                                <option value="{{ $t->id }}" {{ (string) old('technician_id') === (string) $t->id ? 'selected' : '' }}>{{ $t->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">Date</label>
                        <input type="date" name="scheduled_for" required min="{{ $today }}" value="{{ old('scheduled_for', $today) }}" class="w-full rounded-lg border-gray-300 text-sm">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Time of day (optional)</label>
                    <select name="time_slot" class="w-full rounded-lg border-gray-300 text-sm">
                        <option value="">Any time</option>
                        @foreach($slots as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Where (optional)</label>
                    <input type="text" name="location_note" maxlength="255" value="{{ old('location_note') }}" placeholder="Address or landmark" class="w-full rounded-lg border-gray-300 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Instructions (optional)</label>
                    <textarea name="instructions" maxlength="500" rows="2" class="w-full rounded-lg border-gray-300 text-sm">{{ old('instructions') }}</textarea>
                </div>
                <button type="submit" :disabled="!picked" class="w-full bg-blue-950 hover:bg-blue-900 disabled:opacity-40 text-white font-semibold px-4 py-2.5 rounded-lg text-sm">Schedule job</button>
            </form>
        </div>
    </div>

    {{-- ============ COMPLETE / CHANGE / CANCEL ============ --}}
    <div x-show="action" x-cloak class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
        <div @click.outside="action = null" class="bg-white rounded-xl shadow-lg w-full max-w-md max-h-[90vh] overflow-y-auto">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-bold text-gray-800"
                        x-text="action === 'complete' ? 'Mark job completed' : (action === 'cancel' ? 'Cancel job' : 'Change job')"></h3>
                    <p class="text-xs text-gray-500" x-text="job ? job.type + ' · ' + job.vehicle : ''"></p>
                </div>
                <button type="button" @click="action = null" class="text-gray-400 hover:text-gray-600">&#10005;</button>
            </div>

            <template x-if="action === 'complete' && job">
                <form method="POST" :action="job.complete_url" class="p-5 space-y-4">
                    @csrf
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">Completed on</label>
                        <input type="date" name="completed_on" required max="{{ $today }}" value="{{ $today }}" class="w-full rounded-lg border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">Notes (optional)</label>
                        <textarea name="completion_notes" maxlength="500" rows="2" class="w-full rounded-lg border-gray-300 text-sm"></textarea>
                    </div>
                    <p x-show="job.is_install" class="text-[11px] text-gray-500">
                        This also fills the device's install date and installer, if the device is bound and has none yet.
                    </p>
                    <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold px-4 py-2.5 rounded-lg text-sm">Mark completed</button>
                </form>
            </template>

            <template x-if="action === 'reschedule' && job">
                <form method="POST" :action="job.reschedule_url" class="p-5 space-y-4">
                    @csrf @method('PATCH')
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">Technician</label>
                            <select name="technician_id" required class="w-full rounded-lg border-gray-300 text-sm">
                                @foreach($activeTechs as $t)
                                    <option value="{{ $t->id }}" :selected="job.technician_id === {{ $t->id }}">{{ $t->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">Date</label>
                            <input type="date" name="scheduled_for" required min="{{ $today }}" :value="job.scheduled_for" class="w-full rounded-lg border-gray-300 text-sm">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">Time of day</label>
                        <select name="time_slot" class="w-full rounded-lg border-gray-300 text-sm">
                            <option value="" :selected="!job.time_slot">Any time</option>
                            @foreach($slots as $key => $label)<option value="{{ $key }}" :selected="job.time_slot === '{{ $key }}'">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">Where</label>
                        <input type="text" name="location_note" maxlength="255" :value="job.location_note || ''" class="w-full rounded-lg border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">Instructions</label>
                        <textarea name="instructions" maxlength="500" rows="2" class="w-full rounded-lg border-gray-300 text-sm" x-text="job.instructions || ''"></textarea>
                    </div>
                    <button type="submit" class="w-full bg-blue-950 hover:bg-blue-900 text-white font-semibold px-4 py-2.5 rounded-lg text-sm">Save changes</button>
                </form>
            </template>

            <template x-if="action === 'cancel' && job">
                <form method="POST" :action="job.cancel_url" class="p-5 space-y-4">
                    @csrf
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">Reason</label>
                        <input type="text" name="cancel_reason" required maxlength="255" placeholder="e.g. customer not available" class="w-full rounded-lg border-gray-300 text-sm">
                    </div>
                    <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-semibold px-4 py-2.5 rounded-lg text-sm">Cancel job</button>
                </form>
            </template>
        </div>
    </div>
</div>
@endsection