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
            'name' => ['required', 'string', 'max:255'],
            'contact' => ['required', 'digits:10'], // හරියටම අංක 10ක් විය යුතුය
            'nic_or_id' => ['nullable', 'string', 'max:20'],
            'no_of_devices' => ['required', 'integer', 'min:1', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'imei_numbers' => ['required', 'array'],
            'imei_numbers.*' => ['required', 'digits:15', 'distinct'], // Digits 15ක් විය යුතුය, Form එක ඇතුළෙත් Duplicate විය නොහැක
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Customer name is required.',
            'contact.required' => 'Contact number is required.',
            'contact.digits' => 'Contact number must be exactly 10 digits.',
            'no_of_devices.required' => 'Please enter the number of devices.',
            'imei_numbers.*.required' => 'All IMEI numbers are required.',
            'imei_numbers.*.digits' => 'Each IMEI number must be exactly 15 digits.',
            'imei_numbers.*.distinct' => 'Duplicate IMEI numbers entered in the form.',
        ];
    }
}