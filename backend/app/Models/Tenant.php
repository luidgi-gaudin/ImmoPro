<?php

namespace App\Models;

use App\Enums\IdentityDocumentType;
use App\Enums\LeaseStatus;
use App\Models\Concerns\Filterable;
use App\Models\Concerns\RlsProtected;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Dossier locataire tenu par un bailleur.
 *
 * Deux liens vers `users`, à ne jamais confondre :
 *
 *   - `user_id` est le **bailleur** propriétaire du dossier. C'est lui que
 *     comparent les policies d'isolation ; s'en écarter ouvrirait le dossier à
 *     un tiers.
 *   - `account_user_id` est le **locataire** lui-même, quand il s'est inscrit.
 *     Il ne possède rien : il obtient un droit de lecture sur son propre
 *     dossier et de dépôt de pièces, rien de plus.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $account_user_id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $email
 * @property Carbon|null $birth_date
 * @property IdentityDocumentType|null $identity_document_type
 * @property Carbon|null $archived_at
 */
class Tenant extends Model
{
    use Filterable, HasFactory, RlsProtected, SoftDeletes;

    /** @return list<string> */
    protected function searchable(): array
    {
        return ['first_name', 'last_name', 'email', 'phone', 'address', 'country'];
    }

    /** @return list<string> */
    protected function sortable(): array
    {
        return ['last_name', 'first_name', 'email', 'birth_date', 'created_at'];
    }

    /**
     * @return array<int|string, string|\Closure>
     */
    protected function filters(): array
    {
        return [
            'country',
            'loue' => function (Builder $query, string $value): void {
                $expectsRented = in_array($value, ['1', 'true', 'oui'], true);
                $hasActiveLease = fn (Builder $lease) => $lease->where('statut', LeaseStatus::Actif->value);

                $expectsRented
                    ? $query->whereHas('leases', $hasActiveLease)
                    : $query->whereDoesntHave('leases', $hasActiveLease);
            },
            'avec_garant' => function (Builder $query, string $value): void {
                in_array($value, ['1', 'true', 'oui'], true)
                    ? $query->whereHas('guarantors')
                    : $query->whereDoesntHave('guarantors');
            },
            'avec_compte' => function (Builder $query, string $value): void {
                in_array($value, ['1', 'true', 'oui'], true)
                    ? $query->whereNotNull('account_user_id')
                    : $query->whereNull('account_user_id');
            },
        ];
    }

    /**
     * Un répertoire de personnes se consulte par ordre alphabétique : on y
     * cherche quelqu'un de précis, pas la dernière fiche créée.
     *
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    protected function defaultSort(): array
    {
        return ['last_name', 'asc'];
    }

    protected $fillable = [
        'user_id',
        'account_user_id',
        'first_name',
        'last_name',
        'birth_date',
        'birth_place',
        'identity_document_type',
        'identity_document_number',
        'photo_path',
        'email',
        'phone',
        'iban',
        'bic',
        'country',
        'address',
        'archived_at',
    ];

    /**
     * Le chemin de stockage du portrait ne circule pas : la photo se demande
     * par une URL signée, comme toute pièce du dossier.
     *
     * @var list<string>
     */
    protected $hidden = ['photo_path'];

    protected $appends = ['full_name', 'is_archived', 'has_account'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Compte de connexion du locataire, s'il en a ouvert un.
     *
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_user_id');
    }

    /** @return HasMany<Lease, $this> */
    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    /** @return HasMany<Guarantor, $this> */
    public function guarantors(): HasMany
    {
        return $this->hasMany(Guarantor::class);
    }

    protected function casts()
    {
        return [
            // RGPD : coordonnées bancaires chiffrées au repos
            'iban' => 'encrypted',
            'bic' => 'encrypted',

            // Donnée d'identification directe : elle ouvre l'usurpation
            // d'identité, pas seulement le démarchage.
            'identity_document_number' => 'encrypted',

            'identity_document_type' => IdentityDocumentType::class,
            /*
             * Sérialisé en « AAAA-MM-JJ », sans heure.
             *
             * La colonne est une date : lui adjoindre un horodatage UTC est au
             * mieux du bruit, au pire une erreur — un champ de saisie de type
             * `date` ne sait pas lire « 2023-04-15T00:00:00.000000Z » et
             * s'affiche vide, et une conversion de fuseau peut décaler le jour.
             */
            'birth_date' => 'date:Y-m-d',
            'archived_at' => 'datetime',
        ];
    }

    /* ----------------------------------------------------------------------
     | Attributs dérivés
     |----------------------------------------------------------------------*/

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function getIsArchivedAttribute(): bool
    {
        return $this->archived_at !== null;
    }

    public function getHasAccountAttribute(): bool
    {
        return $this->account_user_id !== null;
    }

    /** Âge révolu, quand la date de naissance est connue. */
    public function age(): ?int
    {
        return $this->birth_date?->age;
    }

    /* ----------------------------------------------------------------------
     | Archivage
     |----------------------------------------------------------------------*/

    public function archive(): void
    {
        $this->forceFill(['archived_at' => now()])->save();
    }

    public function unarchive(): void
    {
        $this->forceFill(['archived_at' => null])->save();
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Applique le choix d'archivage venu de l'URL, dossiers actifs par défaut.
     *
     * Traité à part des autres filtres, parce que c'est le seul dont l'absence
     * doit *restreindre* : `Filterable` ignore un paramètre manquant, ce qui
     * ferait réapparaître les dossiers clos dans la liste de travail — soit
     * exactement ce que l'archivage sert à éviter.
     */
    public function scopeArchiveFilter(Builder $query, Request $request): Builder
    {
        return match ((string) $request->query('archives', '')) {
            'inclus' => $query,
            'seuls' => $query->whereNotNull('archived_at'),
            default => $query->whereNull('archived_at'),
        };
    }

    /**
     * Pièces jointes rattachées à cet élément.
     *
     * @return MorphMany<Document, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
