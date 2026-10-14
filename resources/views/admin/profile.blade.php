@extends(Auth::user()->role === 'ADMIN' ? 'layouts.admin' : 'layouts.simple')

@section('title', 'My Profile')

@section('content')
    @php
        $me = Auth::user();
        $isAdmin = $me->role === 'ADMIN';
        $updateRoute = match ($me->role) {
            'FINANCE'    => 'finance.profile.update',
            'TECHNICIAN' => 'technician.profile.update',
            default      => 'admin.profile.update',
        };
        $input = 'block mt-1 w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500';
        $label = 'block text-sm font-medium text-slate-700';
        $err   = 'mt-1 text-xs text-red-600';
        $button = 'inline-flex items-center px-4 py-2 rounded-md bg-[#1B2E5E] text-white text-xs font-semibold uppercase tracking-widest hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500';
        // Both forms use the same field names (full_name, email ...): re-fill each one from the submission
        // only when THAT form is the one that failed.
        $refillProfile = $errors->hasBag('profile');
        $refillAdd = $errors->hasBag('addAdmin');
        $tempPassword = session('temp_password');
        $adminSectionOpen = $isAdmin && ($tempPassword
            || $errors->hasBag('addAdmin') || $errors->hasBag('resetRequest')
            || session('admin_section'));
    @endphp

    <div class="max-w-2xl space-y-6">

        @if (session('success'))
            <div class="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800" role="status">
                {{ session('success') }}
            </div>
        @endif

        {{-- Who I am --}}
        <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100">
                <h1 class="text-lg font-bold text-slate-900">{{ $me->full_name }}</h1>
                <p class="text-sm text-slate-500">{{ $me->role }}</p>
            </div>
            <dl class="divide-y divide-gray-100 text-sm">
                @foreach ([
                    'Username' => $me->username,
                    'Email'    => $me->email,
                    'Phone'    => $me->phone_number,
                    'Status'   => $me->status,
                    'Password last changed' => $me->password_changed_at ? $me->password_changed_at->local()->format('Y-m-d H:i') : null,
                ] as $labelText => $value)
                    <div class="px-6 py-3 flex justify-between gap-4">
                        <dt class="text-slate-500">{{ $labelText }}</dt>
                        <dd class="font-medium text-slate-900 text-right break-all">{{ $value ?: '—' }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        {{-- Edit my details --}}
        <form method="POST" action="{{ route($updateRoute) }}" class="bg-white rounded-xl shadow-md border border-gray-200 p-6 space-y-4">
            @csrf
            @method('PUT')
            <h2 class="text-base font-bold text-slate-900">Edit my details</h2>
            <p class="text-xs text-slate-500">Your username, role and status can only be changed by another administrator.</p>

            <div>
                <label for="pf_full_name" class="{{ $label }}">Full name</label>
                <input id="pf_full_name" name="full_name" type="text" required maxlength="150" class="{{ $input }}"
                       value="{{ ($refillProfile ? old('full_name', $me->full_name) : $me->full_name) }}" autocomplete="name">
                @foreach ($errors->profile->get('full_name') as $m)<p class="{{ $err }}">{{ $m }}</p>@endforeach
            </div>
            <div>
                <label for="pf_email" class="{{ $label }}">Email</label>
                <input id="pf_email" name="email" type="email" required maxlength="190" class="{{ $input }}"
                       value="{{ ($refillProfile ? old('email', $me->email) : $me->email) }}" autocomplete="email">
                @foreach ($errors->profile->get('email') as $m)<p class="{{ $err }}">{{ $m }}</p>@endforeach
            </div>
            <div>
                <label for="pf_phone" class="{{ $label }}">Phone</label>
                <input id="pf_phone" name="phone_number" type="tel" maxlength="20" class="{{ $input }}"
                       value="{{ ($refillProfile ? old('phone_number', $me->phone_number) : $me->phone_number) }}" autocomplete="tel">
                @foreach ($errors->profile->get('phone_number') as $m)<p class="{{ $err }}">{{ $m }}</p>@endforeach
            </div>
            <button type="submit" class="{{ $button }}">Save details</button>
        </form>

        {{-- Change my password --}}
        <form method="POST" action="{{ route('account.password.update') }}" class="bg-white rounded-xl shadow-md border border-gray-200 p-6 space-y-4">
            @csrf
            @method('PUT')
            <h2 class="text-base font-bold text-slate-900">Change my password</h2>
            <p class="text-xs text-slate-500">{{ \App\Support\PasswordPolicy::hint() }} Other browsers you are signed in on are signed out when you change it.</p>

            <div>
                <label for="cp_current" class="{{ $label }}">Current password</label>
                <input id="cp_current" name="current_password" type="password" required autocomplete="current-password" class="{{ $input }}">
                @foreach ($errors->changePassword->get('current_password') as $m)<p class="{{ $err }}">{{ $m }}</p>@endforeach
            </div>
            <div>
                <label for="cp_new" class="{{ $label }}">New password</label>
                <input id="cp_new" name="password" type="password" required minlength="{{ \App\Support\PasswordPolicy::MIN_LENGTH }}" autocomplete="new-password" class="{{ $input }}">
                @foreach ($errors->changePassword->get('password') as $m)<p class="{{ $err }}">{{ $m }}</p>@endforeach
            </div>
            <div>
                <label for="cp_confirm" class="{{ $label }}">Repeat the new password</label>
                <input id="cp_confirm" name="password_confirmation" type="password" required autocomplete="new-password" class="{{ $input }}">
            </div>
            <button type="submit" class="{{ $button }}">Change password</button>
        </form>

        {{-- Administration: ADMIN only, folded away. Not linked from anywhere else. --}}
        @if ($isAdmin)
            @php $pending = $resetRequests->count(); @endphp
            <details class="group" @if ($adminSectionOpen) open @endif>
                <summary class="cursor-pointer select-none text-xs text-slate-400 hover:text-slate-600 list-none flex items-center gap-2">
                    <span class="inline-block transition-transform group-open:rotate-90">&#9656;</span>
                    <span>Administration</span>
                    @if ($pending > 0)
                        <span class="rounded-full bg-red-100 text-red-700 px-2 py-0.5 font-semibold">{{ $pending }} password {{ \Illuminate\Support\Str::plural('request', $pending) }}</span>
                    @endif
                </summary>

                <div class="mt-4 space-y-6">

                    {{-- A temporary password, shown once --}}
                    @if ($tempPassword)
                        <div class="rounded-xl border-2 border-amber-300 bg-amber-50 p-5" role="alert">
                            <p class="text-sm font-semibold text-amber-900">
                                Temporary password for {{ $tempPassword['name'] ?: $tempPassword['username'] }}
                                ({{ $tempPassword['username'] }})
                            </p>
                            <p class="mt-2 font-mono text-xl tracking-wider text-slate-900 select-all break-all" id="temp-password-value">{{ $tempPassword['password'] }}</p>
                            <p class="mt-2 text-xs text-amber-900">
                                Shown only once. Give it to them in person or by phone, never in a group chat. They must choose their
                                own password when they sign in, and any browser they were signed in on is signed out.
                            </p>
                            <button type="button" class="mt-3 text-xs underline text-amber-900"
                                    onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('temp-password-value').textContent.trim()); this.textContent='Copied';">
                                Copy
                            </button>
                        </div>
                    @endif

                    {{-- Forgot-password requests --}}
                    <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6">
                        <h2 class="text-base font-bold text-slate-900">Forgot-password requests</h2>
                        <p class="mt-1 text-xs text-slate-500">
                            Check that it really is that person (call them on the number you have) before you set a temporary password.
                            Enter your own password to confirm. Leave the temporary password empty to have one made for you.
                        </p>

                        @foreach ($errors->resetRequest->all() as $m)<p class="mt-2 {{ $err }}">{{ $m }}</p>@endforeach

                        @forelse ($resetRequests as $req)
                            <div class="mt-4 rounded-lg border border-gray-200 p-4">
                                <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                                    <p class="font-semibold text-slate-900">
                                        {{ $req->admin?->full_name ?: $req->username }}
                                        <span class="font-normal text-slate-500">({{ $req->username }}{{ $req->admin?->role ? ', ' . $req->admin->role : '' }})</span>
                                    </p>
                                    <p class="text-xs text-slate-500">asked {{ $req->requested_at->local()->format('Y-m-d H:i') }}</p>
                                </div>

                                <form method="POST" action="{{ route('admin.profile.password-requests.resolve', $req->id) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                                    @csrf
                                    <div>
                                        <label for="rr_tmp_{{ $req->id }}" class="{{ $label }}">Temporary password (optional)</label>
                                        <input id="rr_tmp_{{ $req->id }}" name="temporary_password" type="text" autocomplete="off" maxlength="100" class="{{ $input }} font-mono">
                                    </div>
                                    <div>
                                        <label for="rr_me_{{ $req->id }}" class="{{ $label }}">Your password</label>
                                        <input id="rr_me_{{ $req->id }}" name="current_password" type="password" required autocomplete="current-password" class="{{ $input }}">
                                    </div>
                                    <div class="sm:col-span-2 flex items-center gap-3">
                                        <button type="submit" class="{{ $button }}">Set temporary password</button>
                                    </div>
                                </form>
                                <form method="POST" action="{{ route('admin.profile.password-requests.dismiss', $req->id) }}" class="mt-2">
                                    @csrf
                                    <button type="submit" class="text-xs text-slate-500 underline hover:text-slate-700">Dismiss this request</button>
                                </form>
                            </div>
                        @empty
                            <p class="mt-4 text-sm text-slate-500">No one is waiting for a new password.</p>
                        @endforelse
                    </div>

                    {{-- Add an administrator --}}
                    <form method="POST" action="{{ route('admin.profile.admins.store') }}" class="bg-white rounded-xl shadow-md border border-gray-200 p-6 space-y-4" autocomplete="off">
                        @csrf
                        <h2 class="text-base font-bold text-slate-900">Add an administrator</h2>
                        <p class="text-xs text-slate-500">
                            Full access to the whole portal, so add only people who need it. The password you type is temporary:
                            the new administrator must choose their own the first time they sign in.
                        </p>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="aa_name" class="{{ $label }}">Full name</label>
                                <input id="aa_name" name="full_name" type="text" required maxlength="150" class="{{ $input }}" value="{{ $refillAdd ? old('full_name') : '' }}">
                                @foreach ($errors->addAdmin->get('full_name') as $m)<p class="{{ $err }}">{{ $m }}</p>@endforeach
                            </div>
                            <div>
                                <label for="aa_username" class="{{ $label }}">Username (used to sign in)</label>
                                <input id="aa_username" name="username" type="text" required maxlength="40" class="{{ $input }}" value="{{ $refillAdd ? old('username') : '' }}" autocapitalize="none">
                                @foreach ($errors->addAdmin->get('username') as $m)<p class="{{ $err }}">{{ $m }}</p>@endforeach
                            </div>
                            <div>
                                <label for="aa_email" class="{{ $label }}">Email</label>
                                <input id="aa_email" name="email" type="email" required maxlength="190" class="{{ $input }}" value="{{ $refillAdd ? old('email') : '' }}">
                                @foreach ($errors->addAdmin->get('email') as $m)<p class="{{ $err }}">{{ $m }}</p>@endforeach
                            </div>
                            <div>
                                <label for="aa_phone" class="{{ $label }}">Phone (optional)</label>
                                <input id="aa_phone" name="phone_number" type="tel" maxlength="20" class="{{ $input }}" value="{{ $refillAdd ? old('phone_number') : '' }}">
                                @foreach ($errors->addAdmin->get('phone_number') as $m)<p class="{{ $err }}">{{ $m }}</p>@endforeach
                            </div>
                            <div>
                                <label for="aa_password" class="{{ $label }}">Temporary password</label>
                                <input id="aa_password" name="password" type="password" required minlength="{{ \App\Support\PasswordPolicy::MIN_LENGTH }}" autocomplete="new-password" class="{{ $input }}">
                                @foreach ($errors->addAdmin->get('password') as $m)<p class="{{ $err }}">{{ $m }}</p>@endforeach
                            </div>
                            <div>
                                <label for="aa_confirm" class="{{ $label }}">Repeat the temporary password</label>
                                <input id="aa_confirm" name="password_confirmation" type="password" required autocomplete="new-password" class="{{ $input }}">
                            </div>
                        </div>
                        <p class="text-xs text-slate-500">{{ \App\Support\PasswordPolicy::hint() }}</p>

                        <div class="border-t border-gray-100 pt-4 max-w-sm">
                            <label for="aa_me" class="{{ $label }}">Your own password (to confirm it is you)</label>
                            <input id="aa_me" name="current_password" type="password" required autocomplete="current-password" class="{{ $input }}">
                            @foreach ($errors->addAdmin->get('current_password') as $m)<p class="{{ $err }}">{{ $m }}</p>@endforeach
                        </div>

                        <button type="submit" class="{{ $button }}">Add administrator</button>
                    </form>
                </div>
            </details>
        @endif
    </div>
@endsection