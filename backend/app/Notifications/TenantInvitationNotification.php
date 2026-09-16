<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation adressée au locataire à ouvrir son espace.
 *
 * Ne contient aucun jeton d'accès, et c'est délibéré. Le lien mène au
 * formulaire d'inscription ordinaire ; le rattachement au dossier se fera tout
 * seul, une fois l'adresse vérifiée par code (voir TenantAccountLinker). Un
 * lien porteur d'un jeton aurait donné un accès au dossier à quiconque
 * intercepte le message ou récupère l'historique d'une boîte partagée.
 *
 * Hors préférences : le destinataire n'a pas encore de compte, donc pas de
 * réglages. C'est le bailleur qui décide d'inviter, et l'invitation est le seul
 * message qu'il enverra à cette adresse tant que rien ne s'ouvre.
 */
class TenantInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Tenant $tenant,
        private readonly string $landlordName,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.frontend_url'), '/')
            .'/register?role=locataire&email='.urlencode((string) $this->tenant->email);

        return (new MailMessage)
            ->subject('Votre espace locataire ImmoPro')
            ->greeting("Bonjour {$this->tenant->first_name},")
            ->line("{$this->landlordName} vous invite à ouvrir votre espace locataire ImmoPro.")
            ->line('Vous y consulterez votre bail, les informations de votre logement et vos quittances, '
                .'et vous pourrez y déposer vos justificatifs.')
            ->action('Créer mon espace', $url)
            ->line('Utilisez bien cette adresse e-mail lors de votre inscription : '
                .'c\'est elle qui rattachera votre dossier.')
            ->salutation('L\'équipe ImmoPro');
    }
}
