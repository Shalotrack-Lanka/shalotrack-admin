<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DealerStoreCustomerAdRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // මෙතන true කරන්න ඕනේ, නැත්නම් form එක submit කරන්න දෙන්නේ නෑ
        return true; 
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'contact' => ['required', 'string', 'min:10', 'max:15', 'regex:/^([0-9\s\-\+\(\)]*)$/'], // අංක පමණක් (සහ +, - වගේ ලකුණු)
            'nic_or_id' => ['nullable', 'string', 'max:20'],
            'no_of_devices' => ['required', 'integer', 'min:1', 'max:1000'], // අවම 1ක් වත් තියෙන්න ඕනේ
            'address' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Customer name is required.',
            'contact.required' => 'A contact number is required.',
            'contact.regex' => 'Please enter a valid phone number.',
            'contact.min' => 'Contact number must be at least 10 digits.',
            'no_of_devices.required' => 'Please specify the number of devices.',
            'no_of_devices.min' => 'Number of devices must be at least 1.',
        ];
    }
}