<?php

namespace Database\Factories;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alert>
 */
class AlertFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => AlertType::LoyerImpaye->value,
            'severity' => AlertSeverity::Warning->value,
            'dedup_key' => 'test:'.fake()->unique()->uuid(),
            'title' => fake()->sentence(3),
            'message' => fake()->sentence(),
            'due_date' => now()->toDateString(),
            'meta' => null,
        ];
    }
}
