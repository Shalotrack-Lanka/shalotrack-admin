<?php

namespace App\Rules;

use App\Models\Admin;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A dealer's contact email is copied into the dealer's login. The Admins table has no unique index on email
 * (the portal enforces it in code, case-insensitively, on the admin pages), so every page that copies an email
 * into a login must run this same check, or one dealer could end up sharing an email with an administrator
 * or with another dealer's login.
 *
 * - $ignoreDealerId : the dealer's OWN login(s) never count as "another account".
 * - $unchangedFrom  : the email the dealer already has. Saving the form without changing it is always allowed,
 *                     so an old duplicate can never lock the dealer out of saving other fields.
 */
class EmailFreeForLogin implements ValidationRule
{
    public function __construct(
        private readonly ?int $ignoreDealerId = null,
        private readonly ?string $unchangedFrom = null,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $email = mb_strtolower(trim($value));

        if ($this->unchangedFrom !== null && mb_strtolower(trim($this->unchangedFrom)) === $email) {
            return;
        }

        $taken = Admin::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->when($this->ignoreDealerId !== null, fn ($q) => $q->where(
                fn ($w) => $w->whereNull('dealer_id')->orWhere('dealer_id', '!=', $this->ignoreDealerId)
            ))
            ->exists();

        if ($taken) {
            $fail('Another account already uses this email.');
        }
    }
}