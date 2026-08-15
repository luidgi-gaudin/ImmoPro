<?php

namespace App\Models;

use App\Enums\LeaseStatus;
use App\Enums\LeaseType;
use App\Models\Concerns\Filterable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lease extends Model
{
    use Filterable, HasFactory, SoftDeletes;

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
}
