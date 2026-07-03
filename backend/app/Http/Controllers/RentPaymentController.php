<?php

namespace App\Http\Controllers;

use App\Http\Requests\RentPaymentRequest;
use App\Models\Lease;
use App\Models\RentPayment;

class RentPaymentController extends Controller
{
    public function index(Lease $lease)
    {
        $this->authorize('view', $lease);

        return $lease->payments()
            ->orderByDesc('period')
            ->get()
            ->each->setRelation('lease', $lease); // évite un N+1 lors du calcul du statut
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
}
