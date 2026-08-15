<?php

namespace App\Http\Controllers;

use App\Enums\LeaseStatus;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use App\Models\Lease;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tout ce qu'affiche le tableau de bord, en un seul appel.
 *
 * L'écran demandait auparavant quatre points d'entrée distincts (rapport,
 * portefeuilles récents, locataires récents, baux récents) plus les alertes.
 * Sur une base distante, chaque requête HTTP paie de nouveau l'authentification
 * — recherche du jeton puis de l'utilisateur — avant de faire quoi que ce soit,
 * et ces appels ne se recouvrent pas. Les regrouper supprime ce coût fixe
 * multiplié par cinq.
 */
class DashboardController extends Controller
{
    /** Nombre d'éléments affichés dans chaque encart « récents ». */
    private const RECENT = 3;

    /** Nombre d'alertes affichées dans l'aperçu. */
    private const ALERTS = 4;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Les portefeuilles sont chargés une seule fois : ils servent à la fois
        // au compteur, aux identifiants des sous-requêtes et à l'encart des plus
        // récents. Un bailleur en a une poignée, jamais des milliers — les
        // découper en trois requêtes coûterait plus cher que de tout garder ici.
        $portfolios = Portfolio::where('user_id', $user->id)
            ->withCount('properties')
            ->latest()
            ->get();

        $portfolioIds = $portfolios->pluck('id');

        $properties = Property::whereIn('portfolio_id', $portfolioIds)
            ->selectRaw('count(*) as total, sum(case when is_rented then 1 else 0 end) as occupes')
            ->first();

        $leases = Lease::whereIn('property_id', function ($query) use ($portfolioIds) {
            $query->select('id')->from('properties')->whereIn('portfolio_id', $portfolioIds);
        })
            ->selectRaw(
                'count(*) as total,
                 sum(case when statut = ? then 1 else 0 end) as actifs,
                 sum(case when statut = ? then monthly_rent else 0 end) as loyer_actif',
                [LeaseStatus::Actif->value, LeaseStatus::Actif->value]
            )
            ->first();

        // Les alertes actives sont peu nombreuses par nature : on les charge une
        // fois et on en tire à la fois l'aperçu et le compteur de non-lues,
        // plutôt que de payer un second aller-retour pour un simple count.
        $activeAlerts = Alert::forUser($user)
            ->active()
            ->filtered($request->merge(['sort' => 'severity', 'direction' => 'asc']))
            ->get();

        return response()->json([
            'counts' => [
                'portfolios' => $portfolios->count(),
                'properties' => (int) ($properties->total ?? 0),
                'occupied_properties' => (int) ($properties->occupes ?? 0),
                'tenants' => Tenant::where('user_id', $user->id)->count(),
                'leases' => (int) ($leases->total ?? 0),
                'active_leases' => (int) ($leases->actifs ?? 0),
                'monthly_rent_expected' => round((float) ($leases->loyer_actif ?? 0), 2),
            ],

            'recent' => [
                'portfolios' => $portfolios->take(self::RECENT)->values(),

                'tenants' => Tenant::where('user_id', $user->id)
                    ->latest()
                    ->take(self::RECENT)
                    ->get(),

                'leases' => Lease::whereHas(
                    'property.portfolio',
                    fn ($portfolio) => $portfolio->where('user_id', $user->id)
                )
                    ->orderByDesc('start_date')
                    ->take(self::RECENT)
                    ->get(),
            ],

            'alerts' => [
                // Les plus graves d'abord : le tri métier vit dans le modèle.
                'items' => AlertResource::collection($activeAlerts->take(self::ALERTS)),
                'unread_count' => $activeAlerts->whereNull('read_at')->count(),
            ],
        ]);
    }
}
