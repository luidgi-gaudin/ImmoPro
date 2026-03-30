<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('proprietaire')->after('password');
            $table->text('iban')->nullable()->after('role');        //texte pour l'instant car enum compliquer a gerer en bdd
            $table->text('bic')->nullable()->after('iban');
            $table->text('siret')->nullable()->after('bic');
            $table->text('siren')->nullable()->after('siret');
            $table->string('currency', 3)->default('EUR')->after('siren');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'iban', 'bic', 'siret', 'siren', 'currency']);
            $table->dropSoftDeletes();
        });
    }
};
