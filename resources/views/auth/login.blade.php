<x-guest-layout>
    <x-auth-session-status class="mb-4" :status="session('status')" />

    {{-- ShaloTrack SVG Logo --}}
    <div class="flex flex-col items-center mb-6">
        <svg width="220" viewBox="0 0 220 200" xmlns="http://www.w3.org/2000/svg">
            <!-- Pin/drop shape -->
            <g transform="translate(110, 90) scale(0.9)">
                <!-- Orange outer swoosh left -->
                <path d="M-58,30 Q-72,-30 -30,-72 Q0,-95 30,-72 Q58,-50 62,-20" fill="none" stroke="#F07A1A" stroke-width="14" stroke-linecap="round"/>
                <!-- Orange lower swoosh -->
                <path d="M-10,50 Q10,70 35,50 Q55,28 62,-20" fill="none" stroke="#F07A1A" stroke-width="12" stroke-linecap="round"/>
                <!-- Navy pin body -->
                <ellipse cx="0" cy="-28" rx="52" ry="58" fill="#1B2E5E"/>
                <!-- Pin bottom point -->
                <path d="M-18,24 Q0,68 18,24" fill="#1B2E5E"/>
                <!-- S-curve road (white) -->
                <path d="M-16,-60 Q-30,-35 -8,-12 Q12,10 -4,32" fill="none" stroke="white" stroke-width="10" stroke-linecap="round"/>
                <!-- WiFi signals (orange) -->
                <path d="M28,-72 Q38,-68 34,-58" fill="none" stroke="#F07A1A" stroke-width="4" stroke-linecap="round"/>
                <path d="M36,-80 Q52,-72 46,-56" fill="none" stroke="#F07A1A" stroke-width="4" stroke-linecap="round"/>
                <path d="M44,-88 Q65,-76 58,-55" fill="none" stroke="#F07A1A" stroke-width="4" stroke-linecap="round"/>
                <!-- Car silhouette -->
                <g transform="translate(0, 18)">
                    <rect x="-22" y="-8" width="44" height="16" rx="4" fill="#1B2E5E" stroke="white" stroke-width="1.2"/>
                    <path d="M-14,-8 Q-10,-18 10,-18 Q16,-18 20,-8" fill="#1B2E5E" stroke="white" stroke-width="1.2"/>
                    <path d="M-10,-8 Q-7,-15 9,-15 Q14,-15 18,-8" fill="white" opacity="0.25"/>
                    <circle cx="-12" cy="8" r="4.5" fill="white"/>
                    <circle cx="12" cy="8" r="4.5" fill="white"/>
                    <circle cx="-12" cy="8" r="2" fill="#1B2E5E"/>
                    <circle cx="12" cy="8" r="2" fill="#1B2E5E"/>
                    <line x1="-4" y1="13" x2="-4" y2="22" stroke="white" stroke-width="2" stroke-linecap="round"/>
                    <line x1="4" y1="13" x2="4" y2="22" stroke="white" stroke-width="2" stroke-linecap="round"/>
                </g>
            </g>
        </svg>

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