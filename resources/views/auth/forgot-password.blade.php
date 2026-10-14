<x-guest-layout>
    <x-auth-brand />

    <hr class="mb-6 border-gray-200">

    <h1 class="text-lg font-bold text-slate-900">Forgot your password?</h1>
    <p class="mt-2 mb-4 text-sm text-gray-600">
        Enter your username. A ShaloTrack administrator will be told and will contact you with a temporary
        password, which you must change the first time you sign in. Nothing is sent by email.
    </p>

    @if (session('status'))
        <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800" role="status">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.request.store') }}">
        @csrf

        <div>
            <x-input-label for="username" :value="__('Username')" />
            <x-text-input id="username" class="block mt-1 w-full" type="text" name="username"
                          :value="old('username')" required autofocus autocomplete="username" maxlength="100" />
            <x-input-error :messages="$errors->get('username')" class="mt-2" />
        </div>

        <div class="flex items-center justify-between mt-4">
            <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
               href="{{ route('login') }}">
                {{ __('Back to sign in') }}
            </a>

            <x-primary-button style="background-color: #1B2E5E; border-color: #1B2E5E;">
                {{ __('Ask for a new password') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>