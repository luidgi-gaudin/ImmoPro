<?php

namespace App\Http\Requests;

use App\Enums\Dpe;
use App\Enums\Ges;
use App\Enums\OccupancyStatus;
use App\Enums\OwnershipType;
use App\Enums\PropertyType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Création et modification d'une fiche logement.
 *
 * Les règles vivaient en double dans `store()` et `update()`, recopiées à
 * l'identique. Une seule des deux copies aurait suivi le premier champ ajouté,
 * et le formulaire aurait accepté à la création ce qu'il refusait à la
 * modification — ou l'inverse, ce qui est pire, car alors la donnée entre puis
 * disparaît au premier enregistrement.
 *
 * `portfolio_id` n'y figure pas : le portefeuille vient de l'URL, et l'accepter
 * dans le corps permettrait de déplacer un bien vers le portefeuille d'un tiers.
 */
class PropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'property_type' => ['required', Rule::enum(PropertyType::class)],

            // Adresse
            'address' => ['required', 'string', 'max:255'],
            'address_complement' => ['nullable', 'string', 'max:255'],
            'floor' => ['nullable', 'string', 'max:20'],
            'apartment_number' => ['nullable', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:120'],
            'postal_code' => ['required', 'string', 'max:20'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],

            // Caractéristiques
            'rooms' => ['nullable', 'integer', 'between:0,50'],
            'area_sqm' => ['nullable', 'numeric', 'gt:0'],
            'is_furnished' => ['boolean'],
            'has_balcony' => ['boolean'],
            'has_garden' => ['boolean'],
            'has_terrace' => ['boolean'],
            'has_parking' => ['boolean'],
            'has_garage' => ['boolean'],
            'has_cave' => ['boolean'],

            /*
             * Diagnostic de performance énergétique.
             *
             * L'étiquette énergie reste obligatoire — c'est elle qui commande
             * l'interdiction de location des passoires thermiques. Celle des
             * gaz à effet de serre ne l'est pas : les diagnostics antérieurs à
             * la réforme de 2021 n'en portent pas, et refuser de les
             * enregistrer interdirait de saisir un bien pourtant en règle.
             */
            'dpe' => ['required', Rule::enum(Dpe::class)],
            'ges' => ['nullable', Rule::enum(Ges::class)],
            'dpe_date' => ['nullable', 'date', 'before_or_equal:today'],
            'dpe_expires_on' => ['nullable', 'date', 'after:dpe_date'],

            // Régime de propriété
            'ownership_type' => ['nullable', Rule::enum(OwnershipType::class)],
            'syndic_name' => ['nullable', 'string', 'max:180'],
            'syndic_contact' => ['nullable', 'string', 'max:180'],
            'syndic_email' => ['nullable', 'email', 'max:254'],
            'syndic_phone' => ['nullable', 'string', 'max:40'],
            'syndic_address' => ['nullable', 'string', 'max:255'],
            'lot_number' => ['nullable', 'integer', 'min:1'],

            // Exploitation
            'occupancy_status' => ['nullable', Rule::enum(OccupancyStatus::class)],
            'is_rented' => ['boolean'],
            'monthly_rent' => ['nullable', 'numeric', 'gte:0'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'dpe_date.before_or_equal' => 'Un diagnostic ne peut pas être daté du futur.',
            'dpe_expires_on.after' => 'La fin de validité doit suivre la date du diagnostic.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $ownership = OwnershipType::tryFrom((string) $this->input('ownership_type'));

            /*
             * Un syndic renseigné sur un bien en monopropriété est une
             * contradiction, pas une donnée en trop : l'un des deux champs est
             * faux, et rien ne dit lequel. Signalé plutôt que silencieusement
             * enregistré, faute de quoi la fiche affirmerait deux choses
             * incompatibles.
             */
            if ($ownership === OwnershipType::Monopropriete && filled($this->input('syndic_name'))) {
                $validator->errors()->add(
                    'syndic_name',
                    'Un bien en monopropriété n\'a pas de syndicat. Choisissez « copropriété » '
                        .'ou laissez ce champ vide.'
                );
            }
        });
    }

    /**
     * Données enregistrables, chaînes vides ramenées à `null`.
     *
     * @return array<string, mixed>
     */
    public function propertyData(): array
    {
        return array_map(
            fn ($value) => is_string($value) && trim($value) === '' ? null : $value,
            $this->validated()
        );
    }
}
