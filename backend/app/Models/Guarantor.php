<?php

namespace App\Models;

use App\Enums\GuaranteeType;
use App\Models\Concerns\RlsProtected;
use Database\Factories\GuarantorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Garant d'un locataire : personne physique qui se porte caution, ou organisme
 * qui couvre les loyers.
 *
 * @property int $tenant_id
 * @property GuaranteeType $guarantee_type
 * @property Carbon|null $ends_on
 * @property float|null $monthly_income
 */
class Guarantor extends Model
{
    /** @use HasFactory<GuarantorFactory> */
    use HasFactory, RlsProtected, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'guarantee_type',
        'first_name',
        'last_name',
        'birth_date',
        'profession',
        'company_name',
        'email',
        'phone',
        'address',
        'postal_code',
        'city',
        'country',
        'monthly_income',
        'contract_reference',
        'starts_on',
        'ends_on',
        'max_amount',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'guarantee_type' => GuaranteeType::class,
            /*
             * Sérialisé en « AAAA-MM-JJ », sans heure.
             *
             * La colonne est une date : lui adjoindre un horodatage UTC est au
             * mieux du bruit, au pire une erreur — un champ de saisie de type
             * `date` ne sait pas lire « 2023-04-15T00:00:00.000000Z » et
             * s'affiche vide, et une conversion de fuseau peut décaler le jour.
             */
            'birth_date' => 'date:Y-m-d',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'monthly_income' => 'decimal:2',
            'max_amount' => 'decimal:2',
        ];
    }

    protected $appends = ['display_name', 'is_expired'];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Nom à afficher, qu'il s'agisse d'une personne ou d'un organisme.
     *
     * Une garantie Visale n'a ni prénom ni nom : afficher une ligne vide dans
     * la liste des garants revient à laisser croire que le dossier est
     * incomplet alors qu'il est couvert.
     */
    public function getDisplayNameAttribute(): string
    {
        $person = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));

        return $person !== ''
            ? $person
            : ($this->company_name ?? $this->guarantee_type->label());
    }

    /**
     * L'engagement est-il arrivé à son terme ?
     *
     * Un cautionnement échu ne couvre plus rien, et c'est précisément quand un
     * impayé survient qu'on s'en aperçoit. La fiche doit le dire d'elle-même.
     */
    public function getIsExpiredAttribute(): bool
    {
        return $this->ends_on !== null && $this->ends_on->isPast();
    }

    /** Garanties encore en vigueur à la date du jour. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->whereNull('ends_on')
            ->orWhereDate('ends_on', '>=', now()));
    }

    /**
     * La règle d'usage : des revenus au moins égaux à trois fois le loyer.
     *
     * Ce n'est pas une obligation légale mais la pratique constante des
     * bailleurs et des assureurs de loyers impayés. Renvoie `null` quand la
     * question ne se pose pas — un organisme n'a pas de salaire.
     */
    public function coversRent(float $monthlyRent, float $ratio = 3.0): ?bool
    {
        if (! $this->guarantee_type->isPersonal() || $this->monthly_income === null) {
            return null;
        }

        return (float) $this->monthly_income >= $monthlyRent * $ratio;
    }
}
