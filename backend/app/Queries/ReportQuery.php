<?php

namespace App\Queries;

use App\Enums\LeaseStatus;
use App\Enums\RentPaymentStatus;
use App\Models\User;
use App\Support\Database\JsonAggregate;
use App\Support\Rls;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rapport du bailleur en une seule requête SQL.
 *
 * La version précédente chargeait en mémoire l'intégralité du patrimoine —
 * portefeuilles, biens, baux, échéances, locataires — pour en tirer une
 * vingtaine de chiffres : cinq allers-retours, et une empreinte mémoire qui
 * croît avec le parc. Ici, PostgreSQL agrège ce qu'il a déjà sous la main et ne
 * renvoie que le résultat.
 *
 * Le point délicat est le statut d'une échéance, qui n'existe pas en base : il
 * se déduit de `paid_at` et du jour d'échéance du bail. Il est reproduit en SQL
 * à l'identique de RentPaymentStatus, avec les mêmes bornes :
 *
 *   - payée dès que `paid_at` est renseigné ;
 *   - sinon en retard si le mois de la période est révolu ;
 *   - sinon, pour le mois en cours, en retard si le jour d'échéance est passé —
 *     un jour de paiement au 31 tombant au dernier jour des mois plus courts ;
 *   - sinon en attente.
 *
 * Les repères temporels (premier jour du mois, jour d'aujourd'hui, longueur du
 * mois) sont calculés en PHP et insérés comme littéraux : `date_trunc` et
 * `strftime` n'ont ni la même syntaxe ni le même comportement selon le moteur,
 * et les tests tournent sur SQLite.
 */
class ReportQuery
{
    private CarbonImmutable $today;

    public function __construct(private readonly User $user)
    {
        $this->today = CarbonImmutable::now()->startOfDay();
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        Rls::ensureBound();

        $row = DB::selectOne('select '.implode(",\n       ", [
            $this->estateCounts(),
            $this->currentMonthTotals(),
            $this->perProperty(),
            $this->perTenant(),
        ]));

        return [
            'bailleur' => [
                'total_portfolios' => (int) $row->total_portfolios,
                'total_properties' => (int) $row->total_properties,
                'occupied_properties' => (int) $row->occupied_properties,
                'vacant_properties' => (int) $row->total_properties - (int) $row->occupied_properties,
                'total_tenants' => (int) $row->total_tenants,
                'total_leases' => (int) $row->total_leases,
                'active_leases' => (int) $row->active_leases,
                'monthly_rent_expected' => round((float) $row->monthly_rent_expected, 2),
                'this_month' => [
                    'paid_amount' => round((float) $row->paid_amount, 2),
                    'paid_count' => (int) $row->paid_count,
                    'late_amount' => round((float) $row->late_amount, 2),
                    'late_count' => (int) $row->late_count,
                    'pending_amount' => round((float) $row->pending_amount, 2),
                    'pending_count' => (int) $row->pending_count,
                ],
            ],

            'par_bien' => $this->decode($row->par_bien),
            'par_locataire' => $this->decode($row->par_locataire),
        ];
    }

    /**
     * Statut d'une échéance, reproduit en SQL.
     *
     * `$payment` et `$lease` sont les alias des tables dans la requête
     * appelante. Le `case` imbriqué remplace `least()`, qui n'existe pas en
     * SQLite.
     */
    private function statusExpression(string $payment, string $lease): string
    {
        $firstOfMonth = $this->today->startOfMonth()->toDateString();
        $firstOfNextMonth = $this->today->startOfMonth()->addMonth()->toDateString();
        $daysInMonth = $this->today->daysInMonth;
        $dayToday = $this->today->day;

        $paye = RentPaymentStatus::Paye->value;
        $retard = RentPaymentStatus::EnRetard->value;
        $attente = RentPaymentStatus::EnAttente->value;

        return "case
                    when {$payment}.paid_at is not null then '{$paye}'
                    when {$payment}.period < '{$firstOfMonth}' then '{$retard}'
                    when {$payment}.period >= '{$firstOfNextMonth}' then '{$attente}'
                    when {$dayToday} > (case when coalesce({$lease}.payment_day, 1) > {$daysInMonth}
                                             then {$daysInMonth}
                                             else coalesce({$lease}.payment_day, 1) end)
                        then '{$retard}'
                    else '{$attente}'
                end";
    }

    private function estateCounts(): string
    {
        $id = (int) $this->user->id;
        $actif = LeaseStatus::Actif->value;

        $estate = "from properties p
                     join portfolios po on po.id = p.portfolio_id
                    where po.user_id = {$id}";

        $leases = fn (string $extra = '') => "from leases l
                     join properties p on p.id = l.property_id
                     join portfolios po on po.id = p.portfolio_id
                    where po.user_id = {$id} and l.deleted_at is null {$extra}";

        return implode(",\n       ", [
            "(select count(*) from portfolios where user_id = {$id}) as total_portfolios",
            "(select count(*) {$estate}) as total_properties",
            "(select coalesce(sum(case when p.is_rented then 1 else 0 end), 0) {$estate}) as occupied_properties",
            "(select count(*) from tenants where user_id = {$id} and deleted_at is null) as total_tenants",
            '(select count(*) '.$leases().') as total_leases',
            '(select count(*) '.$leases("and l.statut = '{$actif}'").') as active_leases',
            '(select coalesce(sum(l.monthly_rent), 0) '.$leases("and l.statut = '{$actif}'").') as monthly_rent_expected',
        ]);
    }

    /**
     * Encaissé, en retard et attendu sur le mois en cours.
     *
     * Six sous-requêtes plutôt qu'une seule à six colonnes : elles s'exécutent
     * dans le même aller-retour, et chacune se lit indépendamment. Regrouper
     * n'aurait rien gagné et aurait rendu l'ensemble opaque.
     */
    private function currentMonthTotals(): string
    {
        $id = (int) $this->user->id;
        $firstOfMonth = $this->today->startOfMonth()->toDateString();
        $firstOfNextMonth = $this->today->startOfMonth()->addMonth()->toDateString();
        $status = $this->statusExpression('rp', 'l');

        $scope = "from rent_payments rp
                    join leases l on l.id = rp.lease_id and l.deleted_at is null
                    join properties p on p.id = l.property_id
                    join portfolios po on po.id = p.portfolio_id
                   where po.user_id = {$id}
                     and rp.deleted_at is null
                     and rp.period >= '{$firstOfMonth}'
                     and rp.period < '{$firstOfNextMonth}'";

        $columns = [];

        foreach ([
            'paid' => RentPaymentStatus::Paye->value,
            'late' => RentPaymentStatus::EnRetard->value,
            'pending' => RentPaymentStatus::EnAttente->value,
        ] as $label => $value) {
            $columns[] = "(select coalesce(sum(case when {$status} = '{$value}'
                                                    then rp.amount_rent + rp.amount_charges
                                                    else 0 end), 0) {$scope}) as {$label}_amount";
            $columns[] = "(select count(*) {$scope} and {$status} = '{$value}') as {$label}_count";
        }

        return implode(",\n       ", $columns);
    }

    /** Ventilation par bien : occupation, loyer effectif et locataire en place. */
    private function perProperty(): string
    {
        $id = (int) $this->user->id;
        $actif = LeaseStatus::Actif->value;

        return JsonAggregate::arrayOf(
            [
                'id' => 'r.id',
                'title' => 'r.title',
                'city' => 'r.city',
                'portfolio_id' => 'r.portfolio_id',
                'portfolio_name' => 'r.portfolio_name',
                'is_rented' => 'r.is_rented',
                'monthly_rent' => 'r.monthly_rent',
                'tenant_id' => 'r.tenant_id',
                'tenant_name' => 'r.tenant_name',
                'lease_id' => 'r.lease_id',
            ],
            "from (select p.id, p.title, p.city, p.portfolio_id,
                          po.name as portfolio_name,
                          case when p.is_rented then 1 else 0 end as is_rented,
                          coalesce(l.monthly_rent, p.monthly_rent, 0) as monthly_rent,
                          l.id as lease_id,
                          l.tenant_id,
                          -- nullif : sans locataire, la concaténation donne une
                          -- chaîne vide, que le front afficherait comme un nom
                          -- blanc au lieu d'un tiret « aucun locataire ».
                          nullif(trim(coalesce(te.first_name, '') || ' ' || coalesce(te.last_name, '')), '') as tenant_name
                     from properties p
                     join portfolios po on po.id = p.portfolio_id
                     -- Bail actif du bien, s'il y en a un. Sous-requête plutôt
                     -- que jointure directe : un bien peut porter plusieurs baux
                     -- dans son historique, un seul est actif.
                     left join leases l
                            on l.id = (select l2.id from leases l2
                                        where l2.property_id = p.id
                                          and l2.statut = '{$actif}'
                                          and l2.deleted_at is null
                                        order by l2.start_date desc, l2.id desc
                                        limit 1)
                     left join tenants te on te.id = l.tenant_id and te.deleted_at is null
                    where po.user_id = {$id}
                    order by po.name, p.title) r"
        ).' as par_bien';
    }

    /** Ventilation par locataire : encaissé, restant dû, retards. */
    private function perTenant(): string
    {
        $id = (int) $this->user->id;
        $actif = LeaseStatus::Actif->value;
        $status = $this->statusExpression('rp', 'l');
        $paye = RentPaymentStatus::Paye->value;
        $retard = RentPaymentStatus::EnRetard->value;

        $payments = 'from rent_payments rp
                       join leases l on l.id = rp.lease_id and l.deleted_at is null
                      where l.tenant_id = t.id and rp.deleted_at is null';

        return JsonAggregate::arrayOf(
            [
                'id' => 'r.id',
                'name' => 'r.name',
                'total_paid' => 'r.total_paid',
                'total_due' => 'r.total_due',
                'late_count' => 'r.late_count',
                'active_lease_id' => 'r.active_lease_id',
            ],
            "from (select t.id,
                          trim(coalesce(t.first_name, '') || ' ' || coalesce(t.last_name, '')) as name,
                          (select coalesce(sum(case when {$status} = '{$paye}'
                                                    then rp.amount_rent + rp.amount_charges else 0 end), 0)
                             {$payments}) as total_paid,
                          (select coalesce(sum(case when {$status} <> '{$paye}'
                                                    then rp.amount_rent + rp.amount_charges else 0 end), 0)
                             {$payments}) as total_due,
                          (select count(*) {$payments} and {$status} = '{$retard}') as late_count,
                          (select l3.id from leases l3
                            where l3.tenant_id = t.id
                              and l3.statut = '{$actif}'
                              and l3.deleted_at is null
                            order by l3.start_date desc, l3.id desc
                            limit 1) as active_lease_id
                     from tenants t
                    where t.user_id = {$id} and t.deleted_at is null
                    order by t.last_name, t.first_name) r"
        ).' as par_locataire';
    }

    /** @return list<array<string, mixed>> */
    private function decode(?string $json): array
    {
        $rows = json_decode($json ?? '[]', true) ?: [];

        return array_map(function (array $row): array {
            if (array_key_exists('is_rented', $row)) {
                $row['is_rented'] = (bool) $row['is_rented'];
            }
            foreach (['monthly_rent', 'total_paid', 'total_due'] as $money) {
                if (array_key_exists($money, $row)) {
                    $row[$money] = round((float) $row[$money], 2);
                }
            }

            return $row;
        }, $rows);
    }
}
