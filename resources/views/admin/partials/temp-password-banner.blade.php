{{-- Temporary password, shown once. Expects $tempPassword = session('temp_password'). --}}
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