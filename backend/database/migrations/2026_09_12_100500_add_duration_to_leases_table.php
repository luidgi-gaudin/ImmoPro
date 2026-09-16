<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durée contractuelle du bail, en mois.
 *
 * Elle se déduisait jusqu'ici des deux dates. C'est faux dès que le bail est en
 * cours de reconduction : un bail de trois ans reconduit tacitement affiche
 * alors une durée de six ans, alors que la durée *contractuelle* — celle qui
 * figure au contrat et que la loi encadre — est restée de trois ans. C'est elle
 * qui décide du préavis et du régime applicable, pas l'écart entre la première
 * et la dernière date.
 *
 * Nullable, et alimentée par défaut à partir des dates existantes : les baux
 * déjà saisis ne portent pas cette information et personne ne peut l'inventer
 * à leur place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration_months')->nullable()->after('end_date');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('duration_months');
        });
    }
};
