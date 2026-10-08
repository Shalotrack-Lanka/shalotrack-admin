<x-guest-layout>
    <x-auth-session-status class="mb-4" :status="session('status')" />

    {{-- ShaloTrack logo (transparent cut-out; WebP with PNG fallback) --}}
    <div class="flex flex-col items-center mb-6">
        <picture>
            <source srcset="{{ asset('images/shalotrack-logo.webp') }}" type="image/webp">
            <img src="{{ asset('images/shalotrack-logo.png') }}"
                 alt="ShaloTrack logo"
                 width="160" height="160"
                 fetchpriority="high"
                 decoding="async"
                 style="width:160px;height:160px;user-select:none;">
        </picture>

        <!-- Wordmark -->
        <div class="mt-1">
            <span style="font-family: Arial Black, sans-serif; font-weight: 900; font-size: 1.6rem; color: #1B2E5E; letter-spacing: 2px;">SHALO</span><span style="font-family: Arial Black, sans-serif; font-weight: 900; font-size: 1.6rem; color: #F07A1A; letter-spacing: 2px;">TRACK</span>
        </div>
        <div class="flex items-center gap-2 mt-1">
            <div style="height:1px; width:50px; background:#1B2E5E;"></div>
            <span style="font-size: 0.6rem; letter-spacing: 3px; color: #1B2E5E;">ALWAYS CONNECTED</span>
            <div style="height:1px; width:50px; background:#F07A1A;"></div>
        </div>
        <p style="font-size: 0.55rem; letter-spacing: 1.5px; color: #888; margin-top: 4px;">GPS TRACKING &nbsp;|&nbsp; VEHICLE SECURITY &nbsp;|&nbsp; FLEET MANAGEMENT</p>
    </div>

    <hr class="mb-6 border-gray-200">

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <!-- Username -->
        <div>
            <x-input-label for="username" :value="__('Username')" />
            <x-text-input
                id="username"
                class="block mt-1 w-full"
                type="text"
                name="username"
                :value="old('username')"
                required
                autofocus
                autocomplete="username" />
            <x-input-error :messages="$errors->get('username')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input
                id="password"
                class="block mt-1 w-full"
                type="password"
                name="password"
                required
                autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="block mt-4">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox"
                    class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                    name="remember">
                <span class="ms-2 text-sm text-gray-600">{{ __('Remember me') }}</span>
            </label>
        </div>

        <!-- Actions -->
        <div class="flex items-center justify-end mt-4">
            @if (Route::has('password.request'))
                <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                   href="{{ route('password.request') }}">
                    {{ __('Forgot your password?') }}
                </a>
            @endif

            <x-primary-button class="ms-3"
                style="background-color: #1B2E5E; border-color: #1B2E5E;">
                {{ __('Log in') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>