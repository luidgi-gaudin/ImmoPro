<?php

namespace App\Notifications;

use App\Enums\OtpPurpose;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Envoi d'un code à usage unique.
 *
 * Volontairement hors du système de préférences : refuser ce message
 * reviendrait à s'interdire de créer un compte ou de changer d'adresse. Un
 * réglage qui empêche l'utilisateur d'agir n'est pas une préférence.
 *
 * En file d'attente, pour que la réponse HTTP de l'inscription ne dépende pas
 * du temps de réponse du serveur de messagerie.
 */
class EmailOtpNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $code,
        private readonly OtpPurpose $purpose,
        private readonly int $validityMinutes,
    ) {}

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
     * Groupé par trois : « 418 209 » se relit et se ressaisit sans erreur,
     * là où « 418209 » se perd au milieu.
     */
    private function spaced(): string
    {
        return trim(chunk_split($this->code, 3, ' '));
    }
}
