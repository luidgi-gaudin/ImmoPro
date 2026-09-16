<?php

namespace App\Http\Requests;

use App\Enums\DocumentCategory;
use App\Models\Document;
use App\Models\Lease;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Dépôt d'une pièce par le locataire, depuis son espace.
 *
 * Plus restrictif que le dépôt côté bailleur, sur trois points, et chacun ferme
 * une porte :
 *
 *   - **la cible** ne peut être qu'un de ses baux ou sa propre fiche. Ni un
 *     bien, ni un portefeuille : ce sont les dossiers du bailleur.
 *   - **la catégorie** doit figurer parmi celles qu'on lui demande de fournir.
 *     Une quittance, non : c'est le bailleur qui l'émet, et laisser le locataire
 *     en déposer une reviendrait à accepter au dossier un justificatif de
 *     paiement qu'il aurait écrit lui-même.
 *   - **le propriétaire** de la pièce reste le bailleur, jamais le déposant.
 *     Un document rattaché au locataire échapperait à la vue du bailleur, dans
 *     le dossier même où il vient d'être versé.
 */
class TenantSpaceDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'documentable_type' => ['required', Rule::in(['lease', 'tenant'])],
            'documentable_id' => ['required', 'integer'],

            'category' => [
                'required',
                Rule::in(array_map(
                    fn (DocumentCategory $category) => $category->value,
                    DocumentCategory::tenantUploadable()
                )),
            ],

            'file' => [
                'required',
                'file',
                'max:'.config('immopro.documents.max_size_kb'),
                'mimetypes:'.implode(',', config('immopro.documents.mimes')),
            ],

            'name' => ['nullable', 'string', 'max:180'],
            'issued_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:issued_on'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $maxMb = round(((int) config('immopro.documents.max_size_kb')) / 1024);

        return [
            'file.max' => "Le fichier dépasse la taille maximale de {$maxMb} Mo.",
            'file.mimetypes' => 'Format non accepté. Déposez un PDF ou une image.',
            'documentable_type.in' => 'Vous ne pouvez déposer une pièce que sur votre bail ou votre dossier.',
            'category.in' => 'Cette catégorie de document ne peut pas être déposée depuis votre espace.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->target() === null) {
                $validator->errors()->add(
                    'documentable_id',
                    'Cet élément n\'existe pas ou ne fait pas partie de votre dossier.'
                );

                return;
            }

            $category = DocumentCategory::from($this->input('category'));
            $class = Document::classForAlias($this->input('documentable_type'));

            if (! in_array($class, $category->attachableTo(), true)) {
                $validator->errors()->add(
                    'category',
                    "La catégorie « {$category->label()} » ne s'applique pas à cet élément."
                );
            }
        });
    }

    /**
     * Bail ou fiche visé, à condition qu'il relève bien du locataire connecté.
     *
     * Résolu à partir des identifiants de ses propres dossiers, jamais par une
     * simple recherche par clé : sur SQLite, où il n'y a pas de policy, une
     * recherche non bornée rendrait le bail de n'importe qui.
     */
    public function target(): Lease|Tenant|null
    {
        $tenantIds = $this->user()->tenantProfiles()->pluck('id');

        if ($tenantIds->isEmpty()) {
            return null;
        }

        $id = (int) $this->input('documentable_id');

        return match ($this->input('documentable_type')) {
            'tenant' => $tenantIds->contains($id) ? Tenant::find($id) : null,

            'lease' => Lease::whereKey($id)
                ->where(fn ($query) => $query
                    ->whereIn('tenant_id', $tenantIds)
                    ->orWhereHas('coTenants', fn ($inner) => $inner->whereIn('tenants.id', $tenantIds)))
                ->first(),

            default => null,
        };
    }
}
