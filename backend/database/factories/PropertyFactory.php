<?php

namespace Database\Factories;

use App\Enums\Dpe;
use App\Enums\PropertyType;
use App\Models\Portfolio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Property>
 */
class PropertyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'portfolio_id'  => Portfolio::factory(),
            'title'         => fake()->sentence(3),
            'property_type' => fake()->randomElement(PropertyType::cases())->value,
            'address'       => fake()->streetAddress(),
            'city'          => fake()->city(),
            'postal_code'   => fake()->postcode(),
            'latitude'      => fake()->latitude(),
            'longitude'     => fake()->longitude(),
            'dpe'           => fake()->randomElement(Dpe::cases())->value,
            'rooms'         => fake()->numberBetween(1, 10),
            'area_sqm'      => fake()->randomFloat(2, 15, 300),
            'has_balcony'   => false,
            'has_garden'    => false,
            'has_parking'   => false,
            'has_cave'      => false,
            'is_rented'     => false,
            'monthly_rent'  => null,
            'description'   => fake()->optional()->sentence(),
        ];
    }
}
