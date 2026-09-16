<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row Level Security : couverture des garants, et ouverture en lecture de
 * l'espace locataire.
 *
 * Jusqu'ici une seule identité comptait, celle du bailleur, et une seule
 * question se posait : cette ligne lui appartient-elle ? L'espace locataire
 * introduit un second point de vue. Le locataire n'est propriétaire d'aucune
 * ligne — la fiche, le bail et le bien appartiennent au bailleur — mais il doit
 * voir la sienne. La condition ne peut donc pas être élargie : elle doit être
 * doublée.
 *
 * D'où deux policies distinctes par table plutôt qu'une condition avec un OU.
 * PostgreSQL combine les policies permissives par OU pour la lecture, mais
 * chacune garde son propre périmètre de commandes. Une seule policy `for all`
 * qui accepterait le locataire en lecture l'accepterait aussi en écriture : il
 * pourrait réécrire son propre loyer. La séparation `for all` / `for select`
 * est ce qui empêche cela, et c'est la raison d'être de ce découpage.
 *
 * Une exception, le dépôt de pièces : le locataire doit pouvoir téléverser son
 * attestation d'assurance. Il reçoit donc un droit d'insertion sur `documents`,
 * strictement borné — la pièce doit se rattacher à l'un de ses baux ou à sa
 * propre fiche, et porter le bailleur de cette entité comme propriétaire. Ni
 * modification ni suppression : une pièce versée au dossier ne se retire pas.
 *
 * `social_accounts` reste hors RLS, pour la même raison que `users` et
 * `personal_access_tokens` : elle est lue pendant l'authentification, avant
 * qu'une identité existe. La protéger rendrait la connexion Google et Apple
 * impossible.
 */
