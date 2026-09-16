<?php

namespace App\Models;

use App\Enums\Dpe;
use App\Enums\LeaseStatus;
use App\Enums\PropertyType;
use App\Models\Concerns\Filterable;
use App\Models\Concerns\RlsProtected;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Property extends Model
{
    use Filterable, HasFactory, RlsProtected;

    /** @return list<string> */
    protected function searchable(): array
    {
        return ['title', 'address', 'city', 'postal_code', 'description'];
    }

    /** @return list<string> */
    protected function sortable(): array
    {
        return ['title', 'city', 'area_sqm', 'rooms', 'monthly_rent', 'created_at'];
    }

    /**
     * @return array<int|string, string|\Closure>
     */
    protected function filters(): array
    {
        return [
            'property_type',
            'dpe',
            'loue' => function (Builder $query, string $value): void {
                $expectsRented = in_array($value, ['1', 'true', 'oui'], true);

                $hasActiveLease = fn (Builder $lease) => $lease->where('statut', LeaseStatus::Actif->value);

                $expectsRented
                    ? $query->whereHas('leases', $hasActiveLease)
                    : $query->whereDoesntHave('leases', $hasActiveLease);
            },
            'min_rent' => fn (Builder $query, string $val) => is_numeric($val) ? $query->where('monthly_rent', '>=', (float) $val) : null,
            'max_rent' => fn (Builder $query, string $val) => is_numeric($val) ? $query->where('monthly_rent', '<=', (float) $val) : null,
            'min_area' => fn (Builder $query, string $val) => is_numeric($val) ? $query->where('area_sqm', '>=', (float) $val) : null,
            'max_area' => fn (Builder $query, string $val) => is_numeric($val) ? $query->where('area_sqm', '<=', (float) $val) : null,
            'rooms' => fn (Builder $query, string $val) => is_numeric($val) ? $query->where('rooms', (int) $val) : null,
            'has_balcony' => fn (Builder $query, string $val) => in_array($val, ['1', 'true'], true) ? $query->where('has_balcony', true) : null,
            'has_garden' => fn (Builder $query, string $val) => in_array($val, ['1', 'true'], true) ? $query->where('has_garden', true) : null,
            'has_parking' => fn (Builder $query, string $val) => in_array($val, ['1', 'true'], true) ? $query->where('has_parking', true) : null,
            'has_cave' => fn (Builder $query, string $val) => in_array($val, ['1', 'true'], true) ? $query->where('has_cave', true) : null,
        ];
    }

    protected $fillable = [
        'title',
        'portfolio_id',
        'property_type',
        'address',
        'city',
        'postal_code',
        'latitude',
        'longitude',
        'dpe',
        'dpe_date',
        'rooms',
        'area_sqm',
        'has_balcony',
        'has_garden',
        'has_parking',
        'has_cave',
        'is_rented',
        'monthly_rent',
        'description',
    ];

    /** @return BelongsTo<Portfolio, $this> */
    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    /** @return HasMany<Lease, $this> */
    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    protected function casts()
    {
        return [
            'property_type' => PropertyType::class,
            'dpe' => Dpe::class,
            'dpe_date' => 'date',
            'has_balcony' => 'boolean',
            'has_garden' => 'boolean',
            'has_parking' => 'boolean',
            'has_cave' => 'boolean',
            'is_rented' => 'boolean',
            'monthly_rent' => 'decimal:2',
            'area_sqm' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
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
