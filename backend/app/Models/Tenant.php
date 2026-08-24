<?php

namespace App\Models;

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
        return ['last_name', 'first_name', 'email', 'created_at'];
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
        'first_name',
        'last_name',
        'email',
        'phone',
        'iban',
        'bic',
        'country',
        'address',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Lease, $this> */
    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    protected function casts()
    {
        return [
            // RGPD : coordonnées bancaires chiffrées au repos
            'iban' => 'encrypted',
            'bic' => 'encrypted',
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
