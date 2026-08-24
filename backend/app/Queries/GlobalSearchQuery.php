<?php

namespace App\Queries;

use App\Models\User;
use App\Support\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Recherche globale sur tout le patrimoine, en une seule requête SQL.
 *
 * Cinq entités étaient interrogées par cinq requêtes distinctes : cinq
 * allers-retours pour une barre de recherche qui interroge l'API à chaque
 * frappe. Une union les évalue en un seul passage.
 *
 * Contrairement au tableau de bord et aux rapports, le terme recherché vient
 * de l'utilisateur : il est **toujours** passé en paramètre lié, jamais
 * concaténé. Les jokers SQL sont par ailleurs neutralisés en amont, faute de
 * quoi une recherche sur « % » balaierait toutes les tables.
 */
class GlobalSearchQuery
{
    /** Nombre de résultats par catégorie. */
    private const PER_KIND = 5;

    public function __construct(private readonly User $user) {}

    /** @return array<string, mixed> */
    public function search(string $term): array
    {
        $empty = [
            'portfolios' => [],
            'properties' => [],
            'tenants' => [],
            'leases' => [],
            'alerts' => [],
        ];

        // Un terme d'une seule lettre ramènerait presque tout : la requête
        // serait lente et le résultat inutile.
        if (mb_strlen($term) < 2) {
            return ['query' => $term, 'total' => 0, 'results' => $empty];
        }

        Rls::ensureBound();

        // addcslashes neutralise les jokers : chercher « 100% » ou « bail_2026 »
        // cherche bien ces caractères, au lieu de les interpréter comme motif.
        $pattern = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';

        [$sql, $bindings] = $this->union($pattern);

        $rows = DB::select($sql, $bindings);

        // Association explicite : « property » ne se met pas au pluriel en
        // ajoutant un « s », et une catégorie mal nommée disparaît en silence
        // de la réponse au lieu de lever une erreur.
        $groups = [
            'portfolio' => 'portfolios',
            'property' => 'properties',
            'tenant' => 'tenants',
            'lease' => 'leases',
            'alert' => 'alerts',
        ];

        $results = $empty;

        foreach ($rows as $row) {
            $results[$groups[$row->kind]][] = $this->present($row);
        }

        return [
            'query' => $term,
            'total' => count($rows),
            'results' => $results,
        ];
    }

    /**
     * @return array{0: string,1: list<string>}
     */
    private function union(string $pattern): array
    {
        $branches = [
            $this->portfolios(),
            $this->properties(),
            $this->tenants(),
            $this->leases(),
            $this->alerts(),
        ];

        $sql = [];
        $bindings = [];

        foreach ($branches as [$branchSql, $count]) {
            $sql[] = "select * from ({$branchSql}) as _".count($sql);
            // Chaque motif est répété autant de fois que la branche compte de
            // colonnes balayées, dans l'ordre où elles apparaissent.
            $bindings = array_merge($bindings, array_fill(0, $count, $pattern));
        }

        return [implode("\n union all\n", $sql), $bindings];
    }

    /**
     * Comparaison insensible à la casse et portable : PostgreSQL rend LIKE
     * sensible à la casse, SQLite l'inverse, mais `lower()` existe des deux
     * côtés. L'échappement explicite est indispensable en SQLite, qui n'en
     * reconnaît aucun par défaut.
     *
     * @param  list<string>  $columns
     */
    private function matches(array $columns): string
    {
        return '('.implode(' or ', array_map(
            fn (string $column) => "lower({$column}) like ? escape '\\'",
            $columns
        )).')';
    }

    /** @return array{0: string, 1: int} */
    private function portfolios(): array
    {
        $id = (int) $this->user->id;
        $columns = ['pf.name', 'pf.description'];

        return [
            "select 'portfolio' as kind, pf.id,
                    pf.name as title,
                    coalesce(nullif(pf.description, ''), 'Ensemble immobilier') as subtitle,
                    cast((select count(*) from properties px where px.portfolio_id = pf.id) as text) || ' actif(s)' as badge,
                    'neutral' as badge_tone,
                    cast(null as bigint) as parent_id
               from portfolios pf
              where pf.user_id = {$id} and ".$this->matches($columns).'
              order by pf.name
              limit '.self::PER_KIND,
            count($columns),
        ];
    }

