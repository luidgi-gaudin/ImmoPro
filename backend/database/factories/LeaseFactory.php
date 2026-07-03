<?php

namespace Database\Factories;

use App\Enums\LeaseStatus;
use App\Enums\LeaseType;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Lease>
 */
class LeaseFactory extends Factory
{
    public function definition(): array
    {
        $rent = fake()->randomFloat(2, 300, 3000);
        $start = Carbon::instance(fake()->dateTimeBetween('-2 years', 'now'))->startOfDay();

        return [
            'property_id' => Property::factory(),
            'tenant_id' => Tenant::factory(),
            'type' => LeaseType::Nu->value,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addYears(3)->toDateString(), // durée légale minimale en location vide
            'monthly_rent' => $rent,
            'charges' => fake()->randomFloat(2, 0, 300),
            'deposit' => $rent, // plafond : 1 mois de loyer hors charges (art. 22)
            'payment_day' => fake()->numberBetween(1, 28),
            'statut' => fake()->randomElement(LeaseStatus::cases())->value,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['statut' => LeaseStatus::Actif->value]);
    }

    public function meuble(): static
    {
        return $this->state(function (array $attributes) {
            $start = Carbon::parse($attributes['start_date']);

            return [
                'type' => LeaseType::Meuble->value,
                'end_date' => $start->copy()->addYear()->toDateString(),
            ];
        });
    }
}
