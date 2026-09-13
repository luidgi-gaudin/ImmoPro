<?php

namespace App\Http\Controllers;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use App\Models\RentPayment;
use App\Services\Notifications\TenantNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class AlertController extends Controller
{
    /**
     * Liste les alertes du bailleur connecté. Par défaut, seules les alertes
     * actives (non résolues) sont retournées ; `?resolved=1` inclut l'historique.
     * Accepte aussi ?search=, ?type=, ?severity=, ?sort= et ?per_page=.
     */
    public function index(Request $request): LengthAwarePaginator
    {
        // Pas de `with('alertable')` : la relation polymorphe déclenche une
        // requête par type d'entité concerné, et AlertResource n'expose que le
        // type et l'identifiant — que la ligne porte déjà.
        $query = Alert::forUser($request->user())->filtered($request);

        if (! $request->boolean('resolved')) {
            $query->active();
        }

        // through() applique AlertResource à chaque ligne tout en conservant
        // l'enveloppe plate du paginateur (data, current_page, total…).
        // AlertResource::collection() aurait produit une enveloppe meta/links,
        // différente de celle des autres listes de l'API.
        return $this->paginate($query, $request)
            ->through(fn (Alert $alert) => new AlertResource($alert));
    }

    public function markAsRead(Request $request, Alert $alert): AlertResource
    {
        $this->authorize('update', $alert);

        $alert->forceFill(['read_at' => $alert->read_at ?? now()])->save();

        return new AlertResource($alert);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        Alert::forUser($request->user())->unread()->update(['read_at' => now()]);

        return response()->json(['message' => 'Toutes les alertes ont été marquées comme lues.']);
    }

    public function resolve(Request $request, Alert $alert): AlertResource
    {
        $this->authorize('update', $alert);

        $alert->forceFill([
            'resolved_at' => now(),
            'read_at' => $alert->read_at ?? now(),
        ])->save();

        return new AlertResource($alert);
    }

    /**
     * Relance le locataire pour un loyer impayé.
     *
     * Envoie réellement le message, là où cette route se contentait d'un
     * horodatage. « Relancé le 12 septembre » sans que rien ne parte est pire
     * qu'un bouton absent : le bailleur croit avoir agi, le locataire n'a rien
     * reçu, et le litige se construit sur cette croyance.
     *
     * Le canal suit les préférences du locataire quand il a un compte, et se
     * rabat sur l'adresse du dossier sinon. Voir TenantNotifier.
     */
    public function remind(Request $request, Alert $alert, TenantNotifier $notifier): JsonResponse|AlertResource
    {
        $this->authorize('update', $alert);

        if ($alert->type !== AlertType::LoyerImpaye) {
            return response()->json([
                'message' => 'La relance ne concerne que les loyers impayés.',
            ], 422);
        }

        $payment = $alert->alertable_id === null
            ? null
            : RentPayment::with('lease.tenant')->find($alert->alertable_id);

        $tenant = $payment?->lease?->tenant;

        $channels = $tenant === null ? [] : $notifier->send(
            tenant: $tenant,
            type: AlertType::RelanceLoyer,
            subject: 'Rappel : loyer en attente de règlement',
            body: $alert->message,
            about: $payment,
            severity: AlertSeverity::Warning,
            // Une relance par échéance et par jour : trois clics sur le bouton
            // ne doivent pas remplir la cloche du locataire trois fois.
            dedupKey: "relance_loyer:payment:{$alert->alertable_id}:".now()->toDateString(),
        );

        $alert->forceFill([
            'reminded_at' => now(),
            'read_at' => $alert->read_at ?? now(),
        ])->save();

        /*
         * L'horodatage est posé même quand rien ne part, et la réponse le dit.
         *
         * Échouer ici serait pire : le bailleur a bien effectué son geste, et
         * l'absence de destinataire joignable n'est pas une erreur de sa part.
         * Mais le taire le serait tout autant — « relancé le 12 septembre »
         * alors que personne n'a rien reçu, c'est la croyance sur laquelle se
         * construisent les litiges.
         */
        if ($channels === []) {
            return response()->json([
                'data' => new AlertResource($alert),
                'channels' => [],
                'message' => 'Relance notée, mais aucun message n\'est parti : ce locataire n\'a '
                    .'ni adresse e-mail ni espace en ligne.',
            ]);
        }

        return response()->json([
            'data' => new AlertResource($alert),
            'channels' => $channels,
            'message' => 'Le locataire a été relancé.',
        ]);
    }
}
