<?php

namespace App\Http\Resources;

use App\Models\Lease;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Forme unique d'un bail dans l'API, quelle que soit la façon dont il a été
 * chargé.
 *
 * La liste ramène le bien, le locataire et les colocataires par jointures et
 * sous-requêtes JSON — une seule requête SQL pour tout l'écran. La fiche, elle,
 * les charge par relations Eloquent. Les deux chemins n'ont pas la même forme
 * brute ; cette ressource les ramène à la même, pour que le front n'ait qu'une
 * lecture à connaître.
 *
 * C'est aussi ce qui a permis de supprimer le pire point chaud de
 * l'application : l'écran des baux appelait auparavant l'API une fois par
 * portefeuille pour retrouver le nom du bien et du locataire de chaque ligne.
 */
class LeaseResource extends JsonResource
{
    /**
     * Pas d'enveloppe « data ».
     *
     * Les autres listes de l'API renvoient l'objet à plat, et le front lit
     * directement `response.id`. Introduire une enveloppe sur les seuls baux
     * créerait deux conventions de lecture pour la même API.
     */
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Lease $lease */
        $lease = $this->resource;

        return [
            'id' => $lease->id,
            'property_id' => $lease->property_id,
            'tenant_id' => $lease->tenant_id,
            'type' => $lease->type,
            'start_date' => $lease->start_date->toDateString(),
            'end_date' => $lease->end_date?->toDateString(),
            'monthly_rent' => (float) $lease->monthly_rent,
            'charges' => (float) $lease->charges,
            'deposit' => $lease->deposit === null ? null : (float) $lease->deposit,
            'payment_day' => $lease->payment_day,
            'statut' => $lease->statut,
            'last_rent_revision_at' => $lease->last_rent_revision_at?->toDateString(),

            // Plafond légal du dépôt et éligibilité à la révision : deux règles
            // de la loi de 1989 que le front affichait en les recalculant. Une
            // règle de droit dupliquée des deux côtés finit par diverger.
            'deposit_cap' => $lease->depositCap(),
            'can_revise_rent' => $lease->canReviseRent(),

            // Durée effective et réserve de conformité éventuelle. Le front les
            // affiche, il ne les calcule pas : une règle de droit écrite des
            // deux côtés finit par diverger.
            'duration_months' => $lease->durationInMonths(),
            'duration_notice' => $lease->durationNotice(),

            'property' => $this->property(),
            'tenant' => $this->tenant(),
            'co_tenants' => $this->coTenants(),
            'documents_count' => $lease->documents_count ?? null,

            // État de l'échéancier : présent sur la fiche d'un bail, absent des
            // listes qui ne l'affichent pas et n'ont donc pas à le calculer.
            'schedule' => $lease->paymentSchedule(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function property(): ?array
    {
        /** @var Lease $lease */
        $lease = $this->resource;

        if ($lease->relationLoaded('property') && $lease->property !== null) {
            return [
                'id' => $lease->property->id,
                'title' => $lease->property->title,
                'address' => $lease->property->address,
                'city' => $lease->property->city,
                'portfolio_id' => $lease->property->portfolio_id,
                'portfolio_name' => $lease->property->relationLoaded('portfolio')
                    ? $lease->property->portfolio?->name
                    : null,
            ];
        }

        return $lease->getAttribute('property_title') === null ? null : [
            'id' => $lease->property_id,
            'title' => $lease->getAttribute('property_title'),
            'address' => $lease->getAttribute('property_address'),
            'city' => $lease->getAttribute('property_city'),
            'portfolio_id' => $lease->getAttribute('property_portfolio_id'),
            'portfolio_name' => $lease->getAttribute('portfolio_name'),
        ];
    }

    /** @return array<string, mixed>|null */
    private function tenant(): ?array
    {
        /** @var Lease $lease */
        $lease = $this->resource;

        if ($lease->relationLoaded('tenant') && $lease->tenant !== null) {
            return [
                'id' => $lease->tenant->id,
                'first_name' => $lease->tenant->first_name,
                'last_name' => $lease->tenant->last_name,
                'email' => $lease->tenant->email,
            ];
        }

        return $lease->getAttribute('tenant_first_name') === null ? null : [
            'id' => $lease->tenant_id,
            'first_name' => $lease->getAttribute('tenant_first_name'),
            'last_name' => $lease->getAttribute('tenant_last_name'),
            'email' => $lease->getAttribute('tenant_email'),
        ];
    }

    /**
     * `pivot.rent_share` est conservé même quand les colocataires viennent
     * d'une agrégation JSON : c'est la forme que le front connaît, et la
     * changer casserait le formulaire de répartition du loyer.
     *
     * @return list<array<string, mixed>>
     */
    private function coTenants(): array
    {
        /** @var Lease $lease */
        $lease = $this->resource;

        if ($lease->relationLoaded('coTenants')) {
            return $lease->coTenants->map(fn (Tenant $coTenant) => [
                'id' => $coTenant->id,
                'first_name' => $coTenant->first_name,
                'last_name' => $coTenant->last_name,
                'pivot' => ['rent_share' => $coTenant->pivot?->rent_share === null
                    ? null
                    : (float) $coTenant->pivot->rent_share],
            ])->values()->all();
        }

        $aggregated = $lease->getAttribute('co_tenants_json');

        if (is_string($aggregated)) {
            $aggregated = json_decode($aggregated, true);
        }

        return collect($aggregated ?? [])
            ->map(fn (array $coTenant) => [
                'id' => (int) $coTenant['id'],
                'first_name' => $coTenant['first_name'],
                'last_name' => $coTenant['last_name'],
                'pivot' => ['rent_share' => $coTenant['rent_share'] === null
                    ? null
                    : (float) $coTenant['rent_share']],
            ])
            ->values()
            ->all();
    }
}
