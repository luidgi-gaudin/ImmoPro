<?php

namespace App\Http\Controllers;

use App\Enums\LeaseStatus;
use App\Http\Requests\TenantRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantInvitationNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

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
            ->withCount('guarantors')
            // Traité hors de `filtered()` : c'est le seul critère dont
            // l'absence doit restreindre. Un paramètre manquant est ignoré par
            // le filtrage générique, ce qui ferait réapparaître les dossiers
            // clos dans la liste de travail.
            ->archiveFilter($request)
            ->filtered($request);

        return $this->paginate($query, $request);
    }

    public function store(TenantRequest $request)
    {
        $data = $request->tenantData();
        $data['user_id'] = auth()->id();

        return Tenant::create($data);
    }

    public function show(Tenant $tenant)
    {
        $this->authorize('view', $tenant);

        // Les garants font partie de la fiche : les charger ici évite un appel
        // supplémentaire à l'ouverture, et l'écran affiche un dossier complet
        // ou rien, jamais un dossier à moitié rempli.
        return $tenant->load('guarantors');
    }

    public function update(TenantRequest $request, Tenant $tenant)
    {
        $this->authorize('update', $tenant);

        $tenant->update($request->tenantData());

        return $tenant->load('guarantors');
    }

    /* ----------------------------------------------------------------------
     | Archivage
     |----------------------------------------------------------------------*/

    /**
     * Sort le dossier des listes de travail sans l'effacer.
     *
     * Distinct de la suppression, et les deux répondent à des besoins
     * différents : « supprimé par erreur » d'un côté, « dossier clos, à
     * conserver » de l'autre. La prescription des actions en paiement des
     * loyers court sur trois ans (art. 7-1, loi n° 89-462), et l'ancien
     * locataire peut réclamer son dépôt de garantie bien après son départ.
     */
    public function archive(Tenant $tenant): JsonResponse
    {
        $this->authorize('update', $tenant);

        $tenant->archive();

        return response()->json(['data' => $tenant->refresh()]);
    }

    public function unarchive(Tenant $tenant): JsonResponse
    {
        $this->authorize('update', $tenant);

        $tenant->unarchive();

        return response()->json(['data' => $tenant->refresh()]);
    }

    /* ----------------------------------------------------------------------
     | Espace locataire
     |----------------------------------------------------------------------*/

    /**
     * Invite le locataire à ouvrir son espace.
     *
     * Le message ne porte aucun jeton : il renvoie vers l'inscription
     * ordinaire, et c'est la vérification de l'adresse qui rattachera le
     * dossier. Un lien porteur d'un accès direct donnerait le dossier à
     * quiconque met la main sur le message.
     *
     * Un dossier déjà rattaché n'est pas réinvité : le compte existe, et un
     * second message ne ferait qu'entretenir la confusion.
     */
    public function invite(Tenant $tenant): JsonResponse
    {
        $this->authorize('update', $tenant);

        if (blank($tenant->email)) {
            return response()->json([
                'message' => 'Renseignez l\'adresse e-mail du locataire avant de l\'inviter.',
            ], 422);
        }

        if ($tenant->account_user_id !== null) {
            return response()->json([
                'message' => 'Ce locataire dispose déjà de son espace.',
            ], 409);
        }

        /** @var User $landlord */
        $landlord = auth()->user();

        Notification::route('mail', [$tenant->email => $tenant->full_name])
            ->notify(new TenantInvitationNotification($tenant, $landlord->name));

        return response()->json([
            'message' => "Invitation envoyée à {$tenant->email}.",
        ]);
    }

    public function destroy(Tenant $tenant)
    {
        $this->authorize('delete', $tenant);

        $tenant->delete();

        return response()->json();
    }
}
