<?php

namespace Database\Factories;

use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    protected $model = SocialAccount::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => SocialProvider::Google->value,
            'provider_user_id' => (string) fake()->unique()->numerify('##################'),
            'provider_email' => fake()->unique()->safeEmail(),
            'provider_name' => fake()->name(),
        ];
    }

    public function apple(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => SocialProvider::Apple->value,
        ]);
    }
}
