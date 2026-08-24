<?php

namespace App\Models;

use App\Models\Concerns\Filterable;
use App\Models\Concerns\RlsProtected;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Portfolio extends Model
{
    use Filterable, HasFactory, RlsProtected;

    protected $table = 'portfolios';

    protected $fillable = [
        'name',
        'description',
        'user_id',
    ];

    /** @return list<string> */
    protected function searchable(): array
    {
        return ['name', 'description'];
    }

    /** @return list<string> */
    protected function sortable(): array
    {
        return ['name', 'created_at'];
    }

    /** @return array{0: string, 1: 'asc'|'desc'} */
    protected function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',

        // Aperçu des premiers biens, ramené par sous-requête JSON dans la
        // requête de liste (voir PortfolioController::index). Absent des
        // requêtes qui ne le demandent pas.
        'properties_preview' => 'array',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Property, $this> */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    /**
     * Compteurs du portefeuille, ramenés par sous-requêtes.
     *
     * L'écran d'un portefeuille les calculait côté client, ce qui l'obligeait à
     * charger *tous* ses biens avant d'afficher trois chiffres — et à les
     * recharger à chaque changement d'onglet. Ce sont des sous-requêtes
     * corrélées : elles voyagent dans la requête qui charge le portefeuille,
     * sans aller-retour supplémentaire.
     */
    public function scopeWithStats(Builder $query): Builder
    {
        return $query
            ->withCount('properties')
            ->withCount(['properties as occupied_properties_count' => fn (Builder $inner) => $inner->where('is_rented', true)])
            ->selectSub(
                Property::query()
                    ->withoutGlobalScopes()
                    ->selectRaw('coalesce(sum(monthly_rent), 0)')
                    ->whereColumn('properties.portfolio_id', 'portfolios.id'),
                'expected_rent'
            );
    }

    /**
     * Toute résolution de portefeuille par l'URL ramène ses compteurs.
     *
     * La requête de résolution s'exécute de toute façon — c'est elle qui permet
     * de répondre 403 plutôt qu'une liste vide. Y greffer les agrégats les rend
     * gratuits.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        return static::query()
            ->withStats()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    /**
     * Forme exposée par l'API pour un portefeuille accompagné de ses chiffres.
     *
     * @return array<string, mixed>
     */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'properties_count' => (int) ($this->properties_count ?? 0),
            'occupied_properties_count' => (int) ($this->occupied_properties_count ?? 0),
            'vacant_properties_count' => (int) ($this->properties_count ?? 0) - (int) ($this->occupied_properties_count ?? 0),
            'expected_rent' => round((float) ($this->expected_rent ?? 0), 2),
        ];
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