return new class extends Migration
{
    /**
     * Condition de possession par le bailleur, pour les tables ajoutées depuis
     * la migration d'origine.
     *
     * @return array<string, string>
     */
    private function ownerPolicies(): array
    {
        return [
            'guarantors' => 'exists (
                select 1 from public.tenants t
                 where t.id = guarantors.tenant_id
                   and t.user_id = public.app_user_id())',
        ];
    }

    /**
     * Le bail désigné par `%s` est-il l'un de ceux du locataire connecté ?
     *
     * Le titulaire principal et les colocataires sont logés à la même enseigne :
     * un colocataire qui ne verrait pas le bail qu'il a signé n'aurait pas
     * d'espace locataire du tout.
     */
    private function leaseBelongsToTenant(string $lease): string
    {
        return "({$lease}.tenant_id = any (public.app_tenant_ids())
                 or exists (select 1 from public.lease_tenant lt
                             where lt.lease_id = {$lease}.id
                               and lt.tenant_id = any (public.app_tenant_ids())))";
    }

    /**
     * Conditions de lecture pour le locataire connecté.
     *
     * @return array<string, string>
     */
    private function tenantReadPolicies(): array
    {
        $mine = 'any (public.app_tenant_ids())';

        return [
            'tenants' => "id = {$mine}",

            'guarantors' => "tenant_id = {$mine}",

            'lease_tenant' => "tenant_id = {$mine}",

            'leases' => $this->leaseBelongsToTenant('leases'),

            'properties' => 'exists (
                select 1 from public.leases l
                 where l.property_id = properties.id
                   and '.$this->leaseBelongsToTenant('l').')',

            'rent_payments' => 'exists (
                select 1 from public.leases l
                 where l.id = rent_payments.lease_id
                   and '.$this->leaseBelongsToTenant('l').')',

            'lease_photos' => 'exists (
                select 1 from public.leases l
                 where l.id = lease_photos.lease_id
                   and '.$this->leaseBelongsToTenant('l').')',

            'documents' => $this->documentsVisibleToTenant(),
        ];
    }

    /**
     * Pièces visibles par le locataire : celles de sa fiche, de ses baux et des
     * biens qu'il occupe.
     *
     * Les pièces du portefeuille sont volontairement absentes : un mandat de
     * gestion ou une taxe foncière regarde le bailleur seul.
     */
    private function documentsVisibleToTenant(): string
    {
        $mine = 'any (public.app_tenant_ids())';

        return "(documents.documentable_type = 'App\\Models\\Tenant'
                 and documents.documentable_id = {$mine})

             or (documents.documentable_type = 'App\\Models\\Lease'
                 and exists (select 1 from public.leases l
                              where l.id = documents.documentable_id
                                and ".$this->leaseBelongsToTenant('l')."))

             or (documents.documentable_type = 'App\\Models\\Property'
                 and exists (select 1 from public.leases l
                              where l.property_id = documents.documentable_id
                                and ".$this->leaseBelongsToTenant('l').'))';
    }

    /**
     * Dépôt d'une pièce par le locataire.
     *
     * `user_id` doit désigner le bailleur de l'entité visée. Sans cette
     * égalité, un locataire pourrait déposer une pièce en la déclarant sienne,
     * et elle échapperait ensuite à la policy du bailleur — un document
     * invisible dans le dossier où il a pourtant été versé.
     */
    private function tenantDocumentInsertPolicy(): string
    {
        $mine = 'any (public.app_tenant_ids())';

        return "(documents.documentable_type = 'App\\Models\\Tenant'
                 and documents.documentable_id = {$mine}
                 and exists (select 1 from public.tenants t
                              where t.id = documents.documentable_id
                                and t.user_id = documents.user_id))

             or (documents.documentable_type = 'App\\Models\\Lease'
                 and exists (select 1 from public.leases l
                               join public.properties pr on pr.id = l.property_id
                               join public.portfolios po on po.id = pr.portfolio_id
                              where l.id = documents.documentable_id
                                and po.user_id = documents.user_id
                                and ".$this->leaseBelongsToTenant('l').'))';
    }

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            // SQLite n'a pas de RLS. L'isolation de l'espace locataire y est
            // couverte par les tests de contrôleur.
            return;
        }

        /*
         * Fiches locataire rattachées au compte connecté.
         *
         * SECURITY DEFINER est indispensable et n'est pas une facilité : la
         * fonction lit `tenants`, table elle-même protégée par RLS. Exécutée
         * avec les droits de l'appelant, elle ne verrait rien et renverrait un
         * tableau vide — le locataire n'aurait jamais accès à son propre
         * dossier. Exécutée avec ceux du propriétaire de la fonction, elle voit
         * la table entière mais ne rend que les fiches du compte courant :
         * c'est `app_user_id()` qui borne le résultat, et cette valeur vient de
         * la session, pas du client.
         *
         * `search_path` est figé : sans cela, un schéma placé en tête du chemin
         * par un rôle malveillant y glisserait sa propre table `tenants`, que
         * la fonction lirait avec des droits élevés.
         */
        DB::unprepared(
            "create or replace function public.app_tenant_ids() returns bigint[]
                 language sql
                 stable
                 security definer
                 set search_path = public, pg_temp
                 as \$\$
                     select coalesce(array_agg(t.id), '{}'::bigint[])
                       from public.tenants t
                      where t.account_user_id = public.app_user_id()
                        and t.deleted_at is null
                 \$\$;"
        );

        /*
         * Rattachement d'un dossier au compte du locataire.
         *
         * Ce geste ne peut pas passer par les policies, et le constater vaut
         * mieux que de l'apprendre en production : la policy « locataire » se
         * fonde sur `account_user_id`, or c'est précisément la colonne qu'il
         * s'agit d'écrire. Tant qu'elle est vide, le compte ne voit pas la
         * ligne, donc ne peut pas la réclamer — et il ne la verra jamais.
         *
         * D'où cette fonction, seul endroit autorisé à franchir la barrière, et
         * réduite à ce qu'elle doit faire. Elle ne prend aucun paramètre : le
         * bénéficiaire est lu dans la session, pas reçu du client. Une signature
         * `claim_tenant_profiles(user_id)` aurait laissé au code appelant le
         * soin de passer le bon identifiant, c'est-à-dire la possibilité de
         * passer celui d'un autre.
         *
         * Trois conditions bornent l'écriture, et chacune retire une attaque :
         * l'adresse du compte doit être **vérifiée** — sans quoi il suffirait de
         * s'inscrire avec l'adresse d'un tiers pour lire son bail ; le dossier
         * ne doit être rattaché à **personne** — un dossier déjà réclamé ne
         * change pas de main ; l'adresse doit correspondre **exactement**, à la
         * casse près.
         */
        DB::unprepared(
            'create or replace function public.claim_tenant_profiles() returns integer
                 language plpgsql
                 security definer
                 set search_path = public, pg_temp
                 as $$
                 declare
                     v_email text;
                     v_count integer;
                 begin
                     select lower(u.email) into v_email
                       from public.users u
                      where u.id = public.app_user_id()
                        and u.email_verified_at is not null
                        and u.deleted_at is null;

                     if v_email is null then
                         return 0;
                     end if;

                     update public.tenants
                        set account_user_id = public.app_user_id()
                      where account_user_id is null
                        and deleted_at is null
                        and lower(email) = v_email;

                     get diagnostics v_count = row_count;

                     return v_count;
                 end;
                 $$;'
        );

        foreach ($this->ownerPolicies() as $table => $condition) {
            DB::unprepared("alter table public.{$table} enable row level security;");
            DB::unprepared("alter table public.{$table} force row level security;");

            DB::unprepared("drop policy if exists immopro_owner on public.{$table};");
            DB::unprepared(
                "create policy immopro_owner on public.{$table}
                     for all
                     using ({$condition})
                     with check ({$condition});"
            );
        }

        foreach ($this->tenantReadPolicies() as $table => $condition) {
            DB::unprepared("drop policy if exists immopro_tenant_read on public.{$table};");
            DB::unprepared(
                "create policy immopro_tenant_read on public.{$table}
                     for select
                     using ({$condition});"
            );
        }

        DB::unprepared('drop policy if exists immopro_tenant_upload on public.documents;');
        DB::unprepared(
            'create policy immopro_tenant_upload on public.documents
                 for insert
                 with check ('.$this->tenantDocumentInsertPolicy().');'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('drop policy if exists immopro_tenant_upload on public.documents;');

        foreach (array_keys($this->tenantReadPolicies()) as $table) {
            DB::unprepared("drop policy if exists immopro_tenant_read on public.{$table};");
        }

        foreach (array_keys($this->ownerPolicies()) as $table) {
            DB::unprepared("drop policy if exists immopro_owner on public.{$table};");
            DB::unprepared("alter table public.{$table} no force row level security;");
            DB::unprepared("alter table public.{$table} disable row level security;");
        }

        DB::unprepared('drop function if exists public.claim_tenant_profiles();');
        DB::unprepared('drop function if exists public.app_tenant_ids();');
    }
};
