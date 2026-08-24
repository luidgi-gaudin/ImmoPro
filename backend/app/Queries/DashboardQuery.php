<?php

namespace App\Queries;

use App\Enums\LeaseStatus;
use App\Models\User;
use App\Support\Database\JsonAggregate;
use App\Support\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Tout le tableau de bord en une seule requête SQL.
 *
 * L'écran a besoin de sept compteurs, de trois listes « récents » et d'un
 * aperçu des alertes. Écrit en Eloquent, cela fait sept requêtes — sept
 * allers-retours vers Supabase, soit un peu plus d'une seconde d'attente pour
 * une page qui n'affiche qu'une poignée de chiffres.
 *
 * Or aucune de ces sept requêtes ne dépend du résultat d'une autre. PostgreSQL
 * sait toutes les évaluer dans le même ordre du serveur : des sous-requêtes
 * scalaires pour les compteurs, des agrégats JSON pour les listes. Le coût
 * réseau passe de sept allers-retours à un, et le travail demandé au serveur
 * est identique.
 *
 * L'identifiant du bailleur est inséré littéralement dans le SQL, et c'est
 * volontaire : c'est un entier issu de l'authentification, jamais une saisie.
 * Le passer en paramètre lié obligerait à répéter une douzaine de marqueurs
 * positionnels dans le bon ordre — une source d'erreur bien plus probable que
 * l'injection qu'on prétendrait éviter. Le transtypage `(int)` ferme la
 * question.
 */
class DashboardQuery
{
    /** Nombre d'éléments affichés dans chaque encart « récents ». */
    private const RECENT = 3;

    /** Nombre d'alertes affichées dans l'aperçu. */
    private const ALERTS = 4;

    public function __construct(private readonly User $user) {}

    /** @return array<string, mixed> */
    public function get(): array
    {
        Rls::ensureBound();

        $row = DB::selectOne('select '.implode(",\n       ", [
            $this->counts(),
            $this->recentPortfolios(),
            $this->recentTenants(),
            $this->recentLeases(),
            $this->alertsPreview(),
        ]));

        return [
            'counts' => [
                'portfolios' => (int) $row->portfolios_count,
                'properties' => (int) $row->properties_count,
                'occupied_properties' => (int) $row->occupied_properties_count,
                'vacant_properties' => (int) $row->properties_count - (int) $row->occupied_properties_count,
                'tenants' => (int) $row->tenants_count,
                'leases' => (int) $row->leases_count,
                'active_leases' => (int) $row->active_leases_count,
                'monthly_rent_expected' => round((float) $row->monthly_rent_expected, 2),
                'documents' => (int) $row->documents_count,
            ],

            'recent' => [
                'portfolios' => $this->decode($row->recent_portfolios),
                'tenants' => $this->decode($row->recent_tenants),
                'leases' => $this->decode($row->recent_leases),
            ],

            'alerts' => [
                'items' => $this->decode($row->recent_alerts),
                'unread_count' => (int) $row->unread_alerts_count,
            ],
        ];
    }

    /**
     * Portée du bailleur, réutilisée par presque toutes les sous-requêtes.
     * Écrite une fois pour que « les biens de ce bailleur » veuille dire la
     * même chose partout.
     */
    private function estate(): string
    {
        $id = (int) $this->user->id;

        return "from properties p
                  join portfolios po on po.id = p.portfolio_id
                 where po.user_id = {$id}";
    }

    private function leaseEstate(string $extra = ''): string
    {
        $id = (int) $this->user->id;

        return "from leases l
                  join properties p on p.id = l.property_id
                  join portfolios po on po.id = p.portfolio_id
                 where po.user_id = {$id}
                   and l.deleted_at is null
                   {$extra}";
    }

    private function counts(): string
    {
        $id = (int) $this->user->id;
        $actif = LeaseStatus::Actif->value;

        return implode(",\n       ", [
            "(select count(*) from portfolios where user_id = {$id}) as portfolios_count",
            "(select count(*) {$this->estate()}) as properties_count",
            "(select coalesce(sum(case when p.is_rented then 1 else 0 end), 0) {$this->estate()}) as occupied_properties_count",
            "(select count(*) from tenants where user_id = {$id} and deleted_at is null) as tenants_count",
            "(select count(*) {$this->leaseEstate()}) as leases_count",
            "(select count(*) {$this->leaseEstate("and l.statut = '{$actif}'")}) as active_leases_count",
            "(select coalesce(sum(l.monthly_rent), 0) {$this->leaseEstate("and l.statut = '{$actif}'")}) as monthly_rent_expected",
            "(select count(*) from documents where user_id = {$id} and deleted_at is null) as documents_count",
            "(select count(*) from alerts where user_id = {$id} and resolved_at is null and read_at is null) as unread_alerts_count",
        ]);
    }

    private function recentPortfolios(): string
    {
        $id = (int) $this->user->id;

        return JsonAggregate::arrayOf(
            [
                'id' => 'r.id',
                'name' => 'r.name',
                'description' => 'r.description',
                'properties_count' => 'r.properties_count',
            ],
            "from (select pf.id, pf.name, pf.description,
                          (select count(*) from properties px where px.portfolio_id = pf.id) as properties_count
                     from portfolios pf
                    where pf.user_id = {$id}
                    order by pf.created_at desc, pf.id desc
                    limit ".self::RECENT.') r'
        ).' as recent_portfolios';
    }

    private function recentTenants(): string
    {
        $id = (int) $this->user->id;

        return JsonAggregate::arrayOf(
            [
                'id' => 'r.id',
                'first_name' => 'r.first_name',
                'last_name' => 'r.last_name',
                'email' => 'r.email',
                'phone' => 'r.phone',
            ],
            "from (select t.id, t.first_name, t.last_name, t.email, t.phone
                     from tenants t
                    where t.user_id = {$id} and t.deleted_at is null
                    order by t.created_at desc, t.id desc
                    limit ".self::RECENT.') r'
        ).' as recent_tenants';
    }

    /**
     * Les baux récents portent le nom du bien et du locataire.
     *
     * L'encart affichait « Bail #12 » : un identifiant technique ne dit rien à
     * un gestionnaire. Les jointures étant déjà là pour la portée, les libellés
     * ne coûtent rien de plus.
     */
    private function recentLeases(): string
    {
        $id = (int) $this->user->id;

        return JsonAggregate::arrayOf(
            [
                'id' => 'r.id',
                'property_id' => 'r.property_id',
                'tenant_id' => 'r.tenant_id',
                'property_title' => 'r.property_title',
                'tenant_name' => 'r.tenant_name',
                'start_date' => 'r.start_date',
                'end_date' => 'r.end_date',
                'monthly_rent' => 'r.monthly_rent',
                'deposit' => 'r.deposit',
                'statut' => 'r.statut',
            ],
            "from (select l.id, l.property_id, l.tenant_id, l.start_date, l.end_date,
                          l.monthly_rent, l.deposit, l.statut,
                          p.title as property_title,
                          nullif(trim(coalesce(te.first_name, '') || ' ' || coalesce(te.last_name, '')), '') as tenant_name
                     from leases l
                     join properties p on p.id = l.property_id
                     join portfolios po on po.id = p.portfolio_id
                     left join tenants te on te.id = l.tenant_id and te.deleted_at is null
                    where po.user_id = {$id}
                      and l.deleted_at is null
                    order by l.start_date desc, l.id desc
                    limit ".self::RECENT.') r'
        ).' as recent_leases';
    }

    private function alertsPreview(): string
    {
        $id = (int) $this->user->id;

        return JsonAggregate::arrayOf(
            [
                'id' => 'r.id',
                'type' => 'r.type',
                'severity' => 'r.severity',
                'title' => 'r.title',
                'message' => 'r.message',
                'due_date' => 'r.due_date',
                'meta' => 'r.meta',
                'subject_type' => 'r.alertable_type',
                'subject_id' => 'r.alertable_id',
                'is_read' => 'r.is_read',
                'is_resolved' => 'r.is_resolved',
                'reminded_at' => 'r.reminded_at',
                'created_at' => 'r.created_at',
            ],
            "from (select a.id, a.type, a.severity, a.title, a.message, a.due_date,
                          a.alertable_type, a.alertable_id, a.reminded_at, a.created_at,
                          -- `meta` est du JSON dans une colonne. Sans ce cast en
                          -- texte, PostgreSQL l'imbriquerait comme objet et SQLite
                          -- comme chaîne : deux formes pour le même champ. On le
                          -- ramène à du texte des deux côtés, décodé en PHP.
                          cast(a.meta as text) as meta,
                          case when a.read_at is null then 0 else 1 end as is_read,
                          case when a.resolved_at is null then 0 else 1 end as is_resolved
                     from alerts a
                    where a.user_id = {$id} and a.resolved_at is null
                    order by case a.severity when 'critical' then 0 when 'warning' then 1 else 2 end,
                             a.due_date, a.id desc
                    limit ".self::ALERTS.') r'
        ).' as recent_alerts';
    }

    /**
     * Les agrégats JSON reviennent sous forme de chaîne, quel que soit le
     * pilote. `subject_type` porte le nom de classe complet en base ; l'API
     * n'en expose que le nom court, comme AlertResource.
     *
     * @return list<array<string, mixed>>
     */
    private function decode(?string $json): array
    {
        $rows = json_decode($json ?? '[]', true) ?: [];

        return array_map(function (array $row): array {
            if (array_key_exists('subject_type', $row)) {
                $row['subject_type'] = $row['subject_type'] ? class_basename($row['subject_type']) : null;
                $row['is_read'] = (bool) $row['is_read'];
                $row['is_resolved'] = (bool) $row['is_resolved'];
                $row['meta'] = is_string($row['meta'] ?? null) ? json_decode($row['meta'], true) : null;
            }

            return $row;
        }, $rows);
    }
}
