<?php

namespace App\Http\Controllers;

use App\Enums\AlertType;
use App\Enums\DocumentCategory;
use App\Enums\LeaseStatus;
use App\Enums\RentPaymentStatus;
use App\Http\Requests\TenantSpaceDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\Lease;
use App\Models\RentPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Notifications\LandlordNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Espace du locataire : ses logements, ses baux, ses pièces.
 *
 * Le point de vue s'inverse par rapport au reste de l'API. Ailleurs, une
 * requête part du bailleur et descend vers ses biens ; ici, elle part des
 * fiches locataire rattachées au compte connecté et remonte vers les baux, puis
 * vers les logements. Le locataire ne possède aucune de ces lignes — elles sont
 * au bailleur — et n'y accède qu'en lecture, plus le dépôt de pièces.
 *
 * Toutes les requêtes de ce contrôleur sont bornées par les identifiants rendus
 * par `profileIds()`. Ce n'est pas une redondance avec la Row Level Security :
 * les policies protègent la base en production, mais les tests tournent sur
 * SQLite, qui n'en a pas. Un contrôleur qui ne se bornerait que par la policy
 * serait vert en test et ouvert en local.
 *
 * Ce qui reste volontairement hors de portée : le portefeuille, les autres
 * biens du bailleur, ses coordonnées bancaires, ses documents de gestion — un
 * mandat ou une taxe foncière ne regarde pas le locataire.
 */
class TenantSpaceController extends Controller
{
    /**
     * Vue d'ensemble : dossiers, baux en cours, prochaine échéance, impayés.
     */
    public function overview(Request $request): JsonResponse
    {
        $profiles = $this->profiles($request);

        if ($profiles->isEmpty()) {
            return response()->json([
                'data' => [
                    'profiles' => [],
                    'leases' => [],
                    'summary' => $this->emptySummary(),
                ],
                /*
                 * Cas fréquent, et déroutant s'il n'est pas expliqué : le compte
                 * existe, mais aucun bailleur n'a encore inscrit cette adresse
                 * à un dossier. Un écran vide sans un mot ressemble à une panne.
                 */
                'message' => 'Aucun dossier de location n\'est encore rattaché à votre adresse. '
                    .'Votre bailleur doit l\'enregistrer sur votre fiche locataire.',
            ]);
        }

        $leases = $this->leaseQuery($profiles->pluck('id'))->get();

        return response()->json([
            'data' => [
                'profiles' => $profiles->map(fn (Tenant $tenant) => [
                    'id' => $tenant->id,
                    'full_name' => $tenant->full_name,
                    'email' => $tenant->email,
                    'phone' => $tenant->phone,
                ])->values(),

                'leases' => $leases->map(fn (Lease $lease) => $this->leasePayload($lease))->values(),

                'summary' => $this->summary($leases),
            ],
        ]);
    }

    /**
     * Fiche d'un bail : logement, échéancier, pièces.
     *
     * La liaison de route n'est pas employée : `Lease $lease` rendrait n'importe
     * quel bail sur SQLite, où aucune policy ne filtre. La recherche est donc
     * bornée aux dossiers du compte, et un identifiant étranger donne un 404 —
     * pas un 403, qui confirmerait que le bail existe.
     */
    public function lease(Request $request, int $lease): JsonResponse
    {
        $profiles = $this->profiles($request);

        $found = $this->leaseQuery($profiles->pluck('id'))
            ->whereKey($lease)
            ->first();

        abort_if($found === null, 404, 'Ce bail ne fait pas partie de vos dossiers.');

        return response()->json([
            'data' => $this->leasePayload($found) + [
                'payments' => $found->payments
                    ->sortByDesc('period')
                    ->map(fn (RentPayment $payment) => [
                        'id' => $payment->id,
                        'period' => $payment->period?->toDateString(),
                        'amount_rent' => (float) $payment->amount_rent,
                        'amount_charges' => (float) $payment->amount_charges,
                        'total' => (float) $payment->total,
                        'status' => $payment->status,
                        'paid_at' => $payment->paid_at?->toDateString(),
                    ])->values(),
            ],
        ]);
    }

    /* ----------------------------------------------------------------------
     | Documents
     |----------------------------------------------------------------------*/

    /**
     * Pièces du dossier : celles déposées par le bailleur comme les siennes.
     */
    public function documents(Request $request)
    {
        $profileIds = $this->profiles($request)->pluck('id');
        $leaseIds = $this->leaseIds($profileIds);

        $query = Document::query()
            ->select('documents.*')
            ->where(function (Builder $scope) use ($profileIds, $leaseIds): void {
                $scope->where(fn (Builder $inner) => $inner
                    ->where('documentable_type', Tenant::class)
                    ->whereIn('documentable_id', $profileIds));

                $scope->orWhere(fn (Builder $inner) => $inner
                    ->where('documentable_type', Lease::class)
                    ->whereIn('documentable_id', $leaseIds));
            })
            ->filtered($request);

        return $this->paginate($query, $request)
            ->through(fn (Document $document) => new DocumentResource($document));
    }

