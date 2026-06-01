<?php

namespace App\Http\Requests;

use App\Enums\LeaseStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class LeaseRequest extends FormRequest
{
    public function rules()
    {
        $userId = auth()->id();

        return [
            'property_id' => [
                'required',
                'exists:properties,id',
                function ($attribute, $value, $fail) use ($userId) {
                    $owns = \App\Models\Property::where('id', $value)
                        ->whereHas('portfolio', fn ($q) => $q->where('user_id', $userId))
                        ->exists();

                    if (! $owns) {
                        $fail('The selected property does not belong to you.');
                    }
                },
            ],
            'tenant_id' => [
                'required',
                'exists:tenants,id',
                function ($attribute, $value, $fail) use ($userId) {
                    $owns = \App\Models\Tenant::where('id', $value)
                        ->where('user_id', $userId)
                        ->exists();

                    if (! $owns) {
                        $fail('The selected tenant does not belong to you.');
                    }
                },
            ],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date'],
            'monthly_rent' => ['required', 'numeric'],
            'deposit' => ['nullable', 'numeric'],
            'statut' => ['sometimes', new Enum(LeaseStatus::class)],
        ];
    }

    public function authorize()
    {
        return true;
    }
}
