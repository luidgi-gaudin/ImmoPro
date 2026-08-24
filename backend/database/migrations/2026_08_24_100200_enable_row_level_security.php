<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row Level Security sur les tables métier.
 *
 * L'isolation entre bailleurs ne repose plus seulement sur le fait que chaque
 * contrôleur pense à filtrer : PostgreSQL refuse lui-même les lignes qui ne
 * sont pas celles du bailleur connecté. Un `Lease::all()` écrit par
 * distraction, une injection SQL, un point d'entrée oublié — aucun ne peut plus
 * traverser les données d'autrui.
 *
 * Trois conditions, sans lesquelles tout ceci ne serait qu'un décor :
 *
 *   1. Le rôle de connexion ne doit ni posséder les tables ni porter BYPASSRLS.
 *      Le rôle `postgres` de Supabase a les deux — d'où le rôle applicatif
 *      dédié créé par `php artisan immopro:rls-provision`, et FORCE ROW LEVEL
 *      SECURITY posé ci-dessous pour le cas où il deviendrait propriétaire.
 *   2. `app.user_id` doit être posé avant la première lecture. C'est le rôle de
 *      App\Support\Rls, qui le fait voyager avec la requête d'authentification
 *      plutôt que dans un aller-retour dédié.
 *   3. L'absence d'identité doit refuser, pas ouvrir. `app_user_id()` renvoie
 *      NULL quand rien n'est posé ; toutes les comparaisons valent alors NULL,
 *      et NULL n'est pas vrai. L'échec est fermé.
 *
 * Deux tables restent volontairement hors RLS : `users` et
 * `personal_access_tokens`. Ce sont celles du chemin d'authentification, qui
 * s'exécute par construction avant qu'une identité existe — les protéger
 * reviendrait à rendre la connexion impossible. Leurs données sensibles sont
 * couvertes autrement : mots de passe hachés, IBAN, SIRET et secret TOTP
 * chiffrés au repos, jetons stockés sous forme de condensat.
 */
return new class extends Migration
{
    /**
     * Condition de possession, par table. Écrites en SQL plutôt que dérivées
     * d'une convention : une policy est une règle de sécurité, elle doit se
     * lire telle qu'elle s'applique.
     *
     * @return array<string, string>
     */
    private function policies(): array
    {
        $owner = 'public.app_user_id()';

        return [
            'portfolios' => "user_id = {$owner}",
            'tenants' => "user_id = {$owner}",
            'alerts' => "user_id = {$owner}",
            'documents' => "user_id = {$owner}",

            'properties' => "exists (
                select 1 from public.portfolios po
                 where po.id = properties.portfolio_id
                   and po.user_id = {$owner})",

            'leases' => "exists (
                select 1 from public.properties pr
                  join public.portfolios po on po.id = pr.portfolio_id
                 where pr.id = leases.property_id
                   and po.user_id = {$owner})",

            'rent_payments' => "exists (
                select 1 from public.leases l
                  join public.properties pr on pr.id = l.property_id
                  join public.portfolios po on po.id = pr.portfolio_id
                 where l.id = rent_payments.lease_id
                   and po.user_id = {$owner})",

            'lease_photos' => "exists (
                select 1 from public.leases l
                  join public.properties pr on pr.id = l.property_id
                  join public.portfolios po on po.id = pr.portfolio_id
                 where l.id = lease_photos.lease_id
                   and po.user_id = {$owner})",

            'lease_tenant' => "exists (
                select 1 from public.leases l
                  join public.properties pr on pr.id = l.property_id
                  join public.portfolios po on po.id = pr.portfolio_id
                 where l.id = lease_tenant.lease_id
                   and po.user_id = {$owner})",
        ];
    }

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            // Les tests tournent sur SQLite, qui n'a pas de RLS. L'isolation y
            // est couverte par les tests de contrôleur (403 pour un tiers).
            return;
        }

        $setting = config('immopro.rls.setting', 'app.user_id');

        // STABLE et non VOLATILE : la valeur ne change pas pendant la requête,
        // le planificateur peut donc l'évaluer une seule fois au lieu d'une
        // fois par ligne. Sur une table de mille lignes, la différence est
        // celle entre un index et un balayage.
        DB::unprepared(sprintf(
            'create or replace function public.app_user_id() returns bigint
                 language sql
                 stable
                 as $$ select nullif(current_setting(%s, true), %s)::bigint $$;',
            DB::getPdo()->quote($setting),
            DB::getPdo()->quote('')
        ));

        foreach ($this->policies() as $table => $condition) {
            DB::unprepared("alter table public.{$table} enable row level security;");
            DB::unprepared("alter table public.{$table} force row level security;");

            // Rejouable : une migration relancée après un ajustement de policy
            // ne doit pas échouer sur un doublon.
            DB::unprepared("drop policy if exists immopro_owner on public.{$table};");
            DB::unprepared(
                "create policy immopro_owner on public.{$table}
                     using ({$condition})
                     with check ({$condition});"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_keys($this->policies()) as $table) {
            DB::unprepared("drop policy if exists immopro_owner on public.{$table};");
            DB::unprepared("alter table public.{$table} no force row level security;");
            DB::unprepared("alter table public.{$table} disable row level security;");
        }

        DB::unprepared('drop function if exists public.app_user_id();');
    }
};
