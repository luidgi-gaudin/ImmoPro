<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PropertyRequest extends FormRequest
{
    public function rules()
    {
        return [
            'title' => ['required'],
            'portfolio_id' => ['required', 'exists:portfolios'],
            'property_type' => ['required'],
            'address' => ['required'],
            'city' => ['required'],
            'postal_code' => ['required'],
            'latitude' => ['nullable', 'decimal:2'],
            'longitude' => ['nullable', 'decimal:2'],
            'dpe' => ['required'],
            'rooms' => ['nullable', 'integer'],
            'area_sqm' => ['nullable', 'decimal:2'],
            'has_balcony' => ['boolean'],
            'has_garden' => ['boolean'],
            'has_parking' => ['boolean'],
            'has_cave' => ['boolean'],
            'is_rented' => ['boolean'],
            'monthly_rent' => ['nullable', 'decimal:2'],
            'description' => ['nullable'],
        ];
    }

    public function authorize()
    {
        return true;
    }
}
