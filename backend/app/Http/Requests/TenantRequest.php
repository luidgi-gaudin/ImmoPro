<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TenantRequest extends FormRequest
{
    public function rules()
    {
        return [
            'user_id' => ['required', 'exists:users'],
            'first_name' => ['required'],
            'last_name' => ['required'],
            'email' => ['nullable', 'email', 'max:254'],
            'phone' => ['nullable'],
            'iban' => ['nullable'],
            'bic' => ['nullable'],
            'country' => ['nullable'],
            'address' => ['nullable'],
        ];
    }

    public function authorize()
    {
        return true;
    }
}
