<?php

namespace App\Http\Controllers;

use App\Enums\LeaseStatus;
use App\Http\Requests\LeaseRequest;
use App\Models\Lease;
use Illuminate\Http\Request;

class LeaseController extends Controller
{
    public function index()
    {
        $propertyIds = auth()->user()
            ->portfolios()
            ->with('properties')
            ->get()
            ->pluck('properties')
            ->flatten()
            ->pluck('id');

        return Lease::whereIn('property_id', $propertyIds)->with('coTenants')->get();
    }

    public function store(LeaseRequest $request)
    {
        $lease = Lease::create($request->safe()->except('co_tenants'));

        $this->syncCoTenants($lease, $request->validated('co_tenants', []));

        return $lease->load('coTenants');
    }

    public function show(Lease $lease)
    {
        $this->authorize('view', $lease);

        return $lease->load('coTenants', 'photos');
    }

    public function update(LeaseRequest $request, Lease $lease)
    {
        $this->authorize('update', $lease);

        $lease->update($request->safe()->except('co_tenants'));

        if ($request->has('co_tenants')) {
            $this->syncCoTenants($lease, $request->validated('co_tenants', []));
        }

        return $lease->load('coTenants', 'photos');
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

        return $lease->refresh();
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
            'data' => $lease->refresh(),
        ]);
    }
}
