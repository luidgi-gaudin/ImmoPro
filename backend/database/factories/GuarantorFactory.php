<?php

namespace Database\Factories;

use App\Enums\GuaranteeType;
use App\Models\Guarantor;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Guarantor>
 */
class GuarantorFactory extends Factory
{
    protected $model = Guarantor::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'guarantee_type' => GuaranteeType::CautionSolidaire->value,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('06########'),
            'monthly_income' => fake()->numberBetween(2000, 6000),
        ];
    }

    /** Garantie portée par un organisme : ni prénom ni nom, un numéro de dossier. */
    public function visale(): static
    {
        return $this->state(fn (array $attributes) => [
            'guarantee_type' => GuaranteeType::Visale->value,
            'first_name' => null,
            'last_name' => null,
            'company_name' => 'Action Logement',
            'contract_reference' => fake()->bothify('VISALE-########'),
            'monthly_income' => null,
        ]);
    }

    /** Engagement échu : ne couvre plus rien, et la fiche doit le dire. */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_on' => now()->subYears(3)->toDateString(),
            'ends_on' => now()->subMonth()->toDateString(),
        ]);
    }
}
