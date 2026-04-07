<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PortfolioRequest extends FormRequest
{
    public function rules()
    {
        return [
            'name' => ['required'],
            'user_id' => ['required', 'exists:users'],
            'description' => ['nullable'],
        ];
    }

    public function authorize()
    {
        return true;
    }
}