    /** @return array{0: string, 1: int} */
    private function properties(): array
    {
        $id = (int) $this->user->id;
        $columns = ['p.title', 'p.address', 'p.city', 'p.postal_code'];

        return [
            "select 'property' as kind, p.id,
                    p.title as title,
                    trim(coalesce(p.address, '') || ', ' || coalesce(p.city, '') || ' (' || coalesce(p.postal_code, '') || ')') as subtitle,
                    case when p.is_rented then 'Loué' else 'Disponible' end as badge,
                    case when p.is_rented then 'info' else 'success' end as badge_tone,
                    p.portfolio_id as parent_id
               from properties p
               join portfolios po on po.id = p.portfolio_id
              where po.user_id = {$id} and ".$this->matches($columns).'
              order by p.title
              limit '.self::PER_KIND,
            count($columns),
        ];
    }

    /** @return array{0: string, 1: int} */
    private function tenants(): array
    {
        $id = (int) $this->user->id;
        $columns = ['t.first_name', 't.last_name', 't.email', 't.phone'];

        return [
            "select 'tenant' as kind, t.id,
                    trim(coalesce(t.first_name, '') || ' ' || coalesce(t.last_name, '')) as title,
                    coalesce(nullif(t.email, ''), nullif(t.phone, ''), 'Dossier locataire') as subtitle,
                    'Locataire' as badge,
                    'neutral' as badge_tone,
                    cast(null as bigint) as parent_id
               from tenants t
              where t.user_id = {$id} and t.deleted_at is null and ".$this->matches($columns).'
              order by t.last_name, t.first_name
              limit '.self::PER_KIND,
            count($columns),
        ];
    }

    /** @return array{0: string, 1: int} */
    private function leases(): array
    {
        $id = (int) $this->user->id;
        $columns = ['te.first_name', 'te.last_name', 'p.title', 'p.city'];

        return [
            "select 'lease' as kind, l.id,
                    'Bail — ' || coalesce(p.title, 'bien') as title,
                    trim(coalesce(trim(coalesce(te.first_name, '') || ' ' || coalesce(te.last_name, '')) || ' • ', '')
                         || cast(l.monthly_rent as text) || ' €/mois') as subtitle,
                    l.statut as badge,
                    case when l.statut = 'actif' then 'success' else 'neutral' end as badge_tone,
                    cast(null as bigint) as parent_id
               from leases l
               join properties p on p.id = l.property_id
               join portfolios po on po.id = p.portfolio_id
               left join tenants te on te.id = l.tenant_id and te.deleted_at is null
              where po.user_id = {$id} and l.deleted_at is null and ".$this->matches($columns).'
              order by l.start_date desc
              limit '.self::PER_KIND,
            count($columns),
        ];
    }

    /** @return array{0: string, 1: int} */
    private function alerts(): array
    {
        $id = (int) $this->user->id;
        $columns = ['a.title', 'a.message'];

        return [
            "select 'alert' as kind, a.id,
                    a.title as title,
                    a.message as subtitle,
                    a.severity as badge,
                    case when a.severity = 'critical' then 'danger' else 'warning' end as badge_tone,
                    cast(null as bigint) as parent_id
               from alerts a
              where a.user_id = {$id} and ".$this->matches($columns)."
              order by case a.severity when 'critical' then 0 when 'warning' then 1 else 2 end, a.id desc
              limit ".self::PER_KIND,
            count($columns),
        ];
    }

    /**
     * L'URL de destination est construite ici, et non côté client : c'est le
     * serveur qui sait qu'un bien vit sous son portefeuille.
     *
     * @return array<string, mixed>
     */
    private function present(object $row): array
    {
        $url = match ($row->kind) {
            'portfolio' => '/portfolios/'.$row->id.'/properties',
            'property' => '/portfolios/'.$row->parent_id.'/properties/'.$row->id,
            'tenant' => '/tenants/'.$row->id,
            // Le bail est désigné par son identifiant, pas par une recherche
            // textuelle : l'écran ouvre directement le bon dossier.
            'lease' => '/leases?lease='.$row->id,
            default => '/alerts?alert='.$row->id,
        };

        return [
            'id' => (int) $row->id,
            'type' => $row->kind,
            'title' => $row->title,
            'subtitle' => $row->subtitle,
            'badge' => $row->badge,
            'badge_tone' => $row->badge_tone,
            'portfolio_id' => $row->parent_id === null ? null : (int) $row->parent_id,
            'url' => $url,
        ];
    }
}
