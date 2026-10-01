@extends('layouts.admin')

@section('title', 'Audit log')

@section('content')
@php
    use App\Models\AuditLog;
    $tone = ['money' => 'bg-emerald-100 text-emerald-700', 'device' => 'bg-blue-100 text-blue-700', 'account' => 'bg-violet-100 text-violet-700'];
    $show = fn ($v) => is_null($v) ? '∅' : (is_bool($v) ? ($v ? 'yes' : 'no') : (is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)));
@endphp

<div class="space-y-5">
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <h1 class="text-2xl font-black text-blue-950">Audit log</h1>
        <p class="text-xs text-slate-500 mt-0.5">Who changed what, and when. Entries cannot be edited or deleted; they are removed automatically after 12 months.</p>
    </div>

    <form method="GET" action="{{ route('admin.audit-log') }}" class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm grid grid-cols-2 md:grid-cols-6 gap-3 text-xs">
        <label class="font-bold text-slate-600">Category
            <select name="category" class="mt-1 w-full border border-slate-300 rounded-xl px-2 py-2 font-normal bg-slate-50">
                <option value="">All</option>
                @foreach($categories as $key => $label)
                    <option value="{{ $key }}" @selected($f['category'] === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="font-bold text-slate-600">Action
            <select name="action" class="mt-1 w-full border border-slate-300 rounded-xl px-2 py-2 font-normal bg-slate-50">
                <option value="">All</option>
                @foreach($actions as $key => [$cat, $label])
                    <option value="{{ $key }}" @selected($f['action'] === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="font-bold text-slate-600">Who
            <input type="text" name="actor" value="{{ $f['actor'] }}" maxlength="100" placeholder="name or login id" class="mt-1 w-full border border-slate-300 rounded-xl px-2 py-2 font-normal">
        </label>
        <label class="font-bold text-slate-600">IMEI / vehicle / dealer
            <input type="text" name="q" value="{{ $f['q'] }}" maxlength="100" class="mt-1 w-full border border-slate-300 rounded-xl px-2 py-2 font-normal">
        </label>
        <label class="font-bold text-slate-600">From
            <input type="date" name="from" value="{{ $f['from'] }}" class="mt-1 w-full border border-slate-300 rounded-xl px-2 py-2 font-normal">
        </label>
        <label class="font-bold text-slate-600">To
            <input type="date" name="to" value="{{ $f['to'] }}" class="mt-1 w-full border border-slate-300 rounded-xl px-2 py-2 font-normal">
        </label>
        <div class="col-span-2 md:col-span-6 flex flex-wrap gap-2">
            <button class="px-4 py-2 rounded-xl text-xs font-bold bg-blue-950 text-white hover:bg-blue-900">Filter</button>
            <a href="{{ route('admin.audit-log') }}" class="px-4 py-2 rounded-xl text-xs font-bold border border-slate-300 bg-white text-slate-700">Clear</a>
            <a href="{{ route('admin.audit-log', array_filter($f) + ['export' => 'csv']) }}" class="px-4 py-2 rounded-xl text-xs font-bold border border-slate-300 bg-white text-slate-700">Export CSV (max 5,000 rows)</a>
        </div>
    </form>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead class="bg-slate-50 border-b border-slate-200 text-[11px] text-slate-600 uppercase tracking-wide">
                    <tr>
                        <th class="p-3">When (Sri Lanka)</th>
                        <th class="p-3">Who</th>
                        <th class="p-3">What</th>
                        <th class="p-3">Subject</th>
                        <th class="p-3">Details</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-sm text-slate-700 align-top">
                    @forelse($rows as $r)
                        <tr>
                            <td class="p-3 whitespace-nowrap text-xs">{{ $r->occurred_at->copy()->local()->format('Y-m-d H:i:s') }}</td>
                            <td class="p-3">
                                <div class="font-semibold">{{ $r->actor_name ?: ($r->actor_id ?: 'Not signed in') }}</div>
                                <div class="text-[11px] text-slate-400">{{ $r->actor_role }} @if($r->ip)· {{ $r->ip }}@endif</div>
                            </td>
                            <td class="p-3">
                                <span class="inline-block px-2 py-0.5 rounded-lg text-[11px] font-bold {{ $tone[$r->category] ?? 'bg-slate-100 text-slate-600' }}">{{ AuditLog::labelFor($r->action) }}</span>
                            </td>
                            <td class="p-3 text-xs">
                                {{ $r->subject_label ?: $r->subject_id }}
                                <div class="text-[11px] text-slate-400">{{ $r->subject_type }}</div>
                            </td>
                            <td class="p-3 text-xs">
                                @foreach(($r->changes ?? []) as $field => $c)
                                    <div><span class="font-semibold">{{ $field }}</span>: <span class="text-red-700">{{ $show($c['from'] ?? null) }}</span> → <span class="text-emerald-700">{{ $show($c['to'] ?? null) }}</span></div>
                                @endforeach
                                @foreach(($r->meta ?? []) as $k => $v)
                                    <div class="text-slate-500"><span class="font-semibold">{{ $k }}</span>: {{ $show($v) }}</div>
                                @endforeach
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-8 text-center text-slate-400 text-sm">No entries match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($rows->hasPages())
            <div class="p-3 border-t border-slate-100">{{ $rows->links() }}</div>
        @endif
    </div>
</div>
@endsection