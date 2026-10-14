@props(['disabled' => false])

{{-- Password field with a "show / hide password" eye inside it. Use it like <x-text-input>, but without
     type= and without a top margin on the field itself (the wrapper carries mt-1).
     Plain JS on purpose: it must work on the sign-in page, before anything else has loaded. --}}
@once
    <style>
        /* Edge draws its own reveal button in password fields; ours replaces it. */
        input::-ms-reveal, input::-ms-clear { display: none; }
    </style>
@endonce

<div class="relative mt-1">
    <input type="password" @disabled($disabled)
        {{ $attributes->class(['border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm', 'block w-full pr-10']) }}>

    <button type="button"
            class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-500 hover:text-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 rounded-r-md"
            aria-label="Show password" aria-pressed="false" title="Show password" @disabled($disabled)
            onclick="var i=this.parentElement.querySelector('input'),show=i.type==='password';i.type=show?'text':'password';this.setAttribute('aria-pressed',show?'true':'false');this.setAttribute('aria-label',show?'Hide password':'Show password');this.title=show?'Hide password':'Show password';this.querySelector('.pw-eye').classList.toggle('hidden',show);this.querySelector('.pw-eye-off').classList.toggle('hidden',!show);">
        {{-- eye: shown while the password is hidden --}}
        <svg class="pw-eye h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/>
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
        </svg>
        {{-- eye with a slash: shown while the password is visible --}}
        <svg class="pw-eye-off hidden h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"/>
        </svg>
    </button>
</div>