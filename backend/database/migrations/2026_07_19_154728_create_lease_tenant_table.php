<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Colocataires additionnels d'un bail (au-delà du locataire principal `leases.tenant_id`),
     * avec une éventuelle répartition du loyer par colocataire (`rent_share`).
     */
    public function up(): void
    {
        Schema::create('lease_tenant', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->decimal('rent_share', 10, 2)->nullable();
            $table->timestamps();

            $table->unique(['lease_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_tenant');
    }
};
