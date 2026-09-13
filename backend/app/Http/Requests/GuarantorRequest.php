<?php

namespace App\Http\Requests;

use App\Enums\GuaranteeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Création et modification d'un garant.
 *
 * Les champs exigés dépendent de la nature de la garantie, et ce n'est pas une
 * subtilité d'affichage. Une caution est une personne : sans nom, l'acte ne
 * désigne personne et ne s'exécute contre personne. Visale et une assurance
 * loyers impayés sont des dispositifs : ce qui les identifie est un numéro de
 * dossier, et réclamer une date de naissance à un organisme n'a pas de sens.
 */
class GuarantorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'guarantee_type' => ['required', Rule::enum(GuaranteeType::class)],

            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'profession' => ['nullable', 'string', 'max:120'],
            'company_name' => ['nullable', 'string', 'max:180'],

            'email' => ['nullable', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],

            'monthly_income' => ['nullable', 'numeric', 'gte:0'],
            'contract_reference' => ['nullable', 'string', 'max:120'],

            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'max_amount' => ['nullable', 'numeric', 'gt:0'],

            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ends_on.after_or_equal' => 'Un engagement ne peut pas se terminer avant d\'avoir commencé.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $type = GuaranteeType::from($this->input('guarantee_type'));

            if ($type->isPersonal()) {
                if (blank($this->input('last_name'))) {
                    $validator->errors()->add(
                        'last_name',
                        "Le nom du garant est requis pour une garantie « {$type->label()} »."
                    );
                }

                return;
            }

            if (blank($this->input('company_name')) && blank($this->input('contract_reference'))) {
                $validator->errors()->add(
                    'contract_reference',
                    "Indiquez l'organisme ou le numéro de dossier pour une garantie « {$type->label()} »."
                );
            }
        });
    }
}
