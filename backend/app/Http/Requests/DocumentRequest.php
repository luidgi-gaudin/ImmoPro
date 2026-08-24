<?php

namespace App\Http\Requests;

use App\Enums\DocumentCategory;
use App\Models\Document;
use App\Models\Lease;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Dépôt et modification d'une pièce jointe.
 *
 * Deux contrôles vont au-delà de la validation de forme :
 *
 *   - l'entité de rattachement doit appartenir au bailleur connecté, sinon
 *     n'importe quel identifiant permettrait de greffer un document sur le
 *     dossier d'un tiers ;
 *   - la catégorie doit avoir un sens pour cette entité — une pièce d'identité
 *     ne se range pas sur un portefeuille.
 */
class DocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'documentable_type' => [
                Rule::requiredIf($creating),
                Rule::in(array_keys(Document::attachableTypes())),
            ],
            'documentable_id' => [Rule::requiredIf($creating), 'integer'],

            'category' => [Rule::requiredIf($creating), Rule::enum(DocumentCategory::class)],

            'file' => [
                Rule::requiredIf($creating),
                'file',
                'max:'.config('immopro.documents.max_size_kb'),
                'mimetypes:'.implode(',', config('immopro.documents.mimes')),
            ],

            'name' => ['nullable', 'string', 'max:180'],
            'issued_on' => ['nullable', 'date'],

            // Une pièce ne peut pas cesser d'être valable avant d'exister.
            'expires_on' => ['nullable', 'date', 'after_or_equal:issued_on'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $maxMb = round(((int) config('immopro.documents.max_size_kb')) / 1024);

        return [
            'file.max' => "Le fichier dépasse la taille maximale de {$maxMb} Mo.",
            'file.mimetypes' => 'Format non accepté. Déposez un PDF, une image ou un document bureautique.',
            'expires_on.after_or_equal' => 'La date de fin de validité ne peut pas précéder la date du document.',
            'documentable_type.in' => 'Un document se rattache à un bail, un bien, un locataire ou un portefeuille.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $alias = $this->input('documentable_type');
            $id = $this->input('documentable_id');

            if ($alias === null || $id === null) {
                return;
            }

            $class = Document::classForAlias($alias);

            if ($class === null) {
                return;
            }

            // La Row Level Security filtre déjà la requête côté base ; ce
            // contrôle transforme un « introuvable » silencieux en message
            // explicite, et couvre les tests qui tournent sur SQLite.
            $owned = $this->ownedByCurrentUser($class, (int) $id);

            if (! $owned) {
                $validator->errors()->add(
                    'documentable_id',
                    'Cet élément n\'existe pas ou ne vous appartient pas.'
                );

                return;
            }

            $category = DocumentCategory::tryFrom((string) $this->input('category'));

            if ($category !== null && ! in_array($class, $category->attachableTo(), true)) {
                $validator->errors()->add(
                    'category',
                    "La catégorie « {$category->label()} » ne s'applique pas à ce type d'élément."
                );
            }
        });
    }

    /** @param  class-string  $class */
    private function ownedByCurrentUser(string $class, int $id): bool
    {
        $user = $this->user();

        return match ($class) {
            Portfolio::class => $user->portfolios()->whereKey($id)->exists(),
            Tenant::class => $user->tenants()->whereKey($id)->exists(),
            Property::class => Property::whereKey($id)
                ->whereHas('portfolio', fn ($query) => $query->where('user_id', $user->id))
                ->exists(),
            Lease::class => Lease::whereKey($id)
                ->whereHas('property.portfolio', fn ($query) => $query->where('user_id', $user->id))
                ->exists(),
            default => false,
        };
    }
}
