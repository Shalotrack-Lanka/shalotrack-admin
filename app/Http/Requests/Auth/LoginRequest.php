<?php

namespace App\Http\Requests\Auth;

use App\Services\AuthAudit;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // Look the account up regardless of status, then require ACTIVE below: the answer to the
        // person is identical either way, but the audit log can say WHY a sign-in was refused.
        $account = \App\Models\Admin::where('username', $this->username)->first();
        $user = $account && $account->status === 'ACTIVE' ? $account : null;

        // FIX: was comparing with !== (plain string equality), which only
        // ever "worked" for accounts with an insecure plain-text password,
        // and always FAILED for every properly-hashed password — meaning
        // the correct, secure accounts could never actually log in.
        if (
            ! $user ||
            ! Hash::check($this->password, $user->password)
        ) {
            RateLimiter::hit($this->throttleKey());

            AuthAudit::failed($this, $account, ! $account ? 'no such account' : (! $user ? 'account is not active' : 'wrong password'));

            throw ValidationException::withMessages([
                'username' => trans('auth.failed'),
            ]);
        }

        Auth::login($user, $this->boolean('remember'));

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'username' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('username')).'|'.$this->ip());
    }
}