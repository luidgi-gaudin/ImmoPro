<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\Lease;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalSearchController extends Controller
{
    /**
     * Recherche globale et sécurisée à travers toutes les entités de l'utilisateur.
     *
     * Tous les résultats sont strictement isolés au compte du bailleur connecté ($request->user()).
     * Les caractères génériques SQL (% et _) sont neutralisés pour éviter toute injection ou lenteur.
     */
    public function search(Request $request): JsonResponse
    {
        $user = $request->user();
        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json([
                'query' => $term,
                'total' => 0,
                'results' => [
                    'portfolios' => [],
                    'properties' => [],
                    'tenants' => [],
                    'leases' => [],
                    'alerts' => [],
                ],
            ]);
        }

        $pattern = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';

        // 1. Portefeuilles
        $portfolios = Portfolio::query()
            ->where('user_id', $user->id)
            ->where(function (Builder $query) use ($pattern) {
                $this->whereLike($query, 'name', $pattern);
                $this->orWhereLike($query, 'description', $pattern);
            })
            ->withCount('properties')
            ->take(5)
            ->get()
            ->map(fn (Portfolio $portfolio) => [
                'id' => $portfolio->id,
                'type' => 'portfolio',
                'title' => $portfolio->name,
                'subtitle' => $portfolio->description ?: 'Ensemble immobilier',
                'badge' => $portfolio->properties_count.' actif(s)',
                'url' => '/portfolios/'.$portfolio->id.'/properties',
            ]);

        // 2. Biens / Actifs
        $properties = Property::query()
            ->whereHas('portfolio', fn (Builder $q) => $q->where('user_id', $user->id))
            ->where(function (Builder $query) use ($pattern) {
                $this->whereLike($query, 'title', $pattern);
                $this->orWhereLike($query, 'address', $pattern);
                $this->orWhereLike($query, 'city', $pattern);
                $this->orWhereLike($query, 'postal_code', $pattern);
            })
            ->with('portfolio')
            ->take(5)
            ->get()
            ->map(fn (Property $property) => [
                'id' => $property->id,
                'portfolio_id' => $property->portfolio_id,
                'type' => 'property',
                'title' => $property->title,
                'subtitle' => trim($property->address.', '.$property->city.' ('.$property->postal_code.')'),
                'badge' => $property->is_rented ? 'Loué' : 'Disponible',
                'badge_tone' => $property->is_rented ? 'info' : 'success',
                'url' => '/portfolios/'.$property->portfolio_id.'/properties/'.$property->id,
            ]);

        // 3. Locataires
        $tenants = Tenant::query()
            ->where('user_id', $user->id)
            ->where(function (Builder $query) use ($pattern) {
                $this->whereLike($query, 'first_name', $pattern);
                $this->orWhereLike($query, 'last_name', $pattern);
                $this->orWhereLike($query, 'email', $pattern);
                $this->orWhereLike($query, 'phone', $pattern);
            })
            ->take(5)
            ->get()
            ->map(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'type' => 'tenant',
                'title' => $tenant->first_name.' '.$tenant->last_name,
                'subtitle' => $tenant->email ?: ($tenant->phone ?: 'Dossier locataire'),
                'badge' => 'Locataire',
                'badge_tone' => 'neutral',
                'url' => '/tenants/'.$tenant->id,
            ]);

        // 4. Baux
        $leases = Lease::query()
            ->whereHas('property.portfolio', fn (Builder $q) => $q->where('user_id', $user->id))
            ->where(function (Builder $query) use ($pattern) {
                $query->whereHas('tenant', function (Builder $t) use ($pattern) {
                    $this->whereLike($t, 'first_name', $pattern);
                    $this->orWhereLike($t, 'last_name', $pattern);
                })->orWhereHas('property', function (Builder $p) use ($pattern) {
                    $this->whereLike($p, 'title', $pattern);
                    $this->orWhereLike($p, 'city', $pattern);
                });
            })
            ->with(['tenant', 'property'])
            ->take(5)
            ->get()
            ->map(function (Lease $lease): array {
                $propertyTitle = $lease->property ? $lease->property->title : 'Bien';
                $tenantName = $lease->tenant ? $lease->tenant->first_name.' '.$lease->tenant->last_name : '';
                $rawStatut = $lease->getAttributes()['statut'] ?? 'actif';
                $statutValue = is_string($rawStatut) ? $rawStatut : 'actif';

                return [
                    'id' => $lease->id,
                    'type' => 'lease',
                    'title' => 'Bail #'.$lease->id.' ('.$propertyTitle.')',
                    'subtitle' => ($tenantName !== '' ? $tenantName.' • ' : '').$lease->monthly_rent.' €/mois',
                    'badge' => $statutValue,
                    'badge_tone' => $statutValue === 'actif' ? 'success' : 'neutral',
                    'url' => '/leases?search='.urlencode($lease->tenant ? $lease->tenant->last_name : $propertyTitle),
                ];
            });

        // 5. Alertes
        $alerts = Alert::query()
            ->where('user_id', $user->id)
            ->where(function (Builder $query) use ($pattern) {
                $this->whereLike($query, 'title', $pattern);
                $this->orWhereLike($query, 'message', $pattern);
            })
            ->take(5)
            ->get()
            ->map(function (Alert $alert): array {
                $rawSeverity = $alert->getAttributes()['severity'] ?? 'warning';
                $severityValue = is_string($rawSeverity) ? $rawSeverity : 'warning';

                return [
                    'id' => $alert->id,
                    'type' => 'alert',
                    'title' => $alert->title,
                    'subtitle' => $alert->message,
                    'badge' => $severityValue,
                    'badge_tone' => $severityValue === 'critical' ? 'danger' : 'warning',
                    'url' => '/alerts',
                ];
            });

        $total = $portfolios->count() + $properties->count() + $tenants->count() + $leases->count() + $alerts->count();

        return response()->json([
            'query' => $term,
            'total' => $total,
            'results' => [
                'portfolios' => $portfolios,
                'properties' => $properties,
                'tenants' => $tenants,
                'leases' => $leases,
                'alerts' => $alerts,
            ],
        ]);
    }

    private function whereLike(Builder $query, string $column, string $pattern): Builder
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap($query->getModel()->qualifyColumn($column));

        return $query->whereRaw("lower({$wrapped}) like ? escape '\\'", [$pattern]);
    }

    private function orWhereLike(Builder $query, string $column, string $pattern): Builder
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap($query->getModel()->qualifyColumn($column));

        return $query->orWhereRaw("lower({$wrapped}) like ? escape '\\'", [$pattern]);
    }
}
