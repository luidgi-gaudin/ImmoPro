<?php

namespace App\Http\Controllers;

use App\Enums\NotificationChannel;
use App\Enums\NotificationTopic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Préférences de notification du compte connecté.
 *
 * L'écran est piloté par le serveur : c'est lui qui dit quels sujets existent
 * pour ce profil, ce que chacun signifie et quels canaux sont disponibles. Le
 * front n'a aucune liste en dur, et un sujet ajouté plus tard apparaît sans
 * qu'il faille redéployer l'application cliente.
 *
 * Les sujets sont filtrés par profil : proposer au bailleur de couper « relance
 * de loyer reçue » n'aurait aucun sens, c'est le locataire qui la reçoit. Un
 * écran de réglages qui liste des interrupteurs sans effet est pire qu'un écran
 * qui en liste peu.
 */
class NotificationPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'topics' => NotificationTopic::catalogue($user->role),
                'channels' => NotificationChannel::catalogue(),
                'preferences' => $user->notificationPreferences()->resolvedFor($user->role),

                /*
                 * Une adresse non vérifiée ne reçoit aucun courriel, quelle que
                 * soit la préférence. Le dire évite qu'on cherche pendant une
                 * heure pourquoi rien n'arrive alors que tout est coché.
                 */
                'mail_available' => $user->hasVerifiedEmail(),
            ],
        ]);
    }

    /**
     * Enregistre un réglage partiel.
     *
     * Seuls les sujets présents dans la requête sont touchés. Un écran qui
     * n'affiche que les sujets d'un profil ne doit pas effacer ceux de l'autre
     * en enregistrant.
     *
     * Un tableau vide est une valeur légitime — « ne me préviens par aucun
     * canal » — et se distingue d'un sujet absent, qui garde son réglage.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*' => ['array'],
            'preferences.*.*' => [Rule::enum(NotificationChannel::class)],
        ], [], $this->attributeNames());

        $allowed = array_map(
            fn (NotificationTopic $topic) => $topic->value,
            NotificationTopic::forRole($user->role)
        );

        // Un sujet qui ne concerne pas ce profil est ignoré plutôt que rejeté :
        // une requête un peu large ne doit pas faire échouer l'enregistrement
        // des réglages qui, eux, sont valables.
        $changes = array_intersect_key(
            $validated['preferences'],
            array_flip($allowed)
        );

        $user->forceFill([
            'notification_preferences' => $user->notificationPreferences()
                ->merge($changes)
                ->toArray(),
        ])->save();

        return response()->json([
            'data' => [
                'preferences' => $user->notificationPreferences()->resolvedFor($user->role),
            ],
            'message' => 'Vos préférences de notification ont été enregistrées.',
        ]);
    }

    /**
     * Libellés lisibles dans les messages d'erreur : « preferences.loyer_impaye.0 »
     * ne dit rien à personne.
     *
     * @return array<string, string>
     */
    private function attributeNames(): array
    {
        $names = [];

        foreach (NotificationTopic::cases() as $topic) {
            $names["preferences.{$topic->value}"] = $topic->label();
            $names["preferences.{$topic->value}.*"] = "canal de « {$topic->label()} »";
        }

        return $names;
    }
}
