<?php

namespace App\Http\Controllers;

use App\Enums\LeaseStatus;
use App\Http\Requests\RentPaymentRequest;
use App\Models\Lease;
use App\Models\RentPayment;
use App\Services\Leases\RentScheduleGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class RentPaymentController extends Controller
{
    public function index(Request $request, Lease $lease)
    {
        $this->authorize('view', $lease);

        $query = $lease->payments()->filtered($request);

        // Le statut n'est pas une colonne : il se déduit de paid_at et du jour
        // d'échéance du bail, que l'on connaît ici puisque la liste est cadrée
        // à ce bail. Le filtre vit donc dans le contrôleur et non dans le trait.
        $status = trim((string) ($request->query('statut_paiement') ?: $request->query('statut', '')));

        if ($status !== '') {
            $query->withStatus($status, (int) ($lease->payment_day ?? 1));
        }

        $payments = $this->paginate($query, $request);

        // Le calcul du statut relit le bail sur chaque ligne : on lui fournit
        // celui déjà chargé, sinon c'est une requête par échéance affichée.
        $payments->getCollection()->each->setRelation('lease', $lease);

        return $payments;
    }

    public function store(RentPaymentRequest $request, Lease $lease)
    {
        $this->authorize('update', $lease);

        $data = $request->validated();

        // Par défaut, l'échéance reprend le loyer et les charges du bail.
        $data['amount_rent'] ??= $lease->monthly_rent;
        $data['amount_charges'] ??= $lease->charges;

        return $lease->payments()->create($data);
    }

    public function show(Lease $lease, RentPayment $payment)
    {
        $this->authorize('view', $lease);

        return $payment;
    }

    public function update(RentPaymentRequest $request, Lease $lease, RentPayment $payment)
    {
        $this->authorize('update', $lease);

        $payment->update($request->validated());

        return $payment->refresh();
    }

    public function destroy(Lease $lease, RentPayment $payment)
    {
        $this->authorize('update', $lease);

        $payment->delete();

        return response()->json();
    }

    /**
     * Complète l'échéancier du bail sur la période demandée.
     *
     * Un bail de trois ans, c'est trente-six échéances rigoureusement
     * prévisibles : même loyer, même charges, un mois d'écart. Les saisir une
     * par une n'apportait rien qui ne soit déjà dans le bail. Seule la date de
     * règlement demande une intervention humaine, et elle se pointe ensuite en
     * un clic.
     *
     * L'opération est idempotente : les mois déjà présents sont comptés comme
     * ignorés, jamais dupliqués.
     */
    public function generate(Request $request, Lease $lease, RentScheduleGenerator $generator)
    {
        $this->authorize('update', $lease);

        if ($lease->statut === LeaseStatus::Termine) {
            return response()->json([
                'message' => 'Un bail résilié ne peut plus recevoir de nouvelles échéances.',
            ], 409);
        }

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'months' => ['nullable', 'integer', 'between:1,'.RentScheduleGenerator::MAX_MONTHS],
            'prorate' => ['sometimes', 'boolean'],
        ]);

        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'])->startOfMonth() : null;
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'])->startOfMonth() : null;

        // « Les douze prochains mois » est la formulation naturelle côté
        // écran ; elle se ramène ici à une date de fin.
        if ($to === null && isset($data['months'])) {
            // Sans point de départ explicite, « les N prochains mois » se compte
            // depuis le mois courant — ou depuis la prise d'effet si le bail
            // n'a pas encore commencé.
            $anchor = $from ?? CarbonImmutable::parse($lease->start_date)
                ->startOfMonth()
                ->max(CarbonImmutable::now()->startOfMonth());

            $to = $anchor->addMonths((int) $data['months'] - 1);
        }

        $result = $generator->generate($lease, $from, $to, (bool) ($data['prorate'] ?? true));

        return response()->json([
            'message' => $this->generationMessage($result['created'], $result['skipped']),
            ...$result,
        ]);
    }

    /**
     * Pointe le règlement d'une échéance.
     *
     * Le geste quotidien du bailleur est de constater qu'un virement est
     * arrivé. Il passait auparavant par le formulaire complet de l'échéance —
     * période, montants, moyen de paiement — alors que tout y est déjà juste.
     */
    public function pay(Request $request, Lease $lease, RentPayment $payment)
    {
        $this->authorize('update', $lease);

        $data = $request->validate([
            'paid_at' => ['nullable', 'date'],
            'payment_method' => ['nullable', 'string', 'max:50'],
        ]);

        $payment->update([
            'paid_at' => $data['paid_at'] ?? now()->toDateString(),
            'payment_method' => $data['payment_method'] ?? $payment->payment_method ?? 'Virement',
        ]);

        return $payment->refresh()->setRelation('lease', $lease);
    }

    /** Annule un pointage fait par erreur : l'échéance redevient due. */
    public function unpay(Lease $lease, RentPayment $payment)
    {
        $this->authorize('update', $lease);

        $payment->update(['paid_at' => null, 'payment_method' => null]);

        return $payment->refresh()->setRelation('lease', $lease);
    }

    /**
     * Pointe plusieurs échéances d'un coup.
     *
     * Un bailleur rapproche ses loyers par relevé bancaire, pas ligne à ligne :
     * l'écran laisse cocher les échéances reçues et n'écrit qu'une fois.
     */
    public function bulkPay(Request $request, Lease $lease)
    {
        $this->authorize('update', $lease);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
            'paid_at' => ['nullable', 'date'],
            'payment_method' => ['nullable', 'string', 'max:50'],
        ]);

        // Le filtrage par la relation est ce qui empêche de pointer l'échéance
        // d'un autre bail en glissant son identifiant dans la liste.
        $updated = $lease->payments()
            ->whereIn('id', $data['ids'])
            ->whereNull('paid_at')
            ->update([
                'paid_at' => $data['paid_at'] ?? now()->toDateString(),
                'payment_method' => $data['payment_method'] ?? 'Virement',
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => $updated === 0
                ? 'Aucune échéance à pointer dans la sélection.'
                : ($updated === 1 ? '1 échéance pointée.' : "{$updated} échéances pointées."),
            'updated' => $updated,
        ]);
    }

    /**
     * Quittance de loyer (art. 21, loi n° 89-462) : délivrée gratuitement,
     * uniquement lorsque le loyer et les charges sont intégralement payés,
     * avec le détail loyer / charges obligatoire.
     */
    public function quittance(Lease $lease, RentPayment $payment)
    {
        $this->authorize('view', $lease);

        if (! $payment->isPaid()) {
            return response()->json([
                'message' => 'La quittance ne peut être délivrée que pour un loyer intégralement payé (art. 21, loi n° 89-462).',
            ], 422);
        }

        $lease->load(['tenant', 'property.portfolio.user']);

        return response()->json([
            'quittance' => [
                'numero' => sprintf('Q-%d-%s', $lease->id, $payment->period->format('Y-m')),
                'bailleur' => [
                    'nom' => $lease->property->portfolio->user->name,
                ],
                'locataire' => [
                    'nom' => trim($lease->tenant->first_name.' '.$lease->tenant->last_name),
                ],
                'bien' => [
                    'adresse' => $lease->property->address,
                    'code_postal' => $lease->property->postal_code,
                    'ville' => $lease->property->city,
                ],
                'periode' => [
                    'debut' => $payment->period->toDateString(),
                    'fin' => $payment->period->copy()->endOfMonth()->toDateString(),
                ],
                'detail' => [
                    'loyer' => (float) $payment->amount_rent,
                    'charges' => (float) $payment->amount_charges,
                    'total' => $payment->total,
                ],
                'date_paiement' => $payment->paid_at->toDateString(),
                'date_emission' => now()->toDateString(),
                'mention_legale' => 'Quittance délivrée gratuitement conformément à l\'article 21 de la loi n° 89-462 du 6 juillet 1989. Elle annule tous les reçus qui auraient pu être établis en cas de paiement partiel de la période concernée.',
            ],
        ]);
    }

    private function generationMessage(int $created, int $skipped): string
    {
        if ($created === 0) {
            return $skipped === 0
                ? 'Aucune échéance à générer sur cette période.'
                : 'L\'échéancier était déjà à jour sur cette période.';
        }

        $label = $created === 1 ? '1 échéance générée' : "{$created} échéances générées";

        return $skipped === 0 ? $label.'.' : $label." ({$skipped} déjà présentes).";
    }
}
