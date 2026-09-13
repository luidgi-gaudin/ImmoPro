<?php

namespace App\Http\Requests\Auth;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Ouverture d'un compte.
 *
 * Le téléphone n'y figure pas : il se renseigne depuis le profil, une fois le
 * compte créé. Un formulaire d'inscription ne demande que ce sans quoi le
 * compte ne peut pas exister.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:254', 'unique:users'],
            'password' => ['required', 'confirmed', Password::defaults()],

            /*
             * Le profil est demandé, pas déduit.
             *
             * La colonne portait déjà « proprietaire » par défaut, ce qui
             * revenait à inscrire tous les locataires comme bailleurs. Un
             * défaut silencieux sur une donnée qui décide de ce qu'on voit
             * n'est pas un défaut : c'est une erreur différée.
             */
            'role' => ['required', Rule::enum(UserRole::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'role.required' => 'Indiquez si vous êtes propriétaire bailleur ou locataire.',
        ];
    }

    public function role(): UserRole
    {
        return UserRole::from($this->validated('role'));
    }
}
