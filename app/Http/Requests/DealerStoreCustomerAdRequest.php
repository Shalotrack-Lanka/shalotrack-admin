<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DealerStoreCustomerAdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'          => 'required|string|max:255',

            // Contact stored as 10-digit local format (07XXXXXXXX).
            // Unique per dealer's own records — the same customer cannot be
            // added twice by the same dealer, but two different dealers CAN
            // legitimately serve the same customer.
            'contact'       => [
                'required',
                'digits:10',
                'regex:/^0[0-9]{9}$/',
            ],

            // Email is optional but must be a valid address if provided.
            // Used as a second identifier to soft-match the dealer lead
            // against the real CustomerAd record from the app.
            'email'         => 'nullable|email|max:255',

            'nic_or_id'     => 'nullable|string|max:20',
            'has_device'    => 'nullable|boolean',
            'no_of_devices' => 'nullable|integer|min:0',
            'address'       => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'contact.digits'  => 'Contact Number must be exactly 10 digits.',
            'contact.regex'   => 'Contact Number must start with 0 (e.g. 07XXXXXXXX).',
            'email.email'     => 'Please enter a valid email address.',
        ];
    }
}