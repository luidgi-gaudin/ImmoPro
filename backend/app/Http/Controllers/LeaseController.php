<?php

namespace App\Http\Controllers;

use App\Enums\LeaseStatus;
use App\Http\Requests\LeaseRequest;
use App\Http\Resources\LeaseResource;
use App\Models\Lease;
use App\Services\Leases\RentScheduleGenerator;
use App\Support\Database\JsonAggregate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class LeaseController extends Controller
{
    /**
     * Liste des baux : bien, locataire, colocataires et portefeuille compris,
     * en une seule requête SQL.
     *
     * L'écran des baux affiche pour chaque ligne le nom du bien et celui du
     * locataire. Le front les retrouvait auparavant côté client, en chargeant
     * d'abord tous les portefeuilles, puis tous les locataires, puis les biens
     * de *chaque* portefeuille — un appel HTTP par portefeuille. Sur un parc de
     * cinq portefeuilles, ouvrir l'écran demandait sept allers-retours avant
     * d'afficher quoi que ce soit.
     *
     * Les jointures ramènent ces libellés avec les baux, et l'agrégat JSON les
     * colocataires. Ni `with()` ni requête supplémentaire : `with('coTenants')`
     * aurait coûté un aller-retour de plus.
     */
    public function index(Request $request)
    {
        $coTenants = JsonAggregate::arrayOf(
            [
                'id' => 'ct.id',
                'first_name' => 'ct.first_name',
                'last_name' => 'ct.last_name',
                'rent_share' => 'lt.rent_share',
            ],
            'from lease_tenant lt
               join tenants ct on ct.id = lt.tenant_id and ct.deleted_at is null
              where lt.lease_id = leases.id'
        );

        $query = Lease::query()
            ->join('properties as pr', 'pr.id', '=', 'leases.property_id')
            ->join('portfolios as po', 'po.id', '=', 'pr.portfolio_id')
            // Jointure externe : un bail dont le locataire a été supprimé doit
            // rester visible, sinon il disparaît de la gestion sans trace.
            ->leftJoin('tenants as te', function ($join) {
                $join->on('te.id', '=', 'leases.tenant_id')->whereNull('te.deleted_at');
            })
            ->where('po.user_id', auth()->id())
            ->select('leases.*')
            ->addSelect([
                'pr.title as property_title',
                'pr.address as property_address',
                'pr.city as property_city',
                'pr.portfolio_id as property_portfolio_id',
                'po.name as portfolio_name',
                'te.first_name as tenant_first_name',
                'te.last_name as tenant_last_name',
                'te.email as tenant_email',
            ])
            ->selectRaw($coTenants.' as co_tenants_json')
            ->withOwner()
            ->withCount('documents')
            ->filtered($request);

        return $this->paginate($query, $request)
            ->through(fn (Lease $lease) => new LeaseResource($lease));
    }

    /**
     * Crée le bail et pose son échéancier dans la foulée.
     *
     * Un bail sans échéancier n'est pas exploitable : il faut ensuite ouvrir
     * trente-six fois le même formulaire pour saisir des mois que le contrat
     * détermine entièrement. La génération est donc le comportement par défaut,
     * et non une action à retrouver plus tard.
     *
     * `generate_schedule: false` la désactive, pour un bail repris en cours de
     * route dont les échéances passées seront importées autrement.
     */
    public function store(LeaseRequest $request, RentScheduleGenerator $generator)
    {
        $lease = Lease::create($request->safe()->except(['co_tenants', 'generate_schedule', 'schedule_months']));

        $this->syncCoTenants($lease, $request->validated('co_tenants', []));

        if ($request->boolean('generate_schedule', true)) {
            $months = (int) $request->validated('schedule_months', RentScheduleGenerator::DEFAULT_HORIZON_MONTHS);

            $generator->generate(
                $lease,
                to: CarbonImmutable::parse($lease->start_date)->startOfMonth()->addMonths($months - 1),
            );
        }

        return new LeaseResource(
            $this->withSchedule($lease)->load('coTenants', 'tenant', 'property.portfolio')
        );
    }

    /**
     * Recharge le bail avec l'état de son échéancier.
     *
     * La création et la modification passent par `Lease::create()` / `update()`,
     * qui ne connaissent pas les sous-requêtes d'agrégat : sans cette relecture,
     * la fiche renvoyée annoncerait un échéancier vide juste après l'avoir
     * généré.
     */
    private function withSchedule(Lease $lease): Lease
    {
        $fresh = Lease::query()
            ->withOwner()
            ->withPaymentSummary()
            ->whereKey($lease->getKey())
            ->first();

        if ($fresh === null) {
            return $lease;
        }

        // C'est bien la ligne qui vient d'être créée : sans ce report, la
        // ressource perdrait le 201 que Laravel déduit de ce drapeau.
        $fresh->wasRecentlyCreated = $lease->wasRecentlyCreated;

        return $fresh;
    }

    public function show(Lease $lease)
    {
        $this->authorize('view', $lease);

        return new LeaseResource(
            $lease->load('coTenants', 'photos', 'tenant', 'property.portfolio')
        );
    }

    public function update(LeaseRequest $request, Lease $lease)
    {
        $this->authorize('update', $lease);

        $lease->update($request->safe()->except(['co_tenants', 'generate_schedule', 'schedule_months']));

        if ($request->has('co_tenants')) {
            $this->syncCoTenants($lease, $request->validated('co_tenants', []));
        }

        return new LeaseResource(
            $lease->load('coTenants', 'photos', 'tenant', 'property.portfolio')
        );
    }

    /**
     * Synchronise les colocataires et leur éventuelle répartition du loyer.
     *
     * @param  array<int, array{tenant_id: int, rent_share?: float|null}>  $coTenants
     */
    private function syncCoTenants(Lease $lease, array $coTenants): void
    {
        $syncData = collect($coTenants)->mapWithKeys(fn ($coTenant) => [
            $coTenant['tenant_id'] => ['rent_share' => $coTenant['rent_share'] ?? null],
        ])->all();

        $lease->coTenants()->sync($syncData);
    }

    /**
     * Supprime un bail — sous deux verrous.
     *
     * Un bail n'est pas une ligne de liste : c'est un contrat, avec un
     * échéancier, des quittances remises et, en cas de litige, une valeur
     * probante. Le supprimer d'un clic depuis l'en-tête de sa fiche était trop
     * facile pour ce que cela emporte.
     *
     * 1. **Un bail actif ne se supprime pas, il se résilie.** La résiliation
     *    conserve l'historique, libère le bien et donne une date de fin — ce
     *    que la suppression ne fait pas.
     * 2. **Un bail dont des loyers ont été encaissés** ne part qu'avec un
     *    acquittement explicite (`acknowledge`), que l'écran n'envoie qu'après
     *    recopie du mot de confirmation.
     *
     * La suppression reste réversible en base (soft delete) : ces verrous
     * protègent la gestion courante, pas la donnée elle-même.
     */
    public function destroy(Request $request, Lease $lease)
    {
        $this->authorize('delete', $lease);

        if ($lease->statut === LeaseStatus::Actif) {
            return response()->json([
                'message' => 'Un bail actif ne peut pas être supprimé. Résiliez-le d\'abord : '
                    .'la résiliation conserve l\'historique des loyers et libère le bien.',
                'code' => 'lease_active',
            ], 409);
        }

        if (! $request->boolean('acknowledge') && $lease->payments()->whereNotNull('paid_at')->exists()) {
            return response()->json([
                'message' => 'Ce bail porte des loyers encaissés et des quittances délivrées. '
                    .'Confirmez la suppression pour le retirer de la gestion courante.',
                'code' => 'lease_has_settled_payments',
            ], 409);
        }

        $lease->delete();

        return response()->json();
    }

    /**
     * Résilie le bail à la date donnée (congé du locataire ou du bailleur).
     * Le bien est automatiquement marqué comme libre s'il n'a plus de bail actif.
     */
    public function terminate(Request $request, Lease $lease)
    {
        $this->authorize('update', $lease);

        if ($lease->statut === LeaseStatus::Termine) {
            return response()->json(['message' => 'Ce bail est déjà résilié.'], 409);
        }

        $data = $request->validate([
            'end_date' => ['required', 'date', 'after_or_equal:'.$lease->start_date->toDateString()],
        ]);

        $lease->update([
            'statut' => LeaseStatus::Termine,
            'end_date' => $data['end_date'],
        ]);

        // L'échéancier avait été posé jusqu'à l'échéance contractuelle. Un congé
        // anticipé rend caduques les échéances postérieures : les laisser en
        // place ferait apparaître le locataire en impayé sur des mois qu'il
        // n'occupe plus. Seules les échéances non réglées partent — un loyer
        // encaissé a une quittance, il reste.
        $dropped = $lease->payments()
            ->whereNull('paid_at')
            ->where('period', '>', Carbon::parse($data['end_date'])->endOfMonth()->toDateString())
            ->delete();

        return response()->json([
            'message' => $dropped === 0
                ? 'Bail résilié.'
                : ($dropped === 1
                    ? 'Bail résilié. 1 échéance postérieure a été retirée de l\'échéancier.'
                    : "Bail résilié. {$dropped} échéances postérieures ont été retirées de l'échéancier."),
            'dropped_payments' => $dropped,
            // Même enveloppe que la révision de loyer : une action qui a des
            // effets de bord annonce ce qu'elle a fait, et rend le bail à jour
            // sous « data ». La ressource nue est réservée au CRUD.
            'data' => new LeaseResource($this->withSchedule($lease->refresh())),
        ]);
    }

    /**
     * Révision annuelle du loyer indexée sur l'IRL (art. 17-1, loi n° 89-462) :
     * nouveau loyer = loyer × (nouvel indice / ancien indice), une fois par an au plus.
     */
    public function reviseRent(Request $request, Lease $lease)
    {
        $this->authorize('update', $lease);

        if (! $lease->isActive()) {
            return response()->json([
                'message' => 'Seul un bail actif peut faire l\'objet d\'une révision de loyer.',
            ], 409);
        }

        if (! $lease->canReviseRent()) {
            return response()->json([
                'message' => 'La révision du loyer ne peut intervenir qu\'une fois par an (art. 17-1, loi n° 89-462).',
            ], 409);
        }

        $data = $request->validate([
            'irl_old' => ['required', 'numeric', 'gt:0'],
            'irl_new' => ['required', 'numeric', 'gt:0'],
        ]);

        $oldRent = (float) $lease->monthly_rent;
        $newRent = round($oldRent * (float) $data['irl_new'] / (float) $data['irl_old'], 2);

        $lease->forceFill([
            'monthly_rent' => $newRent,
            'last_rent_revision_at' => now()->toDateString(),
        ])->save();

        // L'échéancier est posé d'avance : sans report, la révision n'aurait
        // aucun effet visible avant le mois où les échéances déjà créées
        // s'épuisent, et le bailleur appellerait l'ancien loyer pendant des
        // mois. Les échéances déjà réglées ne bougent pas : leur montant est
        // celui qui figure sur la quittance remise.
        $repriced = $lease->payments()
            ->whereNull('paid_at')
            ->where('period', '>=', now()->startOfMonth()->toDateString())
            ->update(['amount_rent' => $newRent, 'updated_at' => now()]);

        return response()->json([
            'message' => 'Loyer révisé selon la variation de l\'IRL.',
            'old_rent' => $oldRent,
            'new_rent' => $newRent,
            'repriced_payments' => $repriced,
            'data' => new LeaseResource($this->withSchedule($lease->refresh())),
        ]);
    }
}
