<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();

            // Le bailleur destinataire de l'alerte (isolation stricte par compte).
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('type');                 // App\Enums\AlertType
            $table->string('severity')->default('warning'); // App\Enums\AlertSeverity

            // Objet concerné (RentPayment, Lease, Property...) — relation polymorphe.
            $table->nullableMorphs('alertable');

            // Clé de déduplication : garantit qu'un même évènement (ex. un loyer)
            // ne génère l'alerte qu'une seule fois, quel que soit le nombre de scans.
            $table->string('dedup_key')->unique();

            $table->string('title');
            $table->text('message');
            $table->date('due_date')->nullable(); // date de l'évènement (échéance, révision, fin de bail, expiration DPE)
            $table->json('meta')->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamp('reminded_at')->nullable();  // relance locataire (loyer impayé)
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
