<?php

namespace App\Http\Requests;

use App\Enums\IdentityDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création et modification d'un dossier locataire.
 *
 * L'adresse e-mail n'est pas qu'un moyen de contact : c'est elle qui permettra
 * au locataire de retrouver son dossier en ouvrant un compte, une fois son
 * adresse vérifiée. Une faute de frappe ici ne bloque rien tout de suite, et se
 * paie plus tard par un espace locataire qui reste désespérément vide.
 */
class TenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],

            'birth_date' => ['nullable', 'date', 'before:today'],
            'birth_place' => ['nullable', 'string', 'max:120'],

            'identity_document_type' => ['nullable', Rule::enum(IdentityDocumentType::class)],
            'identity_document_number' => ['nullable', 'string', 'max:60'],

            'email' => ['nullable', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:40'],
            'iban' => ['nullable', 'string', 'max:34'],
            'bic' => ['nullable', 'string', 'max:11'],
            'country' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'birth_date.before' => 'La date de naissance doit être antérieure à aujourd\'hui.',
        ];
    }

    /**
     * Champs enregistrables, chaînes vides ramenées à `null`.
     *
     * Un formulaire vidé doit effacer la valeur, pas enregistrer une chaîne
     * vide : `''` et `null` se comportent différemment devant un `whereNull`,
     * et un numéro de pièce d'identité vide mais présent serait chiffré pour
     * rien.
     *
     * @return array<string, mixed>
     */
    public function tenantData(): array
    {
        return array_map(
            fn ($value) => is_string($value) && trim($value) === '' ? null : $value,
            $this->validated()
        );
    }
}
