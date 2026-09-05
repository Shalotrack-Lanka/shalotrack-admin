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
            'contact'       => 'required|string|size:10|unique:dealer_customer_ads,contact',
            'nic_or_id'     => 'nullable|string|max:20|unique:dealer_customer_ads,nic_or_id',
            'has_device'    => 'nullable|boolean',
            'no_of_devices' => 'nullable|integer|min:0',
            'address'       => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'contact.unique'   => 'This Contact Number is already registered under another customer!',
            'nic_or_id.unique' => 'This NIC / ID is already registered under another customer!',
            'contact.size'     => 'Contact Number must be exactly 10 digits.',
        ];
    }
}