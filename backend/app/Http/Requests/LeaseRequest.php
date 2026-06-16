<?php

namespace App\Http\Requests;

use App\Enums\LeaseStatus;
use App\Enums\LeaseType;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class LeaseRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('type')) {
            // En modification, on conserve le type du bail existant.
            $lease = $this->route('lease');

            $this->merge(['type' => $lease?->type?->value ?? LeaseType::Nu->value]);
        }
    }

    public function rules()
    {
        $userId = auth()->id();
        $type = LeaseType::tryFrom((string) $this->input('type'));

        return [
            'property_id' => [
                'required',
                'exists:properties,id',
                function ($attribute, $value, $fail) use ($userId) {
                    $owns = Property::where('id', $value)
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
                    $owns = Tenant::where('id', $value)
                        ->where('user_id', $userId)
                        ->exists();

                    if (! $owns) {
                        $fail('The selected tenant does not belong to you.');
                    }
                },
            ],
            'type' => ['required', Rule::enum(LeaseType::class)],
            'start_date' => ['required', 'date'],
            'end_date' => [
                // Baux à durée impérativement bornée : étudiant (9 mois) et mobilité (1 à 10 mois)
                Rule::requiredIf(in_array($type, [LeaseType::Etudiant, LeaseType::Mobilite], true)),
                'nullable',
                'date',
                'after:start_date',
            ],
            'monthly_rent' => ['required', 'numeric', 'gt:0'],
            'charges' => ['nullable', 'numeric', 'gte:0'],
            'deposit' => ['nullable', 'numeric', 'gte:0'],
            'payment_day' => ['nullable', 'integer', 'between:1,28'],
            'statut' => ['sometimes', new Enum(LeaseStatus::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $type = LeaseType::from($this->input('type'));

            $this->validateDepositCap($validator, $type);
            $this->validateDuration($validator, $type);
        });
    }

    /**
     * Plafond du dépôt de garantie : 1 mois de loyer hors charges en location vide (art. 22),
     * 2 mois en meublé (art. 25-6), interdit en bail mobilité (art. 25-13).
     */
    private function validateDepositCap(Validator $validator, LeaseType $type): void
    {
        $deposit = (float) ($this->input('deposit') ?? 0);

        if ($deposit <= 0) {
            return;
        }

        $capMonths = $type->depositCapInMonths();

        if ($capMonths === 0) {
            $validator->errors()->add(
                'deposit',
                'Aucun dépôt de garantie ne peut être exigé pour un bail mobilité (art. 25-13, loi n° 89-462).'
            );

            return;
        }

        $cap = $capMonths * (float) $this->input('monthly_rent');

        if ($deposit > $cap) {
            $validator->errors()->add(
                'deposit',
                "Le dépôt de garantie ne peut excéder {$capMonths} mois de loyer hors charges (loi n° 89-462)."
            );
        }
    }

    /**
     * Durées légales : 3 ans minimum en location vide (art. 10), 1 an en meublé (art. 25-7),
     * 9 mois pour le bail étudiant (art. 25-7), 1 à 10 mois pour le bail mobilité (art. 25-12).
     * Un bail résilié (statut « termine ») peut avoir une date de fin anticipée.
     */
    private function validateDuration(Validator $validator, LeaseType $type): void
    {
        if (! $this->filled('end_date')) {
            return;
        }

        $statut = $this->input('statut') ?? $this->route('lease')?->statut?->value;

        if ($statut === LeaseStatus::Termine->value) {
            return;
        }

        $start = Carbon::parse($this->input('start_date'));
        $end = Carbon::parse($this->input('end_date'));

        $min = $type->minDurationInMonths();

        // Tolérance d'un jour pour les dates de fin inclusives (ex. 01/09 → 31/05).
        if ($end->lt($start->copy()->addMonths($min)->subDay())) {
            $validator->errors()->add(
                'end_date',
                "La durée minimale d'un bail « {$type->label()} » est de {$min} mois (loi n° 89-462)."
            );

            return;
        }

        $max = $type->maxDurationInMonths();

        if ($max !== null && $end->gt($start->copy()->addMonths($max))) {
            $validator->errors()->add(
                'end_date',
                "La durée maximale d'un bail « {$type->label()} » est de {$max} mois (loi n° 89-462)."
            );
        }
    }

    public function authorize()
    {
        return true;
    }
}