    /**
     * Catégories que le locataire peut déposer.
     *
     * Servies par le serveur plutôt que codées dans le front : la liste des
     * pièces qu'on lui demande relève de la règle métier, pas de l'affichage.
     */
    public function documentCategories(): JsonResponse
    {
        return response()
            ->json([
                'categories' => array_map(fn (DocumentCategory $category) => [
                    'value' => $category->value,
                    'label' => $category->label(),
                    'attachable_to' => array_map(
                        fn (string $class) => strtolower(class_basename($class)),
                        array_values(array_intersect($category->attachableTo(), [Lease::class, Tenant::class]))
                    ),
                    'validity_months' => $category->validityMonths(),
                ], DocumentCategory::tenantUploadable()),
                'max_size_kb' => (int) config('immopro.documents.max_size_kb'),
                'accepted_mimes' => config('immopro.documents.mimes'),
            ])
            ->header('Cache-Control', 'private, max-age=86400');
    }

    /**
     * Téléversement d'une pièce.
     *
     * Le propriétaire enregistré est le **bailleur**, jamais le déposant. Une
     * pièce rattachée au locataire échapperait à la policy du bailleur et
     * deviendrait invisible dans le dossier même où elle vient d'être versée —
     * le locataire aurait déposé son attestation d'assurance, et personne ne la
     * verrait.
     */
    public function storeDocument(TenantSpaceDocumentRequest $request, LandlordNotifier $notifier): JsonResponse
    {
        $target = $request->target();

        abort_if($target === null, 404);

        $owner = $target instanceof Tenant
            ? $target->user_id
            : $target->property()->first()?->portfolio()->first()?->user_id;

        abort_if($owner === null, 409, 'Ce dossier n\'a pas de bailleur identifiable.');

        $file = $request->file('file');
        $disk = (string) config('immopro.documents.disk');

        $path = $file->store(
            sprintf('%d/%s', $owner, Str::of($target::class)->classBasename()->lower()),
            $disk
        );

        $document = Document::create([
            'user_id' => $owner,
            'documentable_type' => $target::class,
            'documentable_id' => $target->getKey(),
            'category' => DocumentCategory::from($request->validated('category')),
            'name' => $request->validated('name')
                ?: pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME),
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'disk' => $disk,
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'issued_on' => $request->validated('issued_on'),
            'expires_on' => $request->validated('expires_on'),
            'notes' => $request->validated('notes'),
        ]);

        /*
         * Le bailleur est prévenu.
         *
         * Sans cela, une attestation d'assurance déposée dormirait dans le
         * dossier jusqu'à ce que quelqu'un pense à regarder — c'est-à-dire, en
         * pratique, jusqu'au sinistre. Le dépôt n'échoue pas si la notification
         * échoue : la pièce est enregistrée, c'est ce qui compte.
         */
        $landlord = User::find($owner);

        if ($landlord !== null) {
            $notifier->send(
                landlord: $landlord,
                type: AlertType::DepotLocataire,
                subject: 'Nouvelle pièce déposée par un locataire',
                body: sprintf(
                    '%s a déposé « %s » (%s).',
                    $request->user()->name,
                    $document->name,
                    $document->category->label(),
                ),
                about: $document,
                dedupKey: 'depot_locataire:document:'.$document->id,
            );
        }

