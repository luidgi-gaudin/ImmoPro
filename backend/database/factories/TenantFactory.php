<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Tenant>
 */
class TenantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'    => User::factory(),
            'first_name' => fake()->firstName(),
            'last_name'  => fake()->lastName(),
            'email'      => fake()->unique()->safeEmail(),
            'phone'      => fake()->phoneNumber(),
            'iban'       => fake()->iban(),
            'bic'        => fake()->swiftBicNumber(),
            'country'    => fake()->country(),
            'address'    => fake()->address(),
        ];
    }
}
