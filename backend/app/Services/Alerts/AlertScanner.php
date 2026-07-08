<?php

namespace App\Services\Alerts;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\LeaseStatus;
use App\Models\Alert;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentPayment;
use Illuminate\Support\Carbon;

/**
 * Moteur d'alertes proactives d'ImmoPro.
 *
 * Analyse l'état des loyers, baux et DPE et matérialise les évènements à
 * surveiller sous forme d'alertes in-app. Le service est idempotent : chaque
 * évènement possède une clé de déduplication (`dedup_key`) qui garantit qu'une
 * alerte n'est créée qu'une seule fois, quel que soit le nombre d'exécutions.
 *
 * Destiné à être appelé quotidiennement via la commande `alerts:scan`.
 */
class AlertScanner
{
    /** Un loyer non enregistré est signalé à J+3 de son échéance. */
    private const UNPAID_GRACE_DAYS = 3;

    /** La révision annuelle IRL est anticipée 30 jours à l'avance (art. 17-1, loi n° 89-462). */
    private const IRL_NOTICE_DAYS = 30;

    /** Paliers d'anticipation avant la fin du bail, du plus lointain au plus proche. */
    private const LEASE_END_THRESHOLDS_MONTHS = [6, 3, 1];

    /** Durée de validité d'un DPE (réforme 2021, loi Climat et Résilience). */
    private const DPE_VALIDITY_YEARS = 10;

    /** Le DPE est signalé 3 mois avant son expiration. */
    private const DPE_NOTICE_MONTHS = 3;

    private const FRENCH_MONTHS = [
        1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril',
        5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août',
        9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
    ];

    /**
     * Exécute l'ensemble des règles et retourne le nombre d'alertes nouvellement créées.
     */
    public function scan(): int
    {
        $today = now()->startOfDay();

        return $this->scanUnpaidRent($today)
            + $this->scanRentRevision($today)
            + $this->scanLeaseEnd($today)
            + $this->scanDpeExpiration($today);
    }

    /**
     * Loyer impayé : une alerte est levée exactement à J+3 de l'échéance d'un
     * loyer non enregistré, une seule fois par loyer. Les alertes dont le loyer
     * a depuis été réglé sont automatiquement résolues.
     */
    private function scanUnpaidRent(Carbon $today): int
    {
        $created = 0;

        $payments = RentPayment::whereNull('paid_at')
            ->with(['lease.property.portfolio.user', 'lease.tenant'])
            ->get();

        foreach ($payments as $payment) {
            $lease = $payment->lease;
            $owner = $lease?->property?->portfolio?->user;

            if (! $owner) {
                continue;
            }

            // Échéance = jour de paiement du bail, borné au nombre de jours du mois.
            $paymentDay = $lease->payment_day ?? 1;
            $dueDate = $payment->period->copy()->day(min($paymentDay, $payment->period->daysInMonth));

            // On attend J+3 après l'échéance avant de déclencher l'alerte.
            if ($today->lt($dueDate->copy()->addDays(self::UNPAID_GRACE_DAYS))) {
                continue;
            }

            $created += $this->upsert([
                'user_id' => $owner->id,
                'type' => AlertType::LoyerImpaye,
                'severity' => AlertSeverity::Critical,
                'dedup_key' => "loyer_impaye:payment:{$payment->id}",
                'title' => 'Loyer impayé',
                'message' => sprintf(
                    'Le loyer de %s (%s) n\'a pas été enregistré. Vous pouvez relancer le locataire.',
                    $this->frenchMonthYear($payment->period),
                    $this->tenantName($lease),
                ),
                'due_date' => $dueDate,
                'meta' => [
                    'period' => $payment->period->toDateString(),
                    'amount' => $payment->total,
                ],
            ], $payment);
        }

        // Auto-résolution : les impayés désormais réglés ne doivent plus encombrer la liste.
        Alert::where('type', AlertType::LoyerImpaye)
            ->whereNull('resolved_at')
            ->where('alertable_type', $this->morphClass(RentPayment::class))
            ->whereIn('alertable_id', function ($query) {
                $query->select('id')->from('rent_payments')->whereNotNull('paid_at');
            })
            ->update(['resolved_at' => now()]);

        return $created;
    }

    /**
     * Révision IRL : 30 jours avant la date à laquelle la révision annuelle du
     * loyer devient possible (art. 17-1, loi n° 89-462). La date de référence est
     * la dernière révision, ou à défaut la date de début du bail, + 1 an.
     */
    private function scanRentRevision(Carbon $today): int
    {
        $created = 0;

        $leases = Lease::where('statut', LeaseStatus::Actif->value)
            ->with('property.portfolio.user')
            ->get();

        foreach ($leases as $lease) {
            $owner = $lease->property?->portfolio?->user;

            if (! $owner) {
                continue;
            }

            $baseDate = $lease->last_rent_revision_at ?? $lease->start_date;
            $revisionDate = $baseDate->copy()->addYear();

            // On alerte à partir de J-30 (et tant que la révision n'a pas été appliquée).
            if ($today->lt($revisionDate->copy()->subDays(self::IRL_NOTICE_DAYS))) {
                continue;
            }

            $created += $this->upsert([
                'user_id' => $owner->id,
                'type' => AlertType::RevisionIrl,
                'severity' => AlertSeverity::Warning,
                // La clé inclut le cycle de révision : un nouveau cycle => une nouvelle alerte.
                'dedup_key' => "revision_irl:lease:{$lease->id}:{$revisionDate->format('Y-m')}",
                'title' => 'Révision annuelle du loyer',
                'message' => sprintf(
                    'La révision du loyer indexée sur l\'IRL est possible à partir du %s. Appliquez le nouvel indice INSEE du trimestre de référence.',
                    $revisionDate->format('d/m/Y'),
                ),
                'due_date' => $revisionDate,
                'meta' => [
                    'current_rent' => (float) $lease->monthly_rent,
                    'revisable_from' => $revisionDate->toDateString(),
                ],
            ], $lease);
        }

        return $created;
    }

