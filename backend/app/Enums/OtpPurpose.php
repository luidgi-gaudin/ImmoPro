<?php

namespace App\Enums;

/**
 * Raison pour laquelle un code à usage unique a été émis.
 *
 * Le motif est stocké avec le code et vérifié à la validation. Sans lui, un
 * code demandé pour confirmer un changement d'adresse pourrait être présenté
 * pour valider une inscription : deux gestes distincts, un même secret, et
 * l'un des deux contrôles contourné.
 */
enum OtpPurpose: string
{
    /** Confirmation de l'adresse au moment de l'inscription. */
    case EmailVerification = 'email_verification';

    /** Confirmation de la nouvelle adresse lors d'un changement. */
    case EmailChange = 'email_change';

    public function subject(): string
    {
        return match ($this) {
            self::EmailVerification => 'Votre code de vérification ImmoPro',
            self::EmailChange => 'Confirmez votre nouvelle adresse e-mail',
        };
    }

    public function intent(): string
    {
        return match ($this) {
            self::EmailVerification => 'valider votre adresse e-mail et activer votre compte',
            self::EmailChange => 'confirmer votre nouvelle adresse e-mail',
        };
    }
}
