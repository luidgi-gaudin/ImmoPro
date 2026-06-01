<?php

namespace Database\Factories;

use App\Enums\LeaseStatus;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Lease>
 */
class LeaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_id'  => Property::factory(),
            'tenant_id'    => Tenant::factory(),
            'start_date'   => fake()->date(),
            'end_date'     => fake()->optional()->date(),
            'monthly_rent' => fake()->randomFloat(2, 300, 3000),
            'deposit'      => fake()->randomFloat(2, 300, 3000),
            'statut'       => fake()->randomElement(LeaseStatus::cases())->value,
        ];
    }
}
