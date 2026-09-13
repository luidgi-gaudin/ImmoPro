<?php

use App\Enums\OccupancyStatus;
use App\Enums\OwnershipType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fiche logement complète : adresse détaillée, second volet du DPE, régime de
 * propriété et état locatif.
 *
 * Trois manques que cette migration comble, et qui n'étaient pas des oublis
 * cosmétiques :
 *
 *   - l'adresse tenait en une ligne libre. Un immeuble de trente lots y était
 *     trente fois la même chaîne, et rien ne distinguait le 3e gauche du 5e
 *     droite. Étage et numéro d'appartement deviennent des champs.
 *   - le DPE ne portait que l'étiquette énergie. Le diagnostic en a deux depuis
 *     2021, et la seconde manquait tout entière.
 *   - l'état locatif était un booléen. « Pas loué » couvrait à la fois le
 *     logement à relouer et celui qu'on refait — deux situations qui n'appellent
 *     ni la même action ni le même taux d'occupation.
 *
 * `is_rented` est conservée et tenue à jour par le modèle : des écrans et des
 * filtres l'interrogent encore, et la retirer dans la même migration
 * changerait le schéma et le code appelant d'un seul coup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // Bâtiment, résidence, escalier : ce qui vient après la voie et
            // avant le code postal.
            $table->string('address_complement')->nullable()->after('address');

            // Texte et non entier : « RDC », « entresol » et « 3 » sont trois
            // réponses également valables, et un rez-de-chaussée n'est pas
            // l'étage zéro d'un immeuble sur pilotis.
            $table->string('floor', 20)->nullable()->after('address_complement');
            $table->string('apartment_number', 20)->nullable()->after('floor');

            $table->boolean('is_furnished')->default(false)->after('area_sqm');

            // Une terrasse n'est ni un balcon ni un jardin : elle se loue, se
            // décrit et s'assure autrement.
            $table->boolean('has_terrace')->default(false)->after('has_garden');

            // Un garage fermé et une place de parking extérieure ne valent pas
            // le même loyer accessoire.
            $table->boolean('has_garage')->default(false)->after('has_parking');

            // Second volet du diagnostic : émissions de gaz à effet de serre.
            $table->string('ges', 1)->nullable()->after('dpe_date');

            /*
             * Fin de validité du diagnostic.
             *
             * Elle était jusqu'ici recalculée à la volée (date + 10 ans), ce
             * qui suppose que tous les DPE ont la même durée. C'est faux pour
             * les diagnostics réalisés avant la réforme, dont la validité a été
             * écourtée par la loi Climat et Résilience. La date est désormais
             * portée par la fiche, avec le calcul en valeur par défaut.
             */
            $table->date('dpe_expires_on')->nullable()->after('ges');

            $table->string('ownership_type', 20)
                ->default(OwnershipType::Monopropriete->value)
                ->after('dpe_expires_on');

            // Informations du syndicat, renseignées seulement en copropriété.
            $table->string('syndic_name')->nullable()->after('ownership_type');
            $table->string('syndic_contact')->nullable()->after('syndic_name');
            $table->string('syndic_email')->nullable()->after('syndic_contact');
            $table->string('syndic_phone', 40)->nullable()->after('syndic_email');
            $table->string('syndic_address')->nullable()->after('syndic_phone');
            $table->unsignedInteger('lot_number')->nullable()->after('syndic_address');

            $table->string('occupancy_status', 20)
                ->default(OccupancyStatus::Vacant->value)
                ->after('is_rented');
        });

        /*
         * `property_type` et `dpe` étaient des colonnes ENUM, c'est-à-dire une
         * contrainte CHECK figée à la création. Ajouter « studio » à
         * l'énumération PHP sans toucher à la base aurait produit une
         * validation qui passe côté application et une insertion refusée côté
         * base — l'échec le plus désagréable qui soit, puisqu'il ne se voit
         * qu'en production.
         *
         * La liste des valeurs vit maintenant dans les enums PHP, appliquée par
         * la validation des requêtes. Une seule source, modifiable sans
         * migration.
         */
        $this->dropEnumCheckConstraints('properties', ['property_type', 'dpe']);

        Schema::table('properties', function (Blueprint $table) {
            $table->string('property_type', 30)->change();
            $table->string('dpe', 1)->change();
        });

        // Reprise des lignes existantes : le booléen devient un état.
        DB::table('properties')->where('is_rented', true)
            ->update(['occupancy_status' => OccupancyStatus::Loue->value]);
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([
                'address_complement',
                'floor',
                'apartment_number',
                'is_furnished',
                'has_terrace',
                'has_garage',
                'ges',
                'dpe_expires_on',
                'ownership_type',
                'syndic_name',
                'syndic_contact',
                'syndic_email',
                'syndic_phone',
                'syndic_address',
                'lot_number',
                'occupancy_status',
            ]);
        });
    }

    /**
     * Supprime les contraintes CHECK laissées par une colonne ENUM.
     *
     * Sur PostgreSQL, `$table->enum()` ne crée pas un type énuméré mais un
     * `varchar` assorti d'un `check (colonne in (...))` anonyme. Passer la
     * colonne en `string()` ne touche pas à cette contrainte : Laravel n'émet
     * qu'un `alter column ... type ...`. La colonne accepterait donc toujours
     * les seules valeurs listées en 2026, et l'insertion d'un « studio »
     * échouerait — en production uniquement, puisque les tests tournent sur
     * SQLite, où la reconstruction de table emporte la contrainte.
     *
     * Le nom est relu dans `pg_constraint` plutôt que déduit de la convention
     * `table_colonne_check` : une contrainte renommée à la main ne doit pas
     * faire échouer la migration en silence.
     *
     * @param  list<string>  $columns
     */
    private function dropEnumCheckConstraints(string $table, array $columns): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($columns as $column) {
            $names = DB::select(
                "select c.conname
                   from pg_constraint c
                   join pg_class t on t.oid = c.conrelid
                   join pg_attribute a on a.attrelid = t.oid and a.attnum = any (c.conkey)
                  where c.contype = 'c'
                    and t.relname = ?
                    and a.attname = ?",
                [$table, $column]
            );

            foreach ($names as $row) {
                DB::statement(sprintf(
                    'alter table %s drop constraint if exists %s',
                    '"'.$table.'"',
                    '"'.$row->conname.'"'
                ));
            }
        }
    }
};
