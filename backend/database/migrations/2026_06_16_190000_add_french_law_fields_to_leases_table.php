<?php

use App\Enums\LeaseType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->string('type')->default(LeaseType::Nu->value)->after('tenant_id');
            $table->decimal('charges')->default(0)->after('monthly_rent');
            $table->unsignedTinyInteger('payment_day')->default(1)->after('deposit');
            $table->date('last_rent_revision_at')->nullable()->after('payment_day');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn(['type', 'charges', 'payment_day', 'last_rent_revision_at']);
        });
    }
};
