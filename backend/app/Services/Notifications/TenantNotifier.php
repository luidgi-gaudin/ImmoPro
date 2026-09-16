<?php

namespace App\Services\Notifications;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\NotificationChannel;
use App\Models\Alert;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantMessageNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;

/**
 * Achemine un message vers un locataire, en respectant ses préférences.
 *
 * Un seul point de passage pour tout ce que le bailleur envoie, parce que trois
 * décisions doivent être prises ensemble et de la même façon partout :
 *
 *   1. **Le destinataire a-t-il un compte ?** La grande majorité des dossiers se
 *      gèrent sans que le locataire se connecte jamais. Sans compte, il n'y a ni
 *      préférences ni cloche : le courriel part sur l'adresse du dossier, ou
 *      rien ne part du tout.
 *   2. **Que veut-il recevoir, et par où ?** C'est l'objet des préférences, et
 *      elles ne valent que si tout le monde les consulte.
 *   3. **Que garde-t-on ?** Le canal « dans l'application » n'écrit pas dans la
 *      table des notifications de Laravel mais dans `alerts`, celle que
 *      l'application lit déjà partout. Deux réservoirs de notifications auraient
 *      donné deux compteurs, deux écrans, et une pastille qui ne compte que la
 *      moitié.
 */
class TenantNotifier
{
    /**
     * @param  Model|null  $about  Entité concernée (bail, échéance), pour que
     *                             l'alerte pointe vers quelque chose.
     * @return list<string> Canaux réellement employés.
     */
    public function send(
        Tenant $tenant,
        AlertType $type,
        string $subject,
        string $body,
        ?Model $about = null,
        AlertSeverity $severity = AlertSeverity::Info,
        ?string $dedupKey = null,
    ): array {
        $account = $tenant->account()->first();

        if ($account === null) {
            return $this->sendToUnregistered($tenant, $subject, $body, $type);
        }

        $used = [];

        if ($account->acceptsNotification($type->topic(), NotificationChannel::Mail)) {
            $account->notify(new TenantMessageNotification(
                $type,
                $subject,
                $body,
                rtrim((string) config('app.frontend_url'), '/').'/espace-locataire',
            ));

            $used[] = NotificationChannel::Mail->value;
        }

        if ($account->acceptsNotification($type->topic(), NotificationChannel::Database)) {
            $this->recordAlert($account, $tenant, $type, $subject, $body, $about, $severity, $dedupKey);

            $used[] = NotificationChannel::Database->value;
        }

        return $used;
    }

    /**
     * Locataire sans compte : le courriel part à l'adresse du dossier.
     *
     * Aucune préférence à consulter — il n'y a pas de compte pour en porter —
     * et aucune alerte à enregistrer, faute de destinataire à qui l'attribuer.
     * L'adresse a été saisie par le bailleur pour son dossier : lui écrire à
     * propos de ce dossier est précisément ce pour quoi elle a été donnée.
     *
     * @return list<string>
     */
    private function sendToUnregistered(Tenant $tenant, string $subject, string $body, AlertType $type): array
    {
        if (blank($tenant->email)) {
            return [];
        }

        Notification::route('mail', [$tenant->email => $tenant->full_name])
            ->notify(new TenantMessageNotification($type, $subject, $body));

        return [NotificationChannel::Mail->value];
    }

    /**
     * Écrit la notification dans la cloche du locataire.
     *
     * `dedup_key` est unique en base : c'est ce qui empêche un bailleur qui
     * clique trois fois sur « relancer » de remplir la cloche de son locataire
     * avec trois fois le même message. À défaut de clé fournie, la date du jour
     * borne la répétition à une par jour et par sujet.
     */
    private function recordAlert(
        User $account,
        Tenant $tenant,
        AlertType $type,
        string $subject,
        string $body,
        ?Model $about,
        AlertSeverity $severity,
        ?string $dedupKey,
    ): void {
        $key = $dedupKey ?? sprintf(
            '%s:%d:%s:%s',
            $type->value,
            $tenant->id,
            $about === null ? 'sans-objet' : $about::class.'#'.$about->getKey(),
            now()->toDateString()
        );

        Alert::firstOrCreate(
            ['dedup_key' => $key],
            [
                'user_id' => $account->id,
                'type' => $type->value,
                'severity' => $severity->value,
                'alertable_type' => $about?->getMorphClass(),
                'alertable_id' => $about?->getKey(),
                'title' => $subject,
                'message' => $body,
            ]
        );
    }
}
