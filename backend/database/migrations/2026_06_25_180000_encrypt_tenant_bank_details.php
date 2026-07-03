<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RGPD : chiffre au repos les coordonnées bancaires des locataires,
 * comme c'est déjà le cas pour celles des utilisateurs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->text('iban')->nullable()->change();
            $table->text('bic')->nullable()->change();
        });

        DB::table('tenants')
            ->where(fn ($q) => $q->whereNotNull('iban')->orWhereNotNull('bic'))
            ->get()
            ->each(function ($tenant) {
                DB::table('tenants')->where('id', $tenant->id)->update([
                    'iban' => $tenant->iban !== null ? Crypt::encryptString($tenant->iban) : null,
                    'bic' => $tenant->bic !== null ? Crypt::encryptString($tenant->bic) : null,
                ]);
            });
    }

    public function down(): void
    {
        DB::table('tenants')
            ->where(fn ($q) => $q->whereNotNull('iban')->orWhereNotNull('bic'))
            ->get()
            ->each(function ($tenant) {
                DB::table('tenants')->where('id', $tenant->id)->update([
                    'iban' => $tenant->iban !== null ? Crypt::decryptString($tenant->iban) : null,
                    'bic' => $tenant->bic !== null ? Crypt::decryptString($tenant->bic) : null,
                ]);
            });

        Schema::table('tenants', function (Blueprint $table) {
            $table->string('iban')->nullable()->change();
            $table->string('bic')->nullable()->change();
        });
    }
};
