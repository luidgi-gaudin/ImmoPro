<?php

namespace App\Services\Notifications;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\NotificationChannel;
use App\Models\Alert;
use App\Models\User;
use App\Notifications\TenantMessageNotification;
use Illuminate\Database\Eloquent\Model;

/**
 * Achemine un message vers le bailleur, en respectant ses préférences.
 *
 * Pendant de TenantNotifier, en plus simple : le bailleur a toujours un compte,
 * puisque c'est lui qui a créé le dossier. Il n'y a donc pas de repli sur une
 * adresse de contact, et pas de cas « destinataire injoignable ».
 */
class LandlordNotifier
{
    /**
     * @return list<string> Canaux réellement employés.
     */
    public function send(
        User $landlord,
        AlertType $type,
        string $subject,
        string $body,
        ?Model $about = null,
        AlertSeverity $severity = AlertSeverity::Info,
        ?string $dedupKey = null,
    ): array {
        $used = [];

        if ($landlord->acceptsNotification($type->topic(), NotificationChannel::Mail)) {
            $landlord->notify(new TenantMessageNotification(
                $type,
                $subject,
                $body,
                rtrim((string) config('app.frontend_url'), '/').'/documents',
                'Ouvrir mes documents',
            ));

            $used[] = NotificationChannel::Mail->value;
        }

        if ($landlord->acceptsNotification($type->topic(), NotificationChannel::Database)) {
            Alert::firstOrCreate(
                ['dedup_key' => $dedupKey ?? sprintf(
                    '%s:%d:%s',
                    $type->value,
                    $landlord->id,
                    $about === null ? uniqid() : $about::class.'#'.$about->getKey()
                )],
                [
                    'user_id' => $landlord->id,
                    'type' => $type->value,
                    'severity' => $severity->value,
                    'alertable_type' => $about?->getMorphClass(),
                    'alertable_id' => $about?->getKey(),
                    'title' => $subject,
                    'message' => $body,
                ]
            );

            $used[] = NotificationChannel::Database->value;
        }

        return $used;
    }
}
