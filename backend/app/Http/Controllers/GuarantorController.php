<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuarantorRequest;
use App\Models\Guarantor;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * Garants d'un locataire.
 *
 * Toujours imbriqués sous la fiche du locataire : un garant n'existe pas seul,
 * et l'autorisation se lit sur le dossier. La policy du locataire suffit donc à
 * couvrir ces routes — un garant ne peut pas appartenir à quelqu'un d'autre que
 * le propriétaire de la fiche.
 */
class GuarantorController extends Controller
{
    public function index(Tenant $tenant): JsonResponse
    {
        $this->authorize('view', $tenant);

        return response()->json([
            'data' => $tenant->guarantors()->orderBy('last_name')->orderBy('id')->get(),
        ]);
    }

    public function store(GuarantorRequest $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('update', $tenant);

        $guarantor = $tenant->guarantors()->create($request->validated());

        return response()->json(['data' => $guarantor], 201);
    }

    public function show(Tenant $tenant, Guarantor $guarantor): JsonResponse
    {
        $this->authorize('view', $tenant);

        return response()->json(['data' => $guarantor]);
    }

    public function update(GuarantorRequest $request, Tenant $tenant, Guarantor $guarantor): JsonResponse
    {
        $this->authorize('update', $tenant);

        $guarantor->update($request->validated());

        return response()->json(['data' => $guarantor->refresh()]);
    }

    /**
     * Suppression réversible.
     *
     * Un acte de cautionnement engage sur plusieurs années et la prescription
     * des loyers court sur trois ans (art. 7-1 de la loi de 1989) : effacer
     * définitivement le garant d'un dossier clos priverait le bailleur du seul
     * élément qui lui permet encore d'agir.
     */
    public function destroy(Tenant $tenant, Guarantor $guarantor): JsonResponse
    {
        $this->authorize('update', $tenant);

        $guarantor->delete();

        return response()->json();
    }
}
