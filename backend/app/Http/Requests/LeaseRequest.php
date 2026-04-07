<?php

namespace App\Http\Requests;

use App\Enums\LeaseStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class LeaseRequest extends FormRequest
{
    public function rules()
    {
        return [
            'property_id' => ['required', 'exists:properties'],
            'tenant_id' => ['required', 'exists:tenants'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date'],
            'monthly_rent' => ['required', 'decimal:2'],
            'deposit' => ['nullable', 'decimal:2'],
            'statut' => ['sometimes', new Enum(LeaseStatus::class)],
        ];
    }

    public function authorize()
    {
        return true;
    }
}
