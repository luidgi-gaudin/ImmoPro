<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationTopic;
use App\Enums\UserRole;
use App\Support\NotificationPreferences;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * Compte de connexion, bailleur ou locataire.
 *
 * Le rôle n'est pas un niveau de privilège mais un point de vue : le bailleur
 * gère un parc, le locataire consulte le sien. Les deux passent par le même
 * formulaire de connexion.
 *
 * `MustVerifyEmail` est implémenté mais l'application n'emploie pas le lien de
 * vérification de Laravel : elle passe par un code à usage unique (voir
 * App\Services\Auth\EmailOtpService), qui écrit lui-même `email_verified_at`.
 * L'interface reste utile — elle est ce que le reste du framework interroge
 * pour savoir si l'adresse est confirmée.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $pending_email
 * @property string|null $phone
 * @property string|null $password
 * @property string|null $avatar_path
 * @property UserRole $role
 * @property array<string, mixed>|null $notification_preferences
 * @property string|null $otp_code_hash
 * @property string|null $otp_purpose
 * @property Carbon|null $otp_sent_at
 * @property Carbon|null $otp_expires_at
 * @property int $otp_attempts
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * Profil par défaut, aligné sur celui de la colonne.
     *
     * Sans cette valeur, une instance non relue depuis la base porte un rôle
     * `null` : la base applique bien son défaut à l'insertion, mais l'objet en
     * mémoire l'ignore, et tout ce qui lit le rôle juste après la création
     * tombe sur rien.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => UserRole::Proprietaire->value,
    ];

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
        'iban',
        'bic',
        'siret',
        'siren',
        'currency',
        'avatar_path',
        'notification_preferences',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'iban',
        'bic',
        'siret',
        'siren',
        'two_factor_secret',
        'two_factor_recovery_codes',

        // Un code à usage unique est un mot de passe temporaire : ni lui ni son
        // condensat n'ont à traverser une réponse d'API.
        'otp_code_hash',
        'otp_purpose',
        'otp_expires_at',
        'otp_attempts',

        // Chemin de stockage : le connaître ne donne pas accès au fichier, mais
        // renseigne sur l'organisation du disque privé.
        'avatar_path',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'iban' => 'encrypted',
            'bic' => 'encrypted',
            'siret' => 'encrypted',
            'siren' => 'encrypted',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'notification_preferences' => 'array',
            'otp_sent_at' => 'datetime',
            'otp_expires_at' => 'datetime',
            'otp_attempts' => 'integer',
        ];
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * Ce compte peut-il se connecter par mot de passe ?
     *
     * Répond à la question posée au moment de détacher un fournisseur externe :
     * reste-t-il un autre moyen d'entrer ? Un compte ouvert par Google n'a pas
     * de mot de passe du tout, et détacher Google sans le vérifier reviendrait
     * à en fermer la porte de l'intérieur.
     */
    public function hasUsablePassword(): bool
    {
        return filled($this->password);
    }

    public function isLandlord(): bool
    {
        return $this->role === UserRole::Proprietaire;
    }

    public function isTenant(): bool
    {
        return $this->role === UserRole::Locataire;
    }

    /* ----------------------------------------------------------------------
     | Préférences de notification
     |----------------------------------------------------------------------*/

    public function notificationPreferences(): NotificationPreferences
    {
        return NotificationPreferences::fromArray($this->notification_preferences);
    }

    /**
     * Ce compte accepte-t-il ce sujet sur ce canal ?
     *
     * Une adresse non vérifiée ne reçoit rien par courriel, quelle que soit la
     * préférence : envoyer à une adresse que personne n'a confirmée, c'est au
     * mieux écrire dans le vide, au pire adresser les échéances de loyer d'un
     * inconnu à celui qui a fait la faute de frappe.
     */
    public function acceptsNotification(NotificationTopic $topic, NotificationChannel $channel): bool
    {
        if ($channel === NotificationChannel::Mail && ! $this->hasVerifiedEmail()) {
            return false;
        }

        return $this->notificationPreferences()->allows($topic, $channel);
    }

    /**
     * Canaux retenus pour un sujet, au format attendu par `Notification::via()`.
     *
     * @return list<string>
     */
    public function notificationChannelsFor(NotificationTopic $topic): array
    {
        return array_values(array_map(
            fn (NotificationChannel $channel) => $channel->value,
            array_filter(
                $this->notificationPreferences()->channelsFor($topic),
                fn (NotificationChannel $channel) => $this->acceptsNotification($topic, $channel)
            )
        ));
    }

    /* ----------------------------------------------------------------------
     | Relations
     |----------------------------------------------------------------------*/

    /** @return HasMany<Portfolio, $this> */
    public function portfolios(): HasMany
    {
        return $this->hasMany(Portfolio::class);
    }

    /**
     * Fiches locataire **gérées** par ce compte, en tant que bailleur.
     *
     * À ne pas confondre avec `tenantProfiles()`, qui désigne les fiches dont
     * ce compte est le sujet. Les deux relations pointent vers la même table
     * par deux colonnes différentes, et les intervertir donnerait à un bailleur
     * la vue d'un locataire.
     *
     * @return HasMany<Tenant, $this>
     */
    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    /**
     * Fiches locataire **dont ce compte est le titulaire**.
     *
     * Au pluriel : une même personne peut louer chez deux bailleurs, qui ont
     * chacun créé leur fiche. Son espace réunit les deux dossiers.
     *
     * @return HasMany<Tenant, $this>
     */
    public function tenantProfiles(): HasMany
    {
        return $this->hasMany(Tenant::class, 'account_user_id');
    }

    /** @return HasMany<SocialAccount, $this> */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /** @return HasMany<Alert, $this> */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    /**
     * Anonymise les données personnelles sans casser les FK.
     * À appeler avant ou à la place d'un hard delete.
     */
    public function anonymize(): void
    {
        $this->update([
            'name' => 'Utilisateur supprimé',
            'email' => "deleted_{$this->id}@anonymized.local",
            'phone' => null,
            'password' => bcrypt(str()->random(32)),
            'iban' => null,
            'bic' => null,
            'siret' => null,
            'siren' => null,
            'avatar_path' => null,
        ]);

        $this->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'otp_code_hash' => null,
            'otp_purpose' => null,
            'otp_expires_at' => null,
        ])->save();

        // Les comptes Google et Apple rattachés doivent partir avec : les
        // laisser rendrait le compte anonymisé réactivable d'un clic.
        $this->socialAccounts()->delete();

        $this->tokens()->delete(); // Révoque les tokens Sanctum
        $this->delete(); // soft delete
    }
}
