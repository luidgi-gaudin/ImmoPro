<?php

namespace App\Models;

use App\Enums\Dpe;
use App\Enums\Ges;
use App\Enums\LeaseStatus;
use App\Enums\OccupancyStatus;
use App\Enums\OwnershipType;
use App\Enums\PropertyType;
use App\Models\Concerns\Filterable;
use App\Models\Concerns\RlsProtected;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * Fiche logement.
 *
 * Les transtypages sont déclarés dans casts() ; ces annotations en donnent le
 * type résultant, que l'analyse statique ne déduit pas d'une méthode.
 *
 * Les énumérations sont annoncées nullables bien que leurs colonnes ne le
 * soient pas toutes : une instance fraîchement construite, avant tout
 * enregistrement, ne porte pas encore la valeur par défaut de la base.
 *
 * @property int $id
 * @property string $title
 * @property int $portfolio_id
 * @property PropertyType|null $property_type
 * @property OccupancyStatus|null $occupancy_status
 * @property OwnershipType|null $ownership_type
 * @property Dpe|null $dpe
 * @property Ges|null $ges
 * @property Carbon|null $dpe_date
 * @property Carbon|null $dpe_expires_on
 * @property bool $is_rented
 * @property bool $is_furnished
 * @property string|null $address_complement
 * @property string|null $floor
 * @property string|null $apartment_number
 * @property string|null $syndic_name
 */
class Property extends Model
{
    use Filterable, HasFactory, RlsProtected;

    /** Durée de validité d'un DPE depuis la réforme de 2021, en années. */
    private const DPE_VALIDITY_YEARS = 10;

    /** @return list<string> */
    protected function searchable(): array
    {
        return [
            'title', 'address', 'address_complement', 'city', 'postal_code',
            'apartment_number', 'description',
        ];
    }

    /** @return list<string> */
    protected function sortable(): array
    {
        return ['title', 'city', 'area_sqm', 'rooms', 'monthly_rent', 'dpe', 'created_at'];
    }

    /**
     * @return array<int|string, string|\Closure>
     */
    protected function filters(): array
    {
        return [
            'property_type',
            'dpe',
            'ges',
            'occupancy_status',
            'ownership_type',

            /*
             * Filtre historique, conservé : il interroge le bail, pas la fiche.
             *
             * Les deux réponses peuvent diverger — un bien marqué « en travaux »
             * peut encore porter un bail actif le temps du préavis — et c'est
             * voulu : `occupancy_status` dit ce que le bailleur déclare,
             * `loue` dit ce que les contrats établissent.
             */
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
            'is_furnished' => fn (Builder $query, string $val) => in_array($val, ['1', 'true'], true) ? $query->where('is_furnished', true) : null,
            'has_balcony' => fn (Builder $query, string $val) => in_array($val, ['1', 'true'], true) ? $query->where('has_balcony', true) : null,
            'has_garden' => fn (Builder $query, string $val) => in_array($val, ['1', 'true'], true) ? $query->where('has_garden', true) : null,
            'has_terrace' => fn (Builder $query, string $val) => in_array($val, ['1', 'true'], true) ? $query->where('has_terrace', true) : null,
            'has_parking' => fn (Builder $query, string $val) => in_array($val, ['1', 'true'], true) ? $query->where('has_parking', true) : null,
            'has_garage' => fn (Builder $query, string $val) => in_array($val, ['1', 'true'], true) ? $query->where('has_garage', true) : null,
            'has_cave' => fn (Builder $query, string $val) => in_array($val, ['1', 'true'], true) ? $query->where('has_cave', true) : null,
        ];
    }

    protected $fillable = [
        'title',
        'portfolio_id',
        'property_type',

        // Adresse
        'address',
        'address_complement',
        'floor',
        'apartment_number',
        'city',
        'postal_code',
        'latitude',
        'longitude',

        // Caractéristiques
        'rooms',
        'area_sqm',
        'is_furnished',
        'has_balcony',
        'has_garden',
        'has_terrace',
        'has_parking',
        'has_garage',
        'has_cave',

        // Diagnostic de performance énergétique
        'dpe',
        'ges',
        'dpe_date',
        'dpe_expires_on',

        // Régime de propriété
        'ownership_type',
        'syndic_name',
        'syndic_contact',
        'syndic_email',
        'syndic_phone',
        'syndic_address',
        'lot_number',

        // Exploitation
        'occupancy_status',
        'is_rented',
        'monthly_rent',
        'description',
    ];

