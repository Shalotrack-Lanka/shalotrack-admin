<x-guest-layout>
    <x-auth-brand />

    <hr class="mb-6 border-gray-200">

    <h1 class="text-lg font-bold text-slate-900">
        {{ $forced ? 'Choose your own password' : 'Change your password' }}
    </h1>
    <p class="mt-2 mb-4 text-sm text-gray-600">
        @if ($forced)
            The password you were given is only temporary. Choose a new one that only you know before you continue.
        @else
            Enter your current password, then the new one.
        @endif
        {{ \App\Support\PasswordPolicy::hint() }}
    </p>

    <form method="POST" action="{{ route('account.password.update') }}">
        @csrf
        @method('PUT')

        <div>
            <x-input-label for="current_password" :value="$forced ? 'Temporary password you were given' : 'Current password'" />
            <x-text-input id="current_password" class="block mt-1 w-full" type="password" name="current_password"
                          required autofocus autocomplete="current-password" />
            <x-input-error :messages="$errors->get('current_password')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password" value="New password" />
            <x-text-input id="password" class="block mt-1 w-full" type="password" name="password"
                          required autocomplete="new-password" minlength="{{ \App\Support\PasswordPolicy::MIN_LENGTH }}" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password_confirmation" value="Repeat the new password" />
            <x-text-input id="password_confirmation" class="block mt-1 w-full" type="password" name="password_confirmation"
                          required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <x-primary-button style="background-color: #1B2E5E; border-color: #1B2E5E;">
                Save new password
            </x-primary-button>
        </div>
    </form>

    @if ($forced)
        <form method="POST" action="{{ route('logout') }}" class="mt-4 text-center">
            @csrf
            <button type="submit" class="text-sm text-gray-600 underline hover:text-gray-900">Sign out instead</button>
        </form>
    @endif
</x-guest-layout>