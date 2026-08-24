<?php

namespace App\Http\Controllers;

use App\Enums\Dpe;
use App\Enums\LeaseStatus;
use App\Enums\PropertyType;
use App\Models\Portfolio;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PropertyController extends Controller
{
    public function index(Request $request, Portfolio $portfolio)
    {
        $this->authorize('view', $portfolio);

        $query = $portfolio->properties()
            // Statut d'occupation et loyer effectif viennent du bail actif :
            // sans ces sous-requêtes, l'écran devait charger les baux à part.
            ->withCount(['leases as active_leases_count' => fn ($query) => $query->where('statut', LeaseStatus::Actif->value)])
            ->withCount('documents')
            ->filtered($request);

        $page = $this->paginate($query, $request);

        // Le portefeuille voyage avec ses biens.
        //
        // L'écran parent (le bandeau du portefeuille) l'affichait au prix d'un
        // second appel HTTP, qui repayait l'authentification et l'ouverture de
        // connexion pour une ligne déjà chargée ici par la résolution de l'URL.
        // L'enveloppe de pagination reste identique : une clé s'ajoute, aucune
        // ne change.
        return response()->json($page->toArray() + ['portfolio' => $portfolio->toSummary()]);
    }

    public function store(Request $request, Portfolio $portfolio)
    {
        $this->authorize('update', $portfolio);

        $data = $request->validate([
            'title' => ['required', 'string'],
            'property_type' => ['required', Rule::enum(PropertyType::class)],
            'address' => ['required', 'string'],
            'city' => ['required', 'string'],
            'postal_code' => ['required', 'string'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'dpe' => ['required', Rule::enum(Dpe::class)],
            'rooms' => ['nullable', 'integer'],
            'area_sqm' => ['nullable', 'numeric'],
            'has_balcony' => ['boolean'],
            'has_garden' => ['boolean'],
            'has_parking' => ['boolean'],
            'has_cave' => ['boolean'],
            'is_rented' => ['boolean'],
            'monthly_rent' => ['nullable', 'numeric'],
            'description' => ['nullable', 'string'],
        ]);

        return $portfolio->properties()->create($data);
    }

    /**
     * Fiche d'un bien, accompagnée de son portefeuille et de ses compteurs.
     *
     * Les deux sont déjà chargés par la résolution de l'URL : les joindre à la
     * réponse évite à l'écran parent un second appel HTTP pour afficher son
     * bandeau.
     */
    public function show(Portfolio $portfolio, Property $property): JsonResponse
    {
        $this->authorize('view', $portfolio);

        return response()->json(
            $property->toArray() + ['portfolio' => $portfolio->toSummary()]
        );
    }

    public function update(Request $request, Portfolio $portfolio, Property $property)
    {
        $this->authorize('update', $portfolio);

        $data = $request->validate([
            'title' => ['required', 'string'],
            'property_type' => ['required', Rule::enum(PropertyType::class)],
            'address' => ['required', 'string'],
            'city' => ['required', 'string'],
            'postal_code' => ['required', 'string'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'dpe' => ['required', Rule::enum(Dpe::class)],
            'rooms' => ['nullable', 'integer'],
            'area_sqm' => ['nullable', 'numeric'],
            'has_balcony' => ['boolean'],
            'has_garden' => ['boolean'],
            'has_parking' => ['boolean'],
            'has_cave' => ['boolean'],
            'is_rented' => ['boolean'],
            'monthly_rent' => ['nullable', 'numeric'],
            'description' => ['nullable', 'string'],
        ]);

        $property->update($data);

        return $property;
    }

    public function destroy(Portfolio $portfolio, Property $property)
    {
        $this->authorize('delete', $portfolio);

        $property->delete();

        return response()->json();
    }
}
