<?php

namespace App\Notifications;

use App\Enums\OtpPurpose;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envoi d'un code à usage unique.
 *
 * Volontairement hors du système de préférences : refuser ce message
 * reviendrait à s'interdire de créer un compte ou de changer d'adresse. Un
 * réglage qui empêche l'utilisateur d'agir n'est pas une préférence.
 *
 * En file d'attente, pour que la réponse de l'inscription ne dépende pas du
 * temps de réponse du serveur de messagerie. Cela suppose qu'un ouvrier tourne
 * en permanence : sans lui, le message ne part jamais et rien ne le signale.
 * `php artisan immopro:mail-test` vérifie les deux bouts de la chaîne.
 *
 * Une conséquence de la file mérite d'être connue : le code voyage en clair
 * dans la charge utile de la tâche. Il est haché dans la table des comptes
 * précisément pour ne pas s'y trouver en clair, et la file rouvre cette porte —
 * étroitement, puisque la ligne disparaît dès la tâche traitée et que le code
 * ne vaut plus rien passé son délai. C'est aussi pourquoi les tentatives
 * s'arrêtent à l'expiration.
 */
class EmailOtpNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Espacement des tentatives, en secondes.
     *
     * Une panne de messagerie dure rarement moins de quelques secondes et
     * rarement plus d'une minute. Réessayer trois fois sur cette fenêtre couvre
     * l'incident passager sans marteler un serveur déjà en difficulté.
     *
     * @var list<int>
     */
    public array $backoff = [10, 30, 60];

    public function __construct(
        /**
         * Code en clair.
         *
         * Public en lecture seule : il est de toute façon sérialisé dans la
         * charge utile de la tâche, et le rendre lisible permet de vérifier
         * l'envoi sans passer par la boîte de réception.
         */
        public readonly string $code,
        private readonly OtpPurpose $purpose,
        private readonly int $validityMinutes,
    ) {}

    /**
     * Fin des tentatives.
     *
     * Calée sur la validité du code : livrer une heure plus tard un code périmé
     * depuis cinquante minutes ne rend service à personne, et n'aboutirait
     * qu'à faire saisir un code que le serveur refusera.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes($this->validityMinutes);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->purpose->subject())
            ->greeting('Bonjour '.($notifiable->name ?? '').',')
            ->line('Voici le code à saisir pour '.$this->purpose->intent().' :')
            ->line('**'.$this->spaced().'**')
            ->line("Ce code est valable {$this->validityMinutes} minutes.")
            // Formulation délibérée : elle dit quoi faire à celui qui n'a rien
            // demandé, sans l'inquiéter. Un code seul ne donne accès à rien.
            ->line('Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message : '
                .'sans ce code, personne ne peut aller plus loin.')
            ->salutation('L\'équipe ImmoPro');
    }

    /**
     * Dernière tentative épuisée.
     *
     * Sans cette trace, l'échec est parfaitement muet : la tâche disparaît dans
     * la table des tâches échouées, et personne ne fait le lien avec l'inscrit
     * qui répète que le code n'arrive pas. Le code lui-même n'est jamais
     * journalisé — un journal se lit, se copie et se conserve.
     */
    public function failed(Throwable $exception): void
    {
        /*
         * Aucune propriété n'est lue ici, et c'est délibéré.
         *
         * Une tâche mise en file avant un déploiement est désérialisée avec la
         * forme d'alors : si la classe a changé entre-temps, une propriété peut
         * rester non initialisée, et la lire lèverait. Un gestionnaire d'échec
         * qui échoue à son tour ferait perdre jusqu'à la trace de l'incident
         * d'origine — la seule chose qu'on tenait encore.
         *
         * Le message de l'exception suffit à agir : c'est lui qui distingue un
         * mot de passe refusé d'un serveur injoignable.
         */
        Log::error('Échec définitif de l\'envoi d\'un code à usage unique.', [
            'reason' => $exception->getMessage(),
        ]);
    }

    /**
     * Groupé par trois : « 418 209 » se relit et se ressaisit sans erreur,
     * là où « 418209 » se perd au milieu.
     */
    private function spaced(): string
    {
        return trim(chunk_split($this->code, 3, ' '));
    }
}