        return (new DocumentResource($document))->response()->setStatusCode(201);
    }

    /** Téléchargement d'une pièce du dossier. */
    public function downloadDocument(Request $request, int $document): StreamedResponse
    {
        $profileIds = $this->profiles($request)->pluck('id');
        $leaseIds = $this->leaseIds($profileIds);

        $found = Document::query()
            ->whereKey($document)
            ->where(function (Builder $scope) use ($profileIds, $leaseIds): void {
                $scope->where(fn (Builder $inner) => $inner
                    ->where('documentable_type', Tenant::class)
                    ->whereIn('documentable_id', $profileIds));

                $scope->orWhere(fn (Builder $inner) => $inner
                    ->where('documentable_type', Lease::class)
                    ->whereIn('documentable_id', $leaseIds));
            })
            ->first();

        abort_if($found === null, 404, 'Cette pièce ne fait pas partie de vos dossiers.');

        $storage = Storage::disk($found->disk);

        abort_unless($storage->exists($found->path), 404, 'Le fichier n\'est plus disponible.');

        return $storage->response(
            $found->path,
            $found->original_name,
            [
                'Content-Type' => $found->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ],
            'attachment'
        );
    }

    /* ----------------------------------------------------------------------
     | Assises communes
     |----------------------------------------------------------------------*/

    /**
     * Dossiers rattachés au compte connecté.
     *
     * @return Collection<int, Tenant>
     */
    private function profiles(Request $request): Collection
    {
        return $request->user()->tenantProfiles()->get();
    }

    /**
     * Baux du locataire, titulaire principal comme colocataire.
     *
     * @param  Collection<int, int>  $profileIds
     * @return Builder<Lease>
     */
    private function leaseQuery(Collection $profileIds): Builder
    {
        return Lease::query()
            ->with(['property', 'payments'])
            ->where(fn (Builder $scope) => $scope
                ->whereIn('tenant_id', $profileIds)
                ->orWhereHas('coTenants', fn (Builder $inner) => $inner->whereIn('tenants.id', $profileIds)))
            ->orderByDesc('start_date');
    }

    /**
     * @param  Collection<int, int>  $profileIds
     * @return Collection<int, int>
     */
    private function leaseIds(Collection $profileIds): Collection
    {
        if ($profileIds->isEmpty()) {
            return collect();
        }

        return $this->leaseQuery($profileIds)->pluck('id');
    }

    /**
     * Vue d'un bail pour son locataire.
     *
     * L'adresse complète et les caractéristiques du logement y figurent ; le
     * portefeuille et les coordonnées du bailleur, non.
     *
     * @return array<string, mixed>
     */
    private function leasePayload(Lease $lease): array
    {
        $property = $lease->property;

        return [
            'id' => $lease->id,
            'type' => $lease->type->value,
            'type_label' => $lease->type->label(),
            'statut' => $lease->statut->value,
            'start_date' => $lease->start_date->toDateString(),
            'end_date' => $lease->end_date?->toDateString(),
            'duration_months' => $lease->durationInMonths(),
            'monthly_rent' => (float) $lease->monthly_rent,
            'charges' => (float) $lease->charges,
            'total_due' => (float) $lease->monthly_rent + (float) $lease->charges,
            'deposit' => $lease->deposit === null ? null : (float) $lease->deposit,
            'payment_day' => $lease->payment_day,

            'property' => $property === null ? null : [
                'id' => $property->id,
                'title' => $property->title,
                'property_type' => $property->property_type->value,
                'property_type_label' => $property->property_type->label(),
                'full_address' => $property->full_address,
                'address' => $property->address,
                'address_complement' => $property->address_complement,
                'floor' => $property->floor,
                'apartment_number' => $property->apartment_number,
                'postal_code' => $property->postal_code,
                'city' => $property->city,
                'area_sqm' => $property->area_sqm === null ? null : (float) $property->area_sqm,
                'rooms' => $property->rooms,
                'is_furnished' => $property->is_furnished,
                'has_balcony' => $property->has_balcony,
                'has_terrace' => $property->has_terrace,
                'has_garden' => $property->has_garden,
                'has_parking' => $property->has_parking,
                'has_garage' => $property->has_garage,
                'has_cave' => $property->has_cave,
                'dpe' => $property->dpe?->value,
                'ges' => $property->ges?->value,
                'dpe_date' => $property->dpe_date?->toDateString(),
                'dpe_expires_on' => $property->dpe_expires_on?->toDateString(),
            ],
        ];
    }

    /**
     * Chiffres de tête : ce qui est en retard, et ce qui vient.
     *
     * La distinction est le tout de cet écran. L'échéancier est engendré des
     * mois à l'avance : additionner toutes les lignes non réglées annonçait au
     * locataire une dette de plusieurs milliers d'euros — un an de loyers à
     * venir — là où il ne devait rien. Seules les échéances dont la date de
     * paiement est passée constituent un arriéré ; les suivantes sont des
     * rendez-vous, pas des dettes.
     *
     * C'est `RentPayment::status` qui tranche, et lui seul : il tient compte du
     * jour de paiement du bail, et le mois en cours n'est en retard qu'une fois
     * ce jour dépassé.
     *
     * @param  Collection<int, Lease>  $leases
     * @return array<string, mixed>
     */
    private function summary(Collection $leases): array
    {
        $payments = $leases->flatMap(function (Lease $lease) {
            /*
             * L'accesseur `status` lit le jour de paiement du bail. Sans la
             * relation inverse posée ici, chaque échéance le recharge : une
             * requête par ligne, soit trente-six pour un bail de trois ans.
             */
            return $lease->payments->each(
                fn (RentPayment $payment) => $payment->setRelation('lease', $lease)
            );
        });

        $overdue = $payments->filter(
            fn (RentPayment $payment) => $payment->status === RentPaymentStatus::EnRetard
        );

        $upcoming = $payments->filter(
            fn (RentPayment $payment) => $payment->status === RentPaymentStatus::EnAttente
                && $payment->period !== null
        );

        $next = $upcoming->sortBy('period')->first();

        return [
            'active_leases' => $leases->filter(fn (Lease $lease) => $lease->statut === LeaseStatus::Actif)->count(),

            'overdue_count' => $overdue->count(),
            'overdue_amount' => round((float) $overdue->sum(fn (RentPayment $payment) => (float) $payment->total), 2),

            'upcoming_count' => $upcoming->count(),

            'next_due' => $next === null ? null : [
                'lease_id' => $next->lease_id,
                'period' => $next->period?->toDateString(),
                'amount' => (float) $next->total,
                'status' => $next->status,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function emptySummary(): array
    {
        return [
            'active_leases' => 0,
            'overdue_count' => 0,
            'overdue_amount' => 0.0,
            'upcoming_count' => 0,
            'next_due' => null,
        ];
    }
}
