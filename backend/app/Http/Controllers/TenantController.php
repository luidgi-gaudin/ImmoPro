<?php

namespace App\Http\Controllers;

use App\Enums\LeaseStatus;
use App\Http\Requests\TenantRequest;
use App\Models\Tenant;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    /**
     * Annuaire des locataires, en une seule requête SQL.
     *
     * `withCount` et `paginateInOnePass` sont deux sous-requêtes corrélées et
     * une fonction de fenêtrage : elles voyagent dans la requête des lignes au
     * lieu d'en ajouter deux.
     */
    public function index(Request $request)
    {
        $query = auth()->user()->tenants()
            // Permet d'afficher « en cours de location » sans recharger les
            // baux côté client, ce qui demandait un appel HTTP de plus.
            ->withCount(['leases as active_leases_count' => fn ($query) => $query->where('statut', LeaseStatus::Actif->value)])
            ->withCount('documents')
            ->filtered($request);

        return $this->paginate($query, $request);
    }

    public function store(TenantRequest $request)
    {
        $data = $request->validated();
        $data['user_id'] = auth()->id();

        return Tenant::create($data);
    }

    public function show(Tenant $tenant)
    {
        $this->authorize('view', $tenant);

        return $tenant;
    }

    public function update(TenantRequest $request, Tenant $tenant)
    {
        $this->authorize('update', $tenant);

        $tenant->update($request->validated());

        return $tenant;
    }

    public function destroy(Tenant $tenant)
    {
        $this->authorize('delete', $tenant);

        $tenant->delete();

        return response()->json();
    }
}