    /**
     * Fin de bail : alerte à 6, 3 puis 1 mois de l'échéance. À chaque scan on ne
     * matérialise que le palier courant (le plus proche atteint), ce qui produit
     * dans le temps trois alertes distinctes, une par palier franchi.
     */
    private function scanLeaseEnd(Carbon $today): int
    {
        $created = 0;

        $leases = Lease::where('statut', LeaseStatus::Actif->value)
            ->whereNotNull('end_date')
            ->with('property.portfolio.user')
            ->get();

        foreach ($leases as $lease) {
            $owner = $lease->property?->portfolio?->user;
            $endDate = $lease->end_date;

            if (! $owner || $endDate->lt($today)) {
                continue; // pas de propriétaire, ou bail déjà arrivé à terme
            }

            // Palier courant = plus petit seuil dont la fenêtre est ouverte.
            $milestone = null;
            foreach (array_reverse(self::LEASE_END_THRESHOLDS_MONTHS) as $months) { // [1, 3, 6]
                if ($endDate->lte($today->copy()->addMonths($months))) {
                    $milestone = $months;
                    break;
                }
            }

            if ($milestone === null) {
                continue; // échéance à plus de 6 mois
            }

            $severity = match ($milestone) {
                1 => AlertSeverity::Critical,
                3 => AlertSeverity::Warning,
                default => AlertSeverity::Info,
            };

            $created += $this->upsert([
                'user_id' => $owner->id,
                'type' => AlertType::FinBail,
                'severity' => $severity,
                'dedup_key' => "fin_bail:lease:{$lease->id}:{$milestone}",
                'title' => 'Fin de bail à préparer',
                'message' => sprintf(
                    'Le bail de %s se termine le %s (dans environ %d mois). Anticipez le renouvellement ou le congé.',
                    $this->tenantName($lease),
                    $endDate->format('d/m/Y'),
                    $milestone,
                ),
                'due_date' => $endDate,
                'meta' => [
                    'end_date' => $endDate->toDateString(),
                    'months_before' => $milestone,
                ],
            ], $lease);
        }

        return $created;
    }

    /**
     * Expiration du DPE : alerte 3 mois avant la fin de validité (10 ans), et
     * en criticité maximale une fois le DPE expiré.
     */
    private function scanDpeExpiration(Carbon $today): int
    {
        $created = 0;

        $properties = Property::whereNotNull('dpe_date')
            ->with('portfolio.user')
            ->get();

        foreach ($properties as $property) {
            $owner = $property->portfolio?->user;

            if (! $owner) {
                continue;
            }

            $expiresAt = $property->dpe_date->copy()->addYears(self::DPE_VALIDITY_YEARS);

            if ($today->lt($expiresAt->copy()->subMonths(self::DPE_NOTICE_MONTHS))) {
                continue;
            }

            $isExpired = $today->gte($expiresAt);

            $created += $this->upsert([
                'user_id' => $owner->id,
                'type' => AlertType::DpeExpiration,
                'severity' => $isExpired ? AlertSeverity::Critical : AlertSeverity::Warning,
                // La clé inclut la date du DPE : un DPE renouvelé => une nouvelle alerte.
                'dedup_key' => "dpe_expiration:property:{$property->id}:{$property->dpe_date->format('Y-m-d')}",
                'title' => $isExpired ? 'DPE expiré' : 'DPE bientôt expiré',
                'message' => sprintf(
                    '%s Le DPE du bien « %s » %s le %s.',
                    $isExpired ? 'Action requise :' : 'À anticiper :',
                    $property->title,
                    $isExpired ? 'a expiré' : 'expire',
                    $expiresAt->format('d/m/Y'),
                ),
                'due_date' => $expiresAt,
                'meta' => [
                    'dpe_class' => $property->dpe?->value,
                    'dpe_date' => $property->dpe_date->toDateString(),
                    'expires_at' => $expiresAt->toDateString(),
                ],
            ], $property);
        }

        return $created;
    }

    /**
     * Crée l'alerte si sa clé de déduplication est inédite, sinon rafraîchit son
     * contenu volatil (titre, message, sévérité...) sans jamais écraser l'état de
     * lecture / résolution / relance déjà positionné par le bailleur.
     *
     * @return int 1 si une nouvelle alerte a été créée, 0 sinon.
     */
    private function upsert(array $attributes, ?object $alertable = null): int
    {
        $alert = Alert::firstOrNew(['dedup_key' => $attributes['dedup_key']]);
        $isNew = ! $alert->exists;

        if ($alertable) {
            $attributes['alertable_type'] = $alertable->getMorphClass();
            $attributes['alertable_id'] = $alertable->getKey();
        }

        $alert->fill($attributes)->save();

        return $isNew ? 1 : 0;
    }

    private function tenantName(Lease $lease): string
    {
        $name = trim(($lease->tenant->first_name ?? '').' '.($lease->tenant->last_name ?? ''));

        return $name !== '' ? $name : 'votre locataire';
    }

    private function frenchMonthYear(Carbon $date): string
    {
        return self::FRENCH_MONTHS[$date->month].' '.$date->year;
    }

    private function morphClass(string $model): string
    {
        return (new $model)->getMorphClass();
    }
}
