<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // Date de réalisation du DPE. Un DPE est valable 10 ans
            // (réforme 2021, loi Climat et Résilience) : sert au calcul de son expiration.
            $table->date('dpe_date')->nullable()->after('dpe');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('dpe_date');
        });
    }
};
