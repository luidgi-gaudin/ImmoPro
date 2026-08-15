<?php

namespace App\Models;

use App\Enums\RentPaymentStatus;
use App\Models\Concerns\Filterable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RentPayment extends Model
{
    use Filterable, HasFactory, SoftDeletes;

    /** @return list<string> */
    protected function sortable(): array
    {
        return ['period', 'paid_at', 'amount_rent'];
    }

    /**
     * L'année se déduit de la période : on borne sur l'intervalle plutôt que
     * d'extraire l'année en SQL, dont la syntaxe diffère entre PostgreSQL et
     * SQLite.
     *
     * @return array<int|string, string|\Closure>
     */
    protected function filters(): array
    {
        return [
            'annee' => function (Builder $query, string $value): void {
                if (! ctype_digit($value)) {
                    return;
                }

                $start = CarbonImmutable::create((int) $value, 1, 1)->startOfDay();

                $query->whereBetween('period', [$start, $start->addYear()->subDay()->endOfDay()]);
            },
        ];
    }

    /** @return array{0: string, 1: 'asc'|'desc'} */
    protected function defaultSort(): array
    {
        return ['period', 'desc'];
    }

    protected $fillable = [
        'lease_id',
        'period',
        'amount_rent',
        'amount_charges',
        'paid_at',
        'payment_method',
    ];

    protected $appends = ['status', 'total'];

    // La relation sert au calcul du statut, inutile de la sérialiser.
    protected $hidden = ['lease'];

    /** @return BelongsTo<Lease, $this> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /**
     * Filtre sur le statut, qui n'existe pas en base : il se déduit de paid_at
     * et de la date d'échéance.
     *
     * L'échéance dépend du payment_day du bail. Comme cette liste est toujours
     * cadrée à un seul bail, ce jour est constant sur toute la requête : on
     * calcule le mois pivot en PHP et on ne compare que des dates, ce qui reste
     * valable aussi bien sur PostgreSQL que sur le SQLite des tests.
     */
    public function scopeWithStatus(Builder $query, string $status, int $paymentDay): Builder
    {
        if ($status === RentPaymentStatus::Paye->value) {
            return $query->whereNotNull('paid_at');
        }

        if (! in_array($status, [RentPaymentStatus::EnRetard->value, RentPaymentStatus::EnAttente->value], true)) {
            return $query;
        }

        $today = CarbonImmutable::now()->startOfDay();
        $currentPeriod = $today->startOfMonth();

        // Le mois en cours n'est en retard que si son jour d'échéance est passé.
        // Un payment_day de 31 tombe au dernier jour des mois plus courts.
        $dueThisMonth = $currentPeriod->day(min($paymentDay, $currentPeriod->daysInMonth));
        $currentMonthIsLate = $today->greaterThan($dueThisMonth);

        $query->whereNull('paid_at');

        return $status === RentPaymentStatus::EnRetard->value
            ? $query->where('period', $currentMonthIsLate ? '<=' : '<', $currentPeriod)
            : $query->where('period', $currentMonthIsLate ? '>' : '>=', $currentPeriod);
    }

    protected function casts()
    {
        return [
            'period' => 'date',
            'paid_at' => 'date',
            'amount_rent' => 'decimal:2',
            'amount_charges' => 'decimal:2',
        ];
    }

    public function getStatusAttribute(): RentPaymentStatus
    {
        if ($this->paid_at !== null) {
            return RentPaymentStatus::Paye;
        }

        $paymentDay = $this->lease?->payment_day ?? 1;
        $dueDate = $this->period->copy()->day(min($paymentDay, $this->period->daysInMonth));

        return now()->startOfDay()->gt($dueDate)
            ? RentPaymentStatus::EnRetard
            : RentPaymentStatus::EnAttente;
    }

    public function getTotalAttribute(): float
    {
        return round((float) $this->amount_rent + (float) $this->amount_charges, 2);
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }
}
