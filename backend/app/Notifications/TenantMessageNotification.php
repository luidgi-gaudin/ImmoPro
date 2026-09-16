<?php

namespace App\Notifications;

use App\Enums\AlertType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Message adressé au locataire par son bailleur : relance, mise à disposition
 * d'une quittance, information.
 *
 * Le contenu est composé par l'appelant plutôt que par la notification :
 * le même gabarit sert quatre sujets, et écrire quatre classes qui ne
 * diffèrent que par deux phrases n'aurait rien clarifié.
 *
 * Ne décide pas de son canal : `Notification::via()` renvoie ce que
 * TenantNotifier lui passe, après consultation des préférences. Une
 * notification qui choisirait elle-même son canal court-circuiterait
 * l'écran de réglages.
 */
class TenantMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly AlertType $type,
        private readonly string $subject,
        private readonly string $body,
        private readonly ?string $actionUrl = null,
        private readonly ?string $actionLabel = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->subject)
            ->greeting('Bonjour '.($notifiable->name ?? '').',')
            ->line($this->body);

        if ($this->actionUrl !== null) {
            $message->action($this->actionLabel ?? 'Ouvrir mon espace', $this->actionUrl);
        }

        return $message
            ->line('Vous pouvez régler les messages que vous recevez depuis votre profil ImmoPro.')
            ->salutation('L\'équipe ImmoPro');
    }

    public function type(): AlertType
    {
        return $this->type;
    }
}
