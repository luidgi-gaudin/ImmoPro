<?php

namespace Database\Factories;

use App\Enums\DocumentCategory;
use App\Models\Document;
use App\Models\Lease;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'documentable_type' => Lease::class,
            'documentable_id' => Lease::factory(),
            'category' => DocumentCategory::BailSigne->value,
            'name' => 'Bail signé',
            'original_name' => 'bail-signe.pdf',
            'path' => '1/lease/'.$this->faker->uuid().'.pdf',
            'disk' => 'documents',
            'mime_type' => 'application/pdf',
            'size_bytes' => $this->faker->numberBetween(20_000, 4_000_000),
            'issued_on' => now()->subMonths(3)->toDateString(),
            'expires_on' => null,
            'notes' => null,
        ];
    }

    /** Pièce dont la validité est dépassée, pour les tests de rappel. */
    public function expired(): static
    {
        return $this->state(fn () => [
            'category' => DocumentCategory::AttestationAssurance->value,
            'expires_on' => now()->subWeek()->toDateString(),
        ]);
    }
}
