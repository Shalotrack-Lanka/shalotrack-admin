@extends('layouts.admin')

@section('title', 'Account directory')

@section('content')
@php
    $input = 'block mt-1 w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500';
    $label = 'block text-xs font-medium text-slate-700';
    $button = 'inline-flex items-center px-3 py-2 rounded-md bg-[#1B2E5E] text-white text-xs font-semibold uppercase tracking-widest hover:opacity-90';
    $tempPassword = session('temp_password');
    $me = Auth::user();
@endphp

<div class="space-y-5">
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <h1 class="text-2xl font-black text-blue-950">Account directory</h1>
        <p class="text-xs text-slate-500 mt-0.5">Every login in the ERP, all roles. Find a person, check it really is them (call the number you have), then set a temporary password. They must choose their own at the next sign-in. The audit log records each reset, never the password.</p>
        <a href="{{ route('admin.profile') }}" class="mt-2 inline-block text-xs underline text-slate-500">&larr; Back to my profile</a>
    </div>

    @include('admin.partials.temp-password-banner', ['tempPassword' => $tempPassword])

    @foreach ($errors->resetAccount->all() as $m)
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">{{ $m }}</div>
    @endforeach

    <form method="GET" action="{{ route('admin.accounts.index') }}" class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm grid grid-cols-2 md:grid-cols-4 gap-3">
        <label class="{{ $label }} col-span-2">Search
            <input type="text" name="q" value="{{ $q }}" maxlength="100" placeholder="name, username, email or phone" class="{{ $input }}">
        </label>
        <label class="{{ $label }}">Role
            <select name="role" class="{{ $input }}">
                <option value="">All roles</option>
                @foreach ($roles as $r)<option value="{{ $r }}" @selected($role === $r)>{{ $r }}</option>@endforeach
            </select>
        </label>
        <label class="{{ $label }}">Status
            <select name="status" class="{{ $input }}">
                <option value="">Any</option>
                <option value="ACTIVE" @selected($status === 'ACTIVE')>Active</option>
                <option value="INACTIVE" @selected($status === 'INACTIVE')>Inactive</option>
            </select>
        </label>
        <div class="col-span-2 md:col-span-4 flex gap-3">
            <button type="submit" class="{{ $button }}">Search</button>
            <a href="{{ route('admin.accounts.index') }}" class="text-xs underline text-slate-500 self-center">Clear</a>
        </div>
    </form>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm divide-y divide-slate-100">
        @forelse ($rows as $a)
            @php $isMe = $a->admin_id === $me->admin_id; @endphp
            <div class="p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <p class="text-sm font-semibold text-slate-900">
                        {{ $a->full_name ?: $a->username }}
                        <span class="font-normal text-slate-500">({{ $a->username }})</span>
                        @if ($isMe)<span class="ml-1 text-xs text-indigo-700">you</span>@endif
                    </p>
                    <div class="flex flex-wrap gap-1 text-[11px] font-semibold">
                        <span class="rounded-full bg-slate-200 text-slate-700 px-2 py-0.5">{{ $a->role }}</span>
                        <span class="rounded-full px-2 py-0.5 {{ $a->status === 'ACTIVE' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700' }}">{{ $a->status }}</span>
                        @if ($a->must_change_password)<span class="rounded-full bg-amber-100 text-amber-800 px-2 py-0.5">must change password</span>@endif
                        @if (isset($pending[$a->admin_id]))<span class="rounded-full bg-red-100 text-red-700 px-2 py-0.5">asked for a reset</span>@endif
                    </div>
                </div>
                <p class="mt-1 text-xs text-slate-500 break-all">
                    {{ $a->email ?: 'no email' }} &middot; {{ $a->phone_number ?: 'no phone' }} &middot;
                    password changed {{ $a->password_changed_at ? $a->password_changed_at->local()->format('Y-m-d H:i') : 'never / not since a reset' }}
                </p>

                @if (! $isMe && $a->status === 'ACTIVE')
                    <details class="mt-2 group">
                        <summary class="cursor-pointer text-xs text-indigo-700 hover:underline">Set temporary password</summary>
                        <form method="POST" action="{{ route('admin.accounts.temporary-password', $a->admin_id) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                            @csrf
                            <div>
                                <label for="tp_{{ $a->admin_id }}" class="{{ $label }}">Temporary password (optional, leave empty to generate)</label>
                                <input id="tp_{{ $a->admin_id }}" name="temporary_password" type="text" autocomplete="off" maxlength="100" class="{{ $input }} font-mono">
                            </div>
                            <div>
                                <label for="me_{{ $a->admin_id }}" class="{{ $label }}">Your password</label>
                                <x-password-input id="me_{{ $a->admin_id }}" name="current_password" required autocomplete="current-password" class="text-sm" />
                            </div>
                            <div class="sm:col-span-2"><button type="submit" class="{{ $button }}">Set temporary password for {{ $a->username }}</button></div>
                        </form>
                    </details>
                @endif
            </div>
        @empty
            <p class="p-6 text-sm text-slate-500">No accounts match.</p>
        @endforelse
    </div>

    {{ $rows->links() }}
</div>
@endsection