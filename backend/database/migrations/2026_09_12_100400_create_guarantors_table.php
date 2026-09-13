<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Garants d'un locataire.
 *
 * Une table plutôt que des colonnes sur `tenants`, parce que le pluriel est la
 * règle et non l'exception : deux parents se portent couramment caution
 * solidaire pour un étudiant, et un dossier peut cumuler une caution
 * personnelle et une garantie Visale. Des champs `garant_1_*`, `garant_2_*`
 * auraient plafonné le dossier à un nombre arbitraire de garants et rendu toute
 * recherche impraticable.
 *
 * Le garant est rattaché au locataire et non au bail : la même personne se
 * porte caution pour la personne, et le renouvellement du bail ne recrée pas
 * l'engagement. L'acte de cautionnement lui-même, lui, se range dans les
 * documents du bail — c'est une pièce contractuelle datée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guarantors', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Voir App\Enums\GuaranteeType : commande ce qui est exigible en
            // cas d'impayé, et si le garant est une personne ou un organisme.
            $table->string('guarantee_type', 40);

            // Personne physique.
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('profession')->nullable();

            // Organisme (Visale, assureur), ou employeur de la caution.
            $table->string('company_name')->nullable();

            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();

            /*
             * Revenus déclarés du garant.
             *
             * Sert à vérifier la règle d'usage des trois fois le loyer avant
             * d'accepter le dossier. Nullable : un organisme n'en a pas.
             */
            $table->decimal('monthly_income', 12, 2)->nullable();

            // Numéro de visa Visale ou de contrat d'assurance loyers impayés.
            $table->string('contract_reference')->nullable();

            /*
             * Bornes de l'engagement.
             *
             * Un cautionnement à durée déterminée s'éteint à son terme, et le
             * bailleur qui l'ignore croit couvert un impayé qui ne l'est plus.
             * `max_amount` porte le plafond quand l'acte en fixe un.
             */
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->decimal('max_amount', 12, 2)->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'guarantee_type']);
            $table->index('ends_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guarantors');
    }
};