    protected $appends = ['full_address', 'dpe_is_expired'];

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
            'ges' => Ges::class,
            /*
             * Sérialisé en « AAAA-MM-JJ », sans heure.
             *
             * La colonne est une date : lui adjoindre un horodatage UTC est au
             * mieux du bruit, au pire une erreur — un champ de saisie de type
             * `date` ne sait pas lire « 2023-04-15T00:00:00.000000Z » et
             * s'affiche vide, et une conversion de fuseau peut décaler le jour.
             */
            'dpe_date' => 'date:Y-m-d',
            'dpe_expires_on' => 'date:Y-m-d',
            'ownership_type' => OwnershipType::class,
            'occupancy_status' => OccupancyStatus::class,
            'is_furnished' => 'boolean',
            'has_balcony' => 'boolean',
            'has_garden' => 'boolean',
            'has_terrace' => 'boolean',
            'has_parking' => 'boolean',
            'has_garage' => 'boolean',
            'has_cave' => 'boolean',
            'is_rented' => 'boolean',
            'lot_number' => 'integer',
            'monthly_rent' => 'decimal:2',
            'area_sqm' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    /**
     * Tient l'état locatif et le booléen historique en phase, et déduit
     * l'expiration du DPE de sa date.
     *
     * L'expiration reste modifiable une fois posée : les diagnostics antérieurs
     * à la réforme de 2021 ont vu leur validité écourtée par la loi Climat et
     * Résilience, et leur échéance ne se calcule pas.
     */
    protected static function booted(): void
    {
        static::saving(function (Property $property): void {
            $property->syncOccupancy();

            if ($property->dpe_date !== null && $property->dpe_expires_on === null) {
                $property->dpe_expires_on = $property->dpe_date
                    ->copy()
                    ->addYears(self::DPE_VALIDITY_YEARS);
            }
        });
    }

    /**
     * Aligne `occupancy_status` et `is_rented` dans le sens de la modification.
     *
     * Deux écritures concurrentes visent l'occupation, et faire primer l'une
     * casserait l'autre. Le bailleur pose l'état depuis la fiche du bien ;
     * le modèle Lease, lui, remet `is_rented` à jour dès qu'un bail devient
     * actif ou s'achève — c'est le contrat qui fait foi, pas une case oubliée.
     *
     * Le sens de la synchronisation est donc donné par le champ qui vient de
     * changer. Un bail qui se termine laisse « en travaux » en place plutôt que
     * de le ramener à « vacant » : le logement rendu n'est pas pour autant
     * reloué, et écraser cette déclaration ferait disparaître un chantier en
     * cours de la liste des biens indisponibles.
     */
    private function syncOccupancy(): void
    {
        if ($this->isDirty('occupancy_status') && $this->occupancy_status !== null) {
            $this->is_rented = $this->occupancy_status === OccupancyStatus::Loue;

            return;
        }

        if (! $this->isDirty('is_rented')) {
            return;
        }

        if ($this->is_rented) {
            $this->occupancy_status = OccupancyStatus::Loue;

            return;
        }

        if ($this->occupancy_status !== OccupancyStatus::EnTravaux) {
            $this->occupancy_status = OccupancyStatus::Vacant;
        }
    }

    /* ----------------------------------------------------------------------
     | Attributs dérivés
     |----------------------------------------------------------------------*/

    /**
     * Adresse postale sur une ligne, telle qu'on l'écrirait sur une enveloppe.
     *
     * Les segments vides sont écartés plutôt que rendus par une virgule
     * orpheline : « 3 rue des Lilas, , 75011 Paris » se voit tout de suite.
     */
    public function getFullAddressAttribute(): string
    {
        $lodging = array_filter([
            $this->apartment_number ? "Appt {$this->apartment_number}" : null,
            $this->floor ? "étage {$this->floor}" : null,
        ]);

        return implode(', ', array_filter([
            $this->address,
            $this->address_complement,
            $lodging === [] ? null : implode(' ', $lodging),
            trim($this->postal_code.' '.$this->city),
        ]));
    }

    public function getDpeIsExpiredAttribute(): bool
    {
        return $this->dpe_expires_on !== null && $this->dpe_expires_on->isPast();
    }

    /** Un bien en copropriété dont le syndic n'est pas renseigné. */
    public function missesSyndicDetails(): bool
    {
        return $this->ownership_type === OwnershipType::Copropriete
            && blank($this->syndic_name);
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
