<?php

namespace App\Http\Controllers;

use App\Enums\AlertType;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AlertController extends Controller
{
    /**
     * Liste les alertes du bailleur connecté. Par défaut, seules les alertes
     * actives (non résolues) sont retournées ; `?resolved=1` inclut l'historique.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Alert::forUser($request->user())
            ->with('alertable')
            ->latest();

        if (! $request->boolean('resolved')) {
            $query->active();
        }

        return AlertResource::collection($query->get());
    }

    public function markAsRead(Request $request, Alert $alert): AlertResource
    {
        $this->authorize('update', $alert);

        $alert->forceFill(['read_at' => $alert->read_at ?? now()])->save();

        return new AlertResource($alert);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        Alert::forUser($request->user())->unread()->update(['read_at' => now()]);

        return response()->json(['message' => 'Toutes les alertes ont été marquées comme lues.']);
    }

    public function resolve(Request $request, Alert $alert): AlertResource
    {
        $this->authorize('update', $alert);

        $alert->forceFill([
            'resolved_at' => now(),
            'read_at' => $alert->read_at ?? now(),
        ])->save();

        return new AlertResource($alert);
    }

    /**
     * Relance du locataire pour un loyer impayé. Version in-app : la relance est
     * horodatée (l'envoi email/SMS relève d'une itération ultérieure).
     */
    public function remind(Request $request, Alert $alert): JsonResponse|AlertResource
    {
        $this->authorize('update', $alert);

        if ($alert->type !== AlertType::LoyerImpaye) {
            return response()->json([
                'message' => 'La relance ne concerne que les loyers impayés.',
            ], 422);
        }

        $alert->forceFill([
            'reminded_at' => now(),
            'read_at' => $alert->read_at ?? now(),
        ])->save();

        return new AlertResource($alert);
    }
}
