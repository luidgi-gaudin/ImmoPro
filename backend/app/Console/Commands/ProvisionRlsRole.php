<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crée (ou vérifie) le rôle PostgreSQL soumis à la Row Level Security.
 *
 * Sans ce rôle, les policies de la migration `enable_row_level_security` sont
 * inertes : Laravel se connecte par défaut avec le rôle `postgres` de Supabase,
 * qui possède les tables *et* porte l'attribut BYPASSRLS. L'un comme l'autre
 * suffit à passer outre — y compris avec FORCE ROW LEVEL SECURITY, qui ne
 * couvre que le cas du propriétaire.
 *
 * La commande est sans effet si le rôle existe déjà : elle se contente alors de
 * réaligner les droits et de rendre son verdict.
 */
class ProvisionRlsRole extends Command
{
    protected $signature = 'immopro:rls-provision
                            {--role= : Nom du rôle applicatif (défaut : config immopro.rls.role)}
                            {--password= : Mot de passe du rôle (généré si absent)}
                            {--verify : Ne rien modifier, seulement diagnostiquer}';

    protected $description = 'Crée le rôle applicatif soumis aux policies RLS et vérifie qu\'elles mordent réellement';

    public function handle(): int
    {
        $admin = DB::connection('pgsql_admin');

        if ($admin->getDriverName() !== 'pgsql') {
            $this->error('La Row Level Security ne concerne que PostgreSQL.');

            return self::FAILURE;
        }

        $role = (string) ($this->option('role') ?: config('immopro.rls.role'));

        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $role)) {
            $this->error("Nom de rôle invalide : {$role}");

            return self::FAILURE;
        }

        if ($this->option('verify')) {
            return $this->verify($admin, $role);
        }

        $password = (string) ($this->option('password') ?: Str::random(40));
        $exists = $this->roleExists($admin, $role);

        if ($exists) {
            $this->line("Le rôle <info>{$role}</info> existe déjà : ses droits sont réalignés, son mot de passe conservé.");
        } else {
            $admin->statement(sprintf(
                'create role %s with login password %s nosuperuser nocreatedb nocreaterole noinherit nobypassrls',
                $admin->getQueryGrammar()->wrap($role),
                $admin->getPdo()->quote($password)
            ));
            $this->info("Rôle {$role} créé.");
        }

        $this->grant($admin, $role);

        $this->newLine();
        $this->line('Droits accordés. Reportez ceci dans <info>backend/.env</info> :');
        $this->newLine();

        foreach ($this->envLines($role, $exists ? null : $password) as $line) {
            $this->line("  <comment>{$line}</comment>");
        }

        $this->newLine();
        $this->line('Puis : <info>php artisan config:clear</info> et <info>php artisan immopro:rls-provision --verify</info>.');

        return self::SUCCESS;
    }

    private function roleExists(Connection $admin, string $role): bool
    {
        return $admin->selectOne('select 1 from pg_roles where rolname = ?', [$role]) !== null;
    }

    /**
     * Droits strictement applicatifs : lire et écrire les lignes, rien de plus.
     *
     * Pas de CREATE sur le schéma : le rôle ne doit pas pouvoir créer de table,
     * car le propriétaire d'une table échappe à ses propres policies. C'est
     * exactement le trou que ce rôle existe pour fermer.
     */
    private function grant(Connection $admin, string $role): void
    {
        $quoted = $admin->getQueryGrammar()->wrap($role);

        $statements = [
            "grant connect on database {$admin->getQueryGrammar()->wrap($admin->getDatabaseName())} to {$quoted}",
            "grant usage on schema public to {$quoted}",
            "grant select, insert, update, delete on all tables in schema public to {$quoted}",
            "grant usage, select on all sequences in schema public to {$quoted}",
            "grant execute on all functions in schema public to {$quoted}",

            // Les tables créées par les futures migrations doivent être
            // accessibles sans repasser la commande.
            "alter default privileges in schema public grant select, insert, update, delete on tables to {$quoted}",
            "alter default privileges in schema public grant usage, select on sequences to {$quoted}",
            "alter default privileges in schema public grant execute on functions to {$quoted}",

            // Posé sur le rôle, et non envoyé par Laravel à chaque connexion.
            // Le connecteur PostgreSQL de Laravel émet un `set search_path`
            // dès que la clé est présente dans config/database.php : un
            // aller-retour de ~150 ms payé sur chaque requête HTTP, invisible
            // dans les journaux puisqu'il précède la première requête.
            // PostgreSQL applique celui-ci à l'ouverture de session, gratuitement.
            "alter role {$quoted} set search_path to \"\$user\", public, extensions",
        ];

        foreach ($statements as $statement) {
            $admin->statement($statement);
        }
    }

    /**
     * Lignes de configuration à reporter.
     *
     * Le pooler Supabase identifie le projet dans le nom d'utilisateur, sous la
     * forme `<rôle>.<référence>`. Un `immopro_app` nu serait refusé avec un
     * message qui ne désigne pas la cause — d'où la reconstruction à partir de
     * l'identifiant actuel.
     *
     * @return list<string>
     */
    private function envLines(string $role, ?string $password): array
    {
        $current = (string) config('database.connections.pgsql.username');
        $projectRef = str_contains($current, '.') ? Str::after($current, '.') : null;
        $username = $projectRef !== null ? "{$role}.{$projectRef}" : $role;

        $lines = [
            '# Rôle privilégié, réservé aux migrations et au scan d\'alertes.',
            'DB_ADMIN_USERNAME='.$current,
            'DB_ADMIN_PASSWORD='.config('database.connections.pgsql.password'),
            '',
            '# Rôle applicatif, soumis aux policies RLS.',
            'DB_USERNAME='.$username,
        ];

        $lines[] = 'DB_PASSWORD='.($password ?? '<mot de passe existant du rôle>');
        $lines[] = 'DB_RLS_ENABLED=true';

        return $lines;
    }

    /**
     * Diagnostic : la RLS mord-elle vraiment ?
     *
     * Poser des policies ne prouve rien — c'est précisément le piège de ce
     * dispositif. Trois conditions se vérifient ici, et l'une d'elles suffit à
     * tout annuler silencieusement.
     */
    private function verify(Connection $admin, string $role): int
    {
        $app = DB::connection('pgsql');
        $identity = $app->selectOne('select current_user as role');

        $attributes = $admin->selectOne(
            'select rolsuper, rolbypassrls from pg_roles where rolname = ?',
            [$identity->role]
        );

        $owned = (int) $admin->selectOne(
            "select count(*) as n
               from pg_class c
               join pg_namespace n on n.oid = c.relnamespace
              where n.nspname = 'public'
                and c.relkind = 'r'
                and pg_get_userbyid(c.relowner) = ?",
            [$identity->role]
        )->n;

        $protected = $admin->select(
            "select c.relname, c.relrowsecurity, c.relforcerowsecurity,
                    (select count(*) from pg_policy p where p.polrelid = c.oid) as policies
               from pg_class c
               join pg_namespace n on n.oid = c.relnamespace
              where n.nspname = 'public' and c.relkind = 'r'
                and c.relrowsecurity
              order by c.relname"
        );

        $this->line("Connexion applicative : <info>{$identity->role}</info>");
        $this->table(
            ['Vérification', 'Attendu', 'Constaté'],
            [
                ['Rôle applicatif distinct', $role, $identity->role === $role ? '✓ '.$identity->role : '✗ '.$identity->role],
                ['Pas superutilisateur', 'non', $attributes?->rolsuper ? '✗ oui' : '✓ non'],
                ['Pas BYPASSRLS', 'non', $attributes?->rolbypassrls ? '✗ oui' : '✓ non'],
                ['Ne possède aucune table', '0', $owned === 0 ? '✓ 0' : "✗ {$owned}"],
                ['Tables sous RLS', '9', count($protected) >= 9 ? '✓ '.count($protected) : '✗ '.count($protected)],
            ]
        );

        $effective = $identity->role === $role
            && ! $attributes?->rolsuper
            && ! $attributes?->rolbypassrls
            && $owned === 0
            && count($protected) >= 9;

        if (! $effective) {
            $this->newLine();
            $this->warn('Les policies sont posées mais n\'ont aucun effet sur cette connexion.');
            $this->line('Une seule ligne « ✗ » suffit : le rôle passe outre, et les données ne sont isolées que par le code applicatif.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('La Row Level Security est active et s\'applique à la connexion de l\'application.');

        return self::SUCCESS;
    }
}
