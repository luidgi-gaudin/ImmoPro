<?php

namespace App\Services\Leases;

use App\Models\Lease;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Génération de l'échéancier de loyer d'un bail.
 *
 * Saisir les échéances une par une était le geste le plus coûteux de
 * l'application : un bail de trois ans, c'est trente-six formulaires à remplir
 * à la main, tous identiques à la date près. Or tout y est déductible du bail
 * lui-même — le loyer, les charges, le mois, le jour d'échéance. Seule la date
 * de règlement ne l'est pas, et c'est précisément la seule information que le
 * bailleur possède réellement.
 *
 * L'échéancier est donc posé d'avance, à la création du bail puis à la demande,
 * et il ne reste plus qu'à pointer les règlements au fil de l'eau.
 *
 * Deux propriétés comptent :
 *
 * - **Idempotence.** Un mois déjà présent n'est jamais dupliqué. Relancer la
 *   génération sur un bail à jour ne crée rien, et sur un bail en retard ne
 *   crée que ce qui manque. C'est ce qui permet d'exposer le bouton sans
 *   crainte, plutôt que de le réserver à un bail vierge.
 * - **Prorata temporis.** Un bail qui prend effet le 15 ne doit pas appeler un
 *   mois plein pour son premier mois (art. 1728 du code civil : le loyer est dû
 *   pour la jouissance effective). Le premier et le dernier mois sont donc
 *   calculés au jour près, les autres au tarif plein.
 */
class RentScheduleGenerator
{
    /**
     * Horizon par défaut d'un bail sans date de fin, en mois.
     *
     * Douze mois suffisent à couvrir l'année de gestion sans encombrer la liste
     * d'échéances lointaines qu'une révision de loyer rendrait fausses.
     */
    public const DEFAULT_HORIZON_MONTHS = 12;

    /** Garde-fou : au-delà, c'est une saisie erronée, pas un besoin réel. */
    public const MAX_MONTHS = 120;

    /**
     * Complète l'échéancier du bail sur la période demandée.
     *
     * @param  CarbonImmutable|null  $from  Premier mois à couvrir (défaut : mois de prise d'effet).
     * @param  CarbonImmutable|null  $to  Dernier mois à couvrir (défaut : fin du bail, ou horizon glissant).
     * @param  bool  $prorate  Calcule au prorata les mois d'entrée et de sortie.
     * @return array{created: int, skipped: int, from: string, to: string}
     */
    public function generate(
        Lease $lease,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        bool $prorate = true,
    ): array {
        $start = CarbonImmutable::parse($lease->start_date)->startOfDay();
        $end = $lease->end_date === null ? null : CarbonImmutable::parse($lease->end_date)->startOfDay();

        $firstMonth = ($from ?? $start)->startOfMonth();
        $lastMonth = ($to ?? $this->defaultHorizon($start, $end))->startOfMonth();

        // Une échéance ne peut pas précéder la prise d'effet ni survivre au terme :
        // la période demandée est rabotée sur le bail, jamais l'inverse.
        $firstMonth = $firstMonth->max($start->startOfMonth());

        if ($end !== null) {
            $lastMonth = $lastMonth->min($end->startOfMonth());
        }

        if ($lastMonth->lessThan($firstMonth)) {
            return ['created' => 0, 'skipped' => 0, 'from' => $firstMonth->toDateString(), 'to' => $firstMonth->toDateString()];
        }

        $lastMonth = $lastMonth->min($firstMonth->addMonths(self::MAX_MONTHS - 1));

        // Les mois déjà pointés sont lus en une fois : un existsById par mois
        // aurait coûté un aller-retour par échéance.
        $existing = $lease->payments()
            ->whereBetween('period', [$firstMonth->toDateString(), $lastMonth->endOfMonth()->toDateString()])
            ->pluck('period')
            ->map(fn ($period) => CarbonImmutable::parse($period)->format('Y-m'))
            ->flip();

        $rows = [];
        $skipped = 0;
        $now = now();

        for ($month = $firstMonth; $month->lessThanOrEqualTo($lastMonth); $month = $month->addMonth()) {
            if ($existing->has($month->format('Y-m'))) {
                $skipped++;

                continue;
            }

            $ratio = $prorate ? $this->occupancyRatio($month, $start, $end) : 1.0;

            if ($ratio <= 0.0) {
                continue;
            }

            $rows[] = [
                'lease_id' => $lease->id,
                'period' => $month->toDateString(),
                'amount_rent' => round((float) $lease->monthly_rent * $ratio, 2),
                'amount_charges' => round((float) $lease->charges * $ratio, 2),
                'paid_at' => null,
                'payment_method' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            // Une seule requête, quel que soit le nombre de mois : sur une base
            // distante à 135 ms l'aller-retour, insérer trente-six lignes une à
            // une prendrait cinq secondes.
            DB::table('rent_payments')->insert($rows);
        }

        return [
            'created' => count($rows),
            'skipped' => $skipped,
            'from' => $firstMonth->toDateString(),
            'to' => $lastMonth->toDateString(),
        ];
    }

    /**
     * Dernier mois couvert lorsque l'appelant n'en impose pas.
     *
     * Un bail borné va jusqu'à son terme. Un bail sans terme suit un horizon
     * glissant d'un an à partir du mois courant — ou de sa prise d'effet si
     * elle est à venir, pour qu'un bail signé d'avance soit couvert dès sa
     * première année.
     */
    private function defaultHorizon(CarbonImmutable $start, ?CarbonImmutable $end): CarbonImmutable
    {
        if ($end !== null) {
            return $end;
        }

        $pivot = $start->greaterThan(CarbonImmutable::now()) ? $start : CarbonImmutable::now();

        return $pivot->startOfMonth()->addMonths(self::DEFAULT_HORIZON_MONTHS - 1);
    }

    /**
     * Part du mois réellement occupée, entre 0 et 1.
     *
     * Vaut 1 pour tout mois entièrement couvert. Le mois d'entrée et le mois de
     * sortie sont comptés au jour près, bornes incluses : un bail du 15 au 31
     * mars doit dix-sept jours sur trente et un, pas la moitié.
     */
    private function occupancyRatio(CarbonImmutable $month, CarbonImmutable $start, ?CarbonImmutable $end): float
    {
        $monthStart = $month->startOfMonth();
        $monthEnd = $month->endOfMonth()->startOfDay();

        $occupiedFrom = $start->greaterThan($monthStart) ? $start : $monthStart;
        $occupiedTo = ($end !== null && $end->lessThan($monthEnd)) ? $end : $monthEnd;

        if ($occupiedTo->lessThan($occupiedFrom)) {
            return 0.0;
        }

        $days = $occupiedFrom->diffInDays($occupiedTo) + 1;

        return round($days / $month->daysInMonth, 6);
    }
}
