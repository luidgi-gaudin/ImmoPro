<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('leases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id');
            $table->foreignId('tenant_id');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->decimal('monthly_rent');
            $table->decimal('deposit')->nullable();
            $table->enum('statut', ['actif', 'termine', 'en_attente'])->default('en_attente');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('leases');
    }
};
