<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rent_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id');
            $table->date('period'); // premier jour du mois concerné
            $table->decimal('amount_rent');
            $table->decimal('amount_charges')->default(0);
            $table->date('paid_at')->nullable();
            $table->string('payment_method')->nullable();
            $table->timestamps();
            $table->softDeletes();
            // Pas d'index unique strict : les échéances soft-deleted doivent pouvoir être recréées.
            // L'unicité (bail, mois) est garantie par la validation applicative.
            $table->index(['lease_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rent_payments');
    }
};
