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
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
 * @property int|null $payments_count
 * @property int|null $payments_paid_count
 * @property int|null $payments_overdue_count
 * @property int|null $payments_current_month_unpaid
 * @property string|null $payments_outstanding_amount
 * @property string|null $payments_last_period
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
    protected $hidden = [
        'owner_user_id',
        'payments_count',
        'payments_paid_count',
        'payments_overdue_count',
        'payments_current_month_unpaid',
        'payments_outstanding_amount',
        'payments_last_period',
    ];

    protected $fillable = [
        'property_id',
        'tenant_id',
        'type',
        'start_date',
        'end_date',
        'duration_months',
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
     * Ramène l'état de l'échéancier par sous-requêtes, dans la requête qui
     * charge déjà le bail.
     *
     * La fiche d'un bail doit répondre à trois questions avant toute autre :
     * combien d'échéances sont posées, combien restent impayées, jusqu'à quand
     * l'échéancier est couvert. Les demander séparément aurait coûté un
     * aller-retour de plus — sur Supabase, autant que la moitié du budget d'une
     * page. Glissées dans la liste de sélection, elles ne coûtent rien de
     * mesurable : la table est indexée sur (lease_id, period).
     *
     * Le mois pivot est calculé en PHP et inséré comme littéral, plutôt que par
     * `date_trunc` / `strftime` dont la syntaxe diffère entre PostgreSQL et le
     * SQLite des tests.
     */
    public function scopeWithPaymentSummary(Builder $query): Builder
    {
        if (empty($query->getQuery()->columns)) {
            $query->select($this->qualifyColumn('*'));
        }

        $currentMonth = now()->startOfMonth()->toDateString();
        $endOfCurrentMonth = now()->endOfMonth()->toDateString();

        $base = fn (): QueryBuilder => DB::table('rent_payments')
            ->whereColumn('rent_payments.lease_id', $this->qualifyColumn('id'))
            ->whereNull('rent_payments.deleted_at');

        return $query
            ->selectSub($base()->selectRaw('count(*)'), 'payments_count')
            ->selectSub($base()->whereNotNull('paid_at')->selectRaw('count(*)'), 'payments_paid_count')
            ->selectSub(
                $base()->whereNull('paid_at')->where('period', '<', $currentMonth)->selectRaw('count(*)'),
                'payments_overdue_count'
            )
            ->selectSub(
                $base()->whereNull('paid_at')
                    ->where('period', '<=', $endOfCurrentMonth)
                    ->selectRaw('coalesce(sum(amount_rent + amount_charges), 0)'),
                'payments_outstanding_amount'
            )
            ->selectSub(
                $base()->whereNull('paid_at')
                    ->whereBetween('period', [$currentMonth, $endOfCurrentMonth])
                    ->selectRaw('count(*)'),
                'payments_current_month_unpaid'
            )
            ->selectSub($base()->selectRaw('max(period)'), 'payments_last_period');
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
            ->withPaymentSummary()
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
            'duration_months' => 'integer',
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
     * Durée contractuelle du bail en mois, null quand elle est inconnue.
     *
     * La durée saisie au contrat prime sur l'écart entre les deux dates, et ce
     * n'est pas une préférence de style : les deux divergent dès la première
     * reconduction tacite. Un bail de trois ans reconduit une fois court sur
     * six ans de dates, mais reste un bail de trois ans — c'est cette durée-là
     * qui commande le préavis et le régime applicable, et c'est elle que
     * `durationNotice()` doit examiner.
     *
     * À défaut, l'écart entre les dates sert de repli, avec la tolérance d'un
     * jour des dates de fin inclusives : un bail du 1er septembre au 31 mai
     * dure bien neuf mois, pas huit.
     */
    public function durationInMonths(): ?int
    {
        if ($this->duration_months !== null) {
            return $this->duration_months;
        }

        return $this->elapsedDurationInMonths();
    }

    /**
     * Nombre de mois couverts par les dates, reconductions comprises.
     *
     * À ne pas confondre avec la durée contractuelle : c'est l'étendue réelle
     * de l'occupation, utile pour un décompte, pas pour un contrôle de
     * conformité.
     */
    public function elapsedDurationInMonths(): ?int
    {
        if ($this->end_date === null) {
            return null;
        }

        return (int) $this->start_date->copy()->startOfDay()
            ->diffInMonths($this->end_date->copy()->addDay()->startOfDay());
    }

    /**
     * Avertissement de conformité sur la durée, ou null si le bail suit la durée
     * de droit commun de son type.
     *
     * L'application accepte les durées inférieures à cette référence : elles
     * sont licites dans des cas précis qu'un logiciel ne peut pas vérifier (un
     * motif de reprise pour le bail vide, art. 11). Mais elle ne les laisse pas
     * passer en silence — le bailleur doit savoir ce qu'il signe.
     *
     * C'est une propriété du bail, pas du formulaire : l'avertissement
     * réapparaît chaque fois qu'on ouvre la fiche, et pas seulement le jour de
     * la saisie.
     */
    public function durationNotice(): ?string
    {
        $months = $this->durationInMonths();

        if ($months === null) {
            return null;
        }

        $standard = $this->type->standardDurationInMonths();

        if ($standard !== null && $months < $standard) {
            return $this->type === LeaseType::Nu
                ? "Ce bail court sur {$months} mois, en deçà des trois ans de droit commun. "
                    ."Une durée réduite n'est licite que si un événement familial ou professionnel "
                    .'justifiant la reprise du logement est mentionné dans le contrat (art. 11, loi n° 89-462).'
                : "Ce bail court sur {$months} mois, en deçà de la durée de droit commun de {$standard} mois "
                    .'pour ce type de contrat (loi n° 89-462).';
        }

        // Le bail étudiant de neuf mois échappe à la reconduction tacite. Passé
        // ce terme, la règle ne s'applique plus, et c'est exactement le genre de
        // conséquence qu'on découvre trop tard.
        if ($this->type === LeaseType::Etudiant && $months > 9) {
            return "Ce bail étudiant court sur {$months} mois. Au-delà de neuf mois, "
                .'la reconduction tacite du meublé ordinaire redevient applicable (art. 25-7).';
        }

        return null;
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
     * État de l'échéancier, mis en forme depuis les colonnes ramenées par
     * `scopeWithPaymentSummary()`.
     *
     * Renvoie `null` quand le bail n'a pas été chargé avec ce scope — c'est le
     * cas des listes, qui n'affichent pas ce détail et n'ont donc pas à le
     * payer.
     *
     * Le retard du mois en cours ne se décide qu'ici : la sous-requête ne peut
     * pas connaître le jour d'échéance du bail, qui varie d'un contrat à
     * l'autre. La règle appliquée est la même que celle de
     * `RentPayment::getStatusAttribute()`, y compris pour un jour d'échéance
     * tombant après le dernier jour d'un mois court.
     *
     * @return array<string, mixed>|null
     */
    public function paymentSchedule(): ?array
    {
        $count = $this->getAttribute('payments_count');

        if ($count === null) {
            return null;
        }

        $currentMonth = now()->startOfMonth();
        $dueThisMonth = $currentMonth->copy()->day(min($this->payment_day ?? 1, $currentMonth->daysInMonth));

        $overdue = (int) $this->getAttribute('payments_overdue_count');

        if (now()->startOfDay()->gt($dueThisMonth)) {
            $overdue += (int) $this->getAttribute('payments_current_month_unpaid');
        }

        $lastPeriod = $this->getAttribute('payments_last_period');
        $lastPeriod = $lastPeriod === null ? null : Carbon::parse($lastPeriod)->startOfMonth();

        return [
            'count' => (int) $count,
            'paid_count' => (int) $this->getAttribute('payments_paid_count'),
            'unpaid_count' => (int) $count - (int) $this->getAttribute('payments_paid_count'),
            'overdue_count' => $overdue,
            'outstanding_amount' => round((float) $this->getAttribute('payments_outstanding_amount'), 2),
            // Dernier mois couvert et premier mois manquant : de quoi préremplir
            // la génération sans que l'écran ait à deviner où l'échéancier s'arrête.
            'last_period' => $lastPeriod?->toDateString(),
            'next_period' => $lastPeriod === null
                ? $this->start_date->copy()->startOfMonth()->toDateString()
                : $lastPeriod->copy()->addMonth()->toDateString(),
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
