<?php

namespace App\Http\Controllers;

use App\Enums\LeaseStatus;
use App\Http\Requests\LeaseRequest;
use App\Http\Resources\LeaseResource;
use App\Models\Lease;
use App\Support\Database\JsonAggregate;
use Illuminate\Http\Request;

class LeaseController extends Controller
{
    /**
     * Liste des baux : bien, locataire, colocataires et portefeuille compris,
     * en une seule requête SQL.
     *
     * L'écran des baux affiche pour chaque ligne le nom du bien et celui du
     * locataire. Le front les retrouvait auparavant côté client, en chargeant
     * d'abord tous les portefeuilles, puis tous les locataires, puis les biens
     * de *chaque* portefeuille — un appel HTTP par portefeuille. Sur un parc de
     * cinq portefeuilles, ouvrir l'écran demandait sept allers-retours avant
     * d'afficher quoi que ce soit.
     *
     * Les jointures ramènent ces libellés avec les baux, et l'agrégat JSON les
     * colocataires. Ni `with()` ni requête supplémentaire : `with('coTenants')`
     * aurait coûté un aller-retour de plus.
     */
    public function index(Request $request)
    {
        $coTenants = JsonAggregate::arrayOf(
            [
                'id' => 'ct.id',
                'first_name' => 'ct.first_name',
                'last_name' => 'ct.last_name',
                'rent_share' => 'lt.rent_share',
            ],
            'from lease_tenant lt
               join tenants ct on ct.id = lt.tenant_id and ct.deleted_at is null
              where lt.lease_id = leases.id'
        );

        $query = Lease::query()
            ->join('properties as pr', 'pr.id', '=', 'leases.property_id')
            ->join('portfolios as po', 'po.id', '=', 'pr.portfolio_id')
            // Jointure externe : un bail dont le locataire a été supprimé doit
            // rester visible, sinon il disparaît de la gestion sans trace.
            ->leftJoin('tenants as te', function ($join) {
                $join->on('te.id', '=', 'leases.tenant_id')->whereNull('te.deleted_at');
            })
            ->where('po.user_id', auth()->id())
            ->select('leases.*')
            ->addSelect([
                'pr.title as property_title',
                'pr.address as property_address',
                'pr.city as property_city',
                'pr.portfolio_id as property_portfolio_id',
                'po.name as portfolio_name',
                'te.first_name as tenant_first_name',
                'te.last_name as tenant_last_name',
                'te.email as tenant_email',
            ])
            ->selectRaw($coTenants.' as co_tenants_json')
            ->withOwner()
            ->withCount('documents')
            ->filtered($request);

        return $this->paginate($query, $request)
            ->through(fn (Lease $lease) => new LeaseResource($lease));
    }

    public function store(LeaseRequest $request)
    {
        $lease = Lease::create($request->safe()->except('co_tenants'));

        $this->syncCoTenants($lease, $request->validated('co_tenants', []));

        return new LeaseResource($lease->load('coTenants', 'tenant', 'property.portfolio'));
    }

    public function show(Lease $lease)
    {
        $this->authorize('view', $lease);

        return new LeaseResource(
            $lease->load('coTenants', 'photos', 'tenant', 'property.portfolio')
        );
    }

    public function update(LeaseRequest $request, Lease $lease)
    {
        $this->authorize('update', $lease);

        $lease->update($request->safe()->except('co_tenants'));

        if ($request->has('co_tenants')) {
            $this->syncCoTenants($lease, $request->validated('co_tenants', []));
        }

        return new LeaseResource(
            $lease->load('coTenants', 'photos', 'tenant', 'property.portfolio')
        );
    }

    /**
     * Synchronise les colocataires et leur éventuelle répartition du loyer.
     *
     * @param  array<int, array{tenant_id: int, rent_share?: float|null}>  $coTenants
     */
    private function syncCoTenants(Lease $lease, array $coTenants): void
    {
        $syncData = collect($coTenants)->mapWithKeys(fn ($coTenant) => [
            $coTenant['tenant_id'] => ['rent_share' => $coTenant['rent_share'] ?? null],
        ])->all();

        $lease->coTenants()->sync($syncData);
    }

    public function destroy(Lease $lease)
    {
        $this->authorize('delete', $lease);

        $lease->delete();

        return response()->json();
    }

    /**
     * Résilie le bail à la date donnée (congé du locataire ou du bailleur).
     * Le bien est automatiquement marqué comme libre s'il n'a plus de bail actif.
     */
    public function terminate(Request $request, Lease $lease)
    {
        $this->authorize('update', $lease);

        if ($lease->statut === LeaseStatus::Termine) {
            return response()->json(['message' => 'Ce bail est déjà résilié.'], 409);
        }

        $data = $request->validate([
            'end_date' => ['required', 'date', 'after_or_equal:'.$lease->start_date->toDateString()],
        ]);

        $lease->update([
            'statut' => LeaseStatus::Termine,
            'end_date' => $data['end_date'],
        ]);

        return new LeaseResource($lease->refresh());
    }

    /**
     * Révision annuelle du loyer indexée sur l'IRL (art. 17-1, loi n° 89-462) :
     * nouveau loyer = loyer × (nouvel indice / ancien indice), une fois par an au plus.
     */
    public function reviseRent(Request $request, Lease $lease)
    {
        $this->authorize('update', $lease);

        if (! $lease->isActive()) {
            return response()->json([
                'message' => 'Seul un bail actif peut faire l\'objet d\'une révision de loyer.',
            ], 409);
        }

        if (! $lease->canReviseRent()) {
            return response()->json([
                'message' => 'La révision du loyer ne peut intervenir qu\'une fois par an (art. 17-1, loi n° 89-462).',
            ], 409);
        }

        $data = $request->validate([
            'irl_old' => ['required', 'numeric', 'gt:0'],
            'irl_new' => ['required', 'numeric', 'gt:0'],
        ]);

        $oldRent = (float) $lease->monthly_rent;
        $newRent = round($oldRent * (float) $data['irl_new'] / (float) $data['irl_old'], 2);

        $lease->forceFill([
            'monthly_rent' => $newRent,
            'last_rent_revision_at' => now()->toDateString(),
        ])->save();

        return response()->json([
            'message' => 'Loyer révisé selon la variation de l\'IRL.',
            'old_rent' => $oldRent,
            'new_rent' => $newRent,
            'data' => new LeaseResource($lease->refresh()),
        ]);
    }
}
