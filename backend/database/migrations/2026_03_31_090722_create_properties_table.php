<?php

use App\Enums\Dpe;
use App\Enums\PropertyType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('portfolio_id');
            $table->enum('property_type', array_column(PropertyType::cases(), 'value'));
            $table->string('address');
            $table->string('city');
            $table->string('postal_code');
            $table->decimal('latitude')->nullable();
            $table->decimal('longitude')->nullable();
            $table->enum('dpe', array_column(Dpe::cases(), 'value'));
            $table->integer('rooms')->nullable();
            $table->decimal('area_sqm')->nullable();
            $table->boolean('has_balcony')->default(false);
            $table->boolean('has_garden')->default(false);
            $table->boolean('has_parking')->default(false);
            $table->boolean('has_cave')->default(false);
            $table->boolean('is_rented')->default(false);
            $table->decimal('monthly_rent')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('properties');
    }
};
