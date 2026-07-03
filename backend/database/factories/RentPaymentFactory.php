<?php

namespace Database\Factories;

use App\Models\Lease;
use App\Models\RentPayment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<RentPayment>
 */
class RentPaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lease_id' => Lease::factory(),
            'period' => Carbon::instance(fake()->unique()->dateTimeBetween('-3 years', 'now'))
                ->startOfMonth()
                ->toDateString(),
            'amount_rent' => fake()->randomFloat(2, 300, 3000),
            'amount_charges' => fake()->randomFloat(2, 0, 300),
            'paid_at' => null,
            'payment_method' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'paid_at' => Carbon::parse($attributes['period'])->addDays(2)->toDateString(),
            'payment_method' => 'virement',
        ]);
    }
}
