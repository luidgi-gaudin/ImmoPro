<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Modification des informations personnelles du compte connecté.
 *
 * Trois champs sont volontairement absents, et leur absence est la règle de
 * sécurité de ce formulaire :
 *
 *   - `email`, qui suit son propre parcours avec confirmation par code. Le
 *     laisser ici permettrait de détourner un compte en changeant son adresse
 *     depuis une session ouverte, puis en demandant une réinitialisation.
 *   - `password`, qui passe par PasswordResetController et exige l'ancien.
 *   - `role`, qui se choisit à l'inscription. Un locataire qui se déclarerait
 *     bailleur n'obtiendrait rien de plus — l'isolation ne repose pas sur le
 *     rôle — mais atterrirait sur des écrans qui ne lui montreraient que du
 *     vide.
 */
class ProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],

            /*
             * Coordonnées de facturation du bailleur. Chiffrées au repos, et
             * remplaçables par une chaîne vide pour être effacées — un IBAN
             * saisi par erreur doit pouvoir être retiré, pas seulement corrigé.
             */
            'iban' => ['nullable', 'string', 'max:34'],
            'bic' => ['nullable', 'string', 'max:11'],
            'siret' => ['nullable', 'string', 'max:14'],
            'siren' => ['nullable', 'string', 'max:9'],
            'currency' => ['nullable', 'string', 'size:3'],
        ];
    }

    /**
     * Champs réservés au bailleur.
     *
     * Un locataire n'émet pas de quittance : lui stocker un SIRET reviendrait à
     * conserver une donnée dont l'application n'a aucun usage, ce que le RGPD
     * n'apprécie pas plus que l'utilisateur.
     *
     * @return array<string, mixed>
     */
    public function personalData(): array
    {
        $data = $this->safe()->only(['name', 'phone']);

        if ($this->user()->isLandlord()) {
            $data += $this->safe()->only(['iban', 'bic', 'siret', 'siren', 'currency']);
        }

        return array_map(
            fn ($value) => is_string($value) && trim($value) === '' ? null : $value,
            $data
        );
    }
}
