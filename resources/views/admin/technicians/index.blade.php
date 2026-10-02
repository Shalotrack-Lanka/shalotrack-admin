@extends('layouts.admin')

@section('title', 'Technicians')

@section('content')
<div class="space-y-5" x-data="{ editing: null }">
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-black text-blue-950">Technicians</h1>
                <p class="text-xs text-slate-500 mt-0.5">
                    The people you send to fit and fix devices. They are a list to schedule jobs for, not login accounts.
                    Deactivate a technician who has left: their past jobs stay on record.
                </p>
            </div>
            <a href="{{ route('admin.technician-jobs') }}" class="px-4 py-2 rounded-xl text-xs font-bold border border-slate-300 bg-white text-slate-700 hover:bg-slate-50">Technician jobs →</a>
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

    <form method="POST" action="{{ route('admin.technicians.store') }}" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 flex flex-wrap items-end gap-3">
        @csrf
        <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Name</label>
            <input type="text" name="name" value="{{ old('name') }}" maxlength="100" required class="rounded-lg border-slate-300 text-sm w-64">
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Phone (optional)</label>
            <input type="text" name="phone" value="{{ old('phone') }}" maxlength="20" class="rounded-lg border-slate-300 text-sm w-48">
        </div>
        <button type="submit" class="bg-blue-950 text-white text-xs font-bold px-4 py-2.5 rounded-lg hover:bg-blue-900">Add technician</button>
    </form>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead class="bg-slate-50 border-b border-slate-200 text-[11px] text-slate-600 uppercase tracking-wide">
                    <tr>
                        <th class="p-3">Name</th>
                        <th class="p-3">Phone</th>
                        <th class="p-3">Scheduled jobs</th>
                        <th class="p-3">Status</th>
                        <th class="p-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-sm text-slate-700">
                    @forelse($technicians as $t)
                        <tr>
                            <td class="p-3 font-semibold">{{ $t->name }}</td>
                            <td class="p-3">{{ $t->phone ?: '—' }}</td>
                            <td class="p-3">
                                @if($t->open_jobs)
                                    <a href="{{ route('admin.technician-jobs', ['technician_id' => $t->id]) }}" class="text-blue-700 underline">{{ $t->open_jobs }}</a>
                                @else
                                    0
                                @endif
                            </td>
                            <td class="p-3">
                                <span class="text-[11px] font-bold px-2 py-0.5 rounded-full {{ $t->is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ $t->is_active ? 'Active' : 'Inactive' }}</span>
                            </td>
                            <td class="p-3 text-right whitespace-nowrap">
                                <button type="button" @click="editing = editing === {{ $t->id }} ? null : {{ $t->id }}" class="text-xs font-bold text-blue-700 hover:underline mr-3">Edit</button>
                                <form method="POST" action="{{ route('admin.technicians.update', $t) }}" class="inline">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="name" value="{{ $t->name }}">
                                    <input type="hidden" name="phone" value="{{ $t->phone }}">
                                    <input type="hidden" name="is_active" value="{{ $t->is_active ? 0 : 1 }}">
                                    <button type="submit" class="text-xs font-bold {{ $t->is_active ? 'text-red-700' : 'text-emerald-700' }} hover:underline">{{ $t->is_active ? 'Deactivate' : 'Activate' }}</button>
                                </form>
                            </td>
                        </tr>
                        <tr x-show="editing === {{ $t->id }}" x-cloak class="bg-slate-50">
                            <td colspan="5" class="p-3">
                                <form method="POST" action="{{ route('admin.technicians.update', $t) }}" class="flex flex-wrap items-end gap-3">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="is_active" value="{{ $t->is_active ? 1 : 0 }}">
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-700 mb-1">Name</label>
                                        <input type="text" name="name" value="{{ $t->name }}" maxlength="100" required class="rounded-lg border-slate-300 text-sm w-64">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-700 mb-1">Phone</label>
                                        <input type="text" name="phone" value="{{ $t->phone }}" maxlength="20" class="rounded-lg border-slate-300 text-sm w-48">
                                    </div>
                                    <button type="submit" class="bg-blue-950 text-white text-xs font-bold px-4 py-2.5 rounded-lg">Save</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-6 text-center text-sm text-slate-500">No technicians yet. Add the first one above.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection