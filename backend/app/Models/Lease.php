<?php

namespace App\Models;

use App\Enums\LeaseStatus;
use App\Enums\LeaseType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lease extends Model
{
    use HasFactory, SoftDeletes;

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

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
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
