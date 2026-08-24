<?php

namespace App\Models;

use App\Enums\LeaseStatus;
use App\Enums\LeaseType;
use App\Models\Concerns\Filterable;
use App\Models\Concerns\RlsProtected;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Bail d'habitation.
 *
 * Les casts sont déclarés dans casts() ; ces annotations en donnent le type
 * résultant, que l'analyse statique ne déduit pas d'une méthode.
 *
 * @property int $id
 * @property int $property_id
 * @property int|null $tenant_id
 * @property LeaseType $type
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property Carbon|null $last_rent_revision_at
 * @property string $monthly_rent
 * @property string $charges
 * @property string|null $deposit
 * @property int|null $payment_day
 * @property LeaseStatus $statut
 * @property int|null $owner_user_id
 * @property int|null $documents_count
 */
class Lease extends Model
{
    use Filterable, HasFactory, RlsProtected, SoftDeletes;

    /**
     * @return list<string>
     */
    protected function searchable(): array
    {
        return ['tenant.first_name', 'tenant.last_name', 'property.title', 'property.city', 'property.address'];
    }

    /** @return list<string> */
    protected function sortable(): array
    {
        return ['start_date', 'end_date', 'monthly_rent', 'statut', 'created_at'];
    }

    /**
     * @return array<int|string, string|\Closure>
     */
    protected function filters(): array
    {
        return [
            'type',
            'statut',
            'tenant_id',
            'property_id',
            'min_rent' => fn (Builder $query, string $val) => is_numeric($val) ? $query->where('monthly_rent', '>=', (float) $val) : null,
            'max_rent' => fn (Builder $query, string $val) => is_numeric($val) ? $query->where('monthly_rent', '<=', (float) $val) : null,
        ];
    }

    /**
     * Les baux les plus récents en premier : ce sont ceux sur lesquels on
     * travaille.
     *
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    protected function defaultSort(): array
    {
        return ['start_date', 'desc'];
    }

    /**
     * `owner_user_id` est une colonne de travail ajoutée par scopeWithOwner() :
     * elle sert à la policy, pas à l'API.
     *
     * @var list<string>
     */
    protected $hidden = ['owner_user_id'];

    protected $fillable = [
        'property_id',
        'tenant_id',
        'type',
        'start_date',
        'end_date',
        'monthly_rent',
        'charges',
        'deposit',
        'payment_day',
        'statut',
    ];

    /**
     * Ramène l'identifiant du bailleur propriétaire par sous-requête.
     *
     * Sans cela, LeasePolicy doit remonter la chaîne bail → bien → portefeuille
     * par une requête dédiée, sur *chaque* action portant sur un bail : afficher,
     * modifier, résilier, lister les échéances. Soit 150 ms ajoutés à chaque
     * fois, pour une information que la requête de chargement du bail pouvait
     * rapporter au passage.
     */
    public function scopeWithOwner(Builder $query): Builder
    {
        if (empty($query->getQuery()->columns)) {
            $query->select($this->qualifyColumn('*'));
        }

        return $query->selectSub(
            Portfolio::query()
                // La sous-requête sert à *décider* du droit d'accès : elle doit
                // voir la ligne telle qu'elle est en base, sans être filtrée par
                // le scope RLS du modèle — sans quoi un refus se lirait comme
                // une absence de portefeuille.
                ->withoutGlobalScopes()
                ->select('portfolios.user_id')
                ->join('properties', 'properties.portfolio_id', '=', 'portfolios.id')
                ->whereColumn('properties.id', $this->qualifyColumn('property_id'))
                ->limit(1),
            'owner_user_id'
        );
    }

    /**
     * Résout un bail depuis l'URL en ramenant au passage son propriétaire.
     *
     * C'est ce qui rend LeasePolicy gratuite : sans cela, chaque action sur un
     * bail — afficher, modifier, résilier, lister ses échéances — paierait un
     * aller-retour de plus pour remonter la chaîne bien → portefeuille.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        return static::query()
            ->withOwner()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Colocataires additionnels (au-delà du locataire principal), avec répartition
     * optionnelle du loyer par colocataire.
     *
     * @return BelongsToMany<Tenant, $this>
     */
    public function coTenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'lease_tenant')
            ->withPivot('rent_share')
            ->withTimestamps();
    }

    /** @return HasMany<RentPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(RentPayment::class);
    }

    /**
     * Photos de l'état des lieux d'entrée et de sortie.
     *
     * @return HasMany<LeasePhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(LeasePhoto::class);
    }

    protected function casts()
    {
        return [
            'type' => LeaseType::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'monthly_rent' => 'decimal:2',
            'charges' => 'decimal:2',
            'deposit' => 'decimal:2',
            'last_rent_revision_at' => 'date',
            'statut' => LeaseStatus::class,
        ];
    }

    protected static function booted(): void
    {
        // Maintient properties.is_rented en phase avec l'existence d'un bail actif.
        $syncIsRented = function (Lease $lease) {
            $property = $lease->property()->first();

            $property?->update([
                'is_rented' => $property->leases()->where('statut', LeaseStatus::Actif->value)->exists(),
            ]);
        };

        static::saved($syncIsRented);
        static::deleted($syncIsRented);
        static::restored($syncIsRented);
    }

    public function isActive(): bool
    {
        return $this->statut === LeaseStatus::Actif;
    }

    /**
     * Plafond légal du dépôt de garantie (loi n° 89-462).
     */
    public function depositCap(): float
    {
        return round($this->type->depositCapInMonths() * (float) $this->monthly_rent, 2);
    }

    /**
     * La révision annuelle du loyer est possible si aucune révision
     * n'a eu lieu depuis 12 mois (art. 17-1, loi n° 89-462).
     */
    public function canReviseRent(): bool
    {
        return $this->last_rent_revision_at === null
            || $this->last_rent_revision_at->lte(now()->subYear());
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
