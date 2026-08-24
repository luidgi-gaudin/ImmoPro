<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index sur les clés étrangères, absents jusqu'ici.
 *
 * `foreignId()` crée la colonne, et `constrained()` la contrainte — mais aucun
 * des deux ne pose d'index. La vérification l'a confirmé : sur les dix tables
 * métier, seules `rent_payments` et `alerts` en avaient un, ajouté à la main.
 *
 * Deux raisons de combler ce trou maintenant :
 *
 *   - toutes les listes de l'application filtrent sur ces colonnes (les biens
 *     d'un portefeuille, les baux d'un bien, les échéances d'un bail) ;
 *   - les policies de Row Level Security posées juste après reposent sur des
 *     sous-requêtes qui parcourent exactement ces mêmes colonnes, et
 *     s'exécutent sur *chaque* ligne lue. Sans index, la RLS transformerait
 *     chaque lecture en balayage complet.
 *
 * PostgreSQL vérifie aussi ces colonnes à chaque suppression du parent : sans
 * index, effacer un portefeuille balaie toute la table des biens.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private array $indexes = [
        'portfolios' => ['user_id'],
        'properties' => ['portfolio_id'],
        'tenants' => ['user_id'],
        'leases' => ['property_id', 'tenant_id'],
        'lease_photos' => ['lease_id'],
        'lease_tenant' => ['tenant_id'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns) {
                foreach ($columns as $column) {
                    if (! $this->hasIndex($table, $column)) {
                        $blueprint->index($column);
                    }
                }
            });
        }

        // Le tri par défaut des baux (start_date desc) et le filtre sur le
        // statut sont sur toutes les pages « Baux » : l'index composite les
        // sert d'un seul parcours.
        Schema::table('leases', function (Blueprint $blueprint) {
            if (! $this->hasIndex('leases', 'statut', 'start_date')) {
                $blueprint->index(['statut', 'start_date']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $blueprint) {
            $blueprint->dropIndex('leases_statut_start_date_index');
        });

        foreach ($this->indexes as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns) {
                foreach ($columns as $column) {
                    $blueprint->dropIndex("{$table}_{$column}_index");
                }
            });
        }
    }

    /**
     * Rend la migration rejouable : un index déjà posé à la main sur une base
     * existante ne doit pas la faire échouer.
     */
    private function hasIndex(string $table, string ...$columns): bool
    {
        $name = $table.'_'.implode('_', $columns).'_index';

        return in_array($name, array_map(
            fn (array $index) => $index['name'],
            Schema::getIndexes($table)
        ), true);
    }
};
