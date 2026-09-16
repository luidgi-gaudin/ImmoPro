<?php

namespace Tests\Feature;

use App\Enums\OccupancyStatus;
use App\Enums\OwnershipType;
use App\Enums\PropertyType;
use App\Models\Lease;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fiche logement : les champs ajoutés et les deux règles que le modèle tient
 * lui-même — l'état locatif et l'expiration du diagnostic.
 */
class PropertyDetailFieldsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Portfolio} */
    private function landlordWithPortfolio(): array
    {
        $landlord = User::factory()->create();

        return [$landlord, Portfolio::factory()->create(['user_id' => $landlord->id])];
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Studio Bastille',
            'property_type' => PropertyType::Studio->value,
            'address' => '12 rue de la Roquette',
            'city' => 'Paris',
            'postal_code' => '75011',
            'dpe' => 'D',
        ], $overrides);
    }

    /**
     * « Studio » a été ajouté à l'énumération. Le test vérifie que la base
     * l'accepte : la contrainte CHECK laissée par l'ancienne colonne ENUM
     * l'aurait refusé côté serveur seulement.
     */
    public function test_a_studio_can_be_registered(): void
    {
        [$landlord, $portfolio] = $this->landlordWithPortfolio();

        $this->actingAs($landlord)
            ->postJson("/api/portfolios/{$portfolio->id}/properties", $this->payload())
            ->assertStatus(201);

        $this->assertDatabaseHas('properties', ['property_type' => 'studio']);
    }

    public function test_the_detailed_address_is_recorded(): void
    {
        [$landlord, $portfolio] = $this->landlordWithPortfolio();

        $this->actingAs($landlord)
            ->postJson("/api/portfolios/{$portfolio->id}/properties", $this->payload([
                'address_complement' => 'Bâtiment B, escalier 2',
                'floor' => 'RDC',
                'apartment_number' => '3',
            ]))
            ->assertStatus(201);

        $property = Property::sole();

        $this->assertSame('Bâtiment B, escalier 2', $property->address_complement);
        // Texte et non entier : un rez-de-chaussée n'est pas l'étage zéro.
        $this->assertSame('RDC', $property->floor);
    }

    public function test_the_second_dpe_label_is_recorded(): void
    {
        [$landlord, $portfolio] = $this->landlordWithPortfolio();

        $this->actingAs($landlord)
            ->postJson("/api/portfolios/{$portfolio->id}/properties", $this->payload([
                'dpe' => 'E',
                'ges' => 'B',
                'dpe_date' => '2023-04-15',
            ]))
            ->assertStatus(201);

        $property = Property::sole();

        $this->assertSame('E', $property->dpe->value);
        $this->assertSame('B', $property->ges->value);
    }

    /** Dix ans depuis la réforme de 2021, déduits quand rien n'est saisi. */
    public function test_the_dpe_expiry_is_derived_from_its_date(): void
    {
        [$landlord, $portfolio] = $this->landlordWithPortfolio();

        $this->actingAs($landlord)
            ->postJson("/api/portfolios/{$portfolio->id}/properties", $this->payload([
                'dpe_date' => '2023-04-15',
            ]))
            ->assertStatus(201);

        $this->assertSame('2033-04-15', Property::sole()->dpe_expires_on->toDateString());
    }

    /**
     * Elle reste modifiable : les diagnostics antérieurs à 2021 ont vu leur
     * validité écourtée par la loi Climat et Résilience.
     */
    public function test_a_stated_expiry_wins_over_the_calculation(): void
    {
        [$landlord, $portfolio] = $this->landlordWithPortfolio();

        $this->actingAs($landlord)
            ->postJson("/api/portfolios/{$portfolio->id}/properties", $this->payload([
                'dpe_date' => '2019-04-15',
                'dpe_expires_on' => '2024-12-31',
            ]))
            ->assertStatus(201);

        $this->assertSame('2024-12-31', Property::sole()->dpe_expires_on->toDateString());
    }

    public function test_the_syndicate_details_are_recorded(): void
    {
        [$landlord, $portfolio] = $this->landlordWithPortfolio();

        $this->actingAs($landlord)
            ->postJson("/api/portfolios/{$portfolio->id}/properties", $this->payload([
                'ownership_type' => OwnershipType::Copropriete->value,
                'syndic_name' => 'Cabinet Foncia',
                'syndic_email' => 'contact@foncia.example',
                'lot_number' => 42,
            ]))
            ->assertStatus(201);

        $property = Property::sole();

        $this->assertSame(OwnershipType::Copropriete, $property->ownership_type);
        $this->assertSame(42, $property->lot_number);
        $this->assertFalse($property->missesSyndicDetails());
    }

    public function test_a_copropriete_without_a_syndicate_is_flagged(): void
    {
        [$landlord, $portfolio] = $this->landlordWithPortfolio();

        $this->actingAs($landlord)
            ->postJson("/api/portfolios/{$portfolio->id}/properties", $this->payload([
                'ownership_type' => OwnershipType::Copropriete->value,
            ]))
            ->assertStatus(201);

        $this->assertTrue(Property::sole()->missesSyndicDetails());
    }

    /* ----------------------------------------------------------------------
     | État locatif
     |----------------------------------------------------------------------*/

    /**
     * Le booléen historique reste lu par des écrans et des filtres : deux
     * sources de vérité qui divergent valent moins qu'une seule.
     */
    public function test_declaring_the_status_keeps_the_boolean_in_step(): void
    {
        [$landlord, $portfolio] = $this->landlordWithPortfolio();

        $this->actingAs($landlord)
            ->postJson("/api/portfolios/{$portfolio->id}/properties", $this->payload([
                'occupancy_status' => OccupancyStatus::EnTravaux->value,
            ]))
            ->assertStatus(201);

        $property = Property::sole();

        $this->assertSame(OccupancyStatus::EnTravaux, $property->occupancy_status);
        $this->assertFalse($property->is_rented);
    }

    /** Le contrat fait foi : un bail actif marque le bien comme loué. */
    public function test_an_active_lease_sets_the_status(): void
    {
        [$landlord, $portfolio] = $this->landlordWithPortfolio();

        $property = Property::factory()->create([
            'portfolio_id' => $portfolio->id,
            'occupancy_status' => OccupancyStatus::Vacant->value,
        ]);

        Lease::factory()->create([
            'property_id' => $property->id,
            'tenant_id' => Tenant::factory()->create(['user_id' => $landlord->id])->id,
            'statut' => 'actif',
        ]);

        $property->refresh();

        $this->assertTrue($property->is_rented);
        $this->assertSame(OccupancyStatus::Loue, $property->occupancy_status);
    }

    /**
     * Un logement rendu n'est pas pour autant relouable : écraser « en travaux »
     * ferait disparaître un chantier en cours de la liste des indisponibles.
     */
    public function test_ending_a_lease_does_not_erase_a_declared_renovation(): void
    {
        [$landlord, $portfolio] = $this->landlordWithPortfolio();

        $property = Property::factory()->create([
            'portfolio_id' => $portfolio->id,
            'occupancy_status' => OccupancyStatus::EnTravaux->value,
        ]);

        $lease = Lease::factory()->create([
            'property_id' => $property->id,
            'tenant_id' => Tenant::factory()->create(['user_id' => $landlord->id])->id,
            'statut' => 'termine',
        ]);

        $this->assertSame(OccupancyStatus::EnTravaux, $property->refresh()->occupancy_status);
        $this->assertFalse($property->is_rented);
    }

    /**
     * Les dates sortent sans heure.
     *
     * Un champ de saisie de type `date` ne sait pas lire
     * « 2023-04-15T00:00:00.000000Z » et s'affiche vide : le formulaire de
     * modification perdait silencieusement la date du diagnostic à chaque
     * enregistrement.
     */
    public function test_dates_are_serialised_without_a_time(): void
    {
        [$landlord, $portfolio] = $this->landlordWithPortfolio();

        $property = Property::factory()->create([
            'portfolio_id' => $portfolio->id,
            'dpe_date' => '2023-04-15',
        ]);

        $this->actingAs($landlord)
            ->getJson("/api/portfolios/{$portfolio->id}/properties/{$property->id}")
            ->assertStatus(200)
            ->assertJsonPath('dpe_date', '2023-04-15')
            ->assertJsonPath('dpe_expires_on', '2033-04-15');
    }

    /* ----------------------------------------------------------------------
     | Adresse affichable
     |----------------------------------------------------------------------*/

    public function test_the_full_address_skips_the_empty_parts(): void
    {
        $property = Property::factory()->make([
            'address' => '12 rue de la Roquette',
            'address_complement' => null,
            'floor' => null,
            'apartment_number' => null,
            'postal_code' => '75011',
            'city' => 'Paris',
        ]);

        // Ni virgule orpheline ni double espace là où rien n'est renseigné.
        $this->assertSame('12 rue de la Roquette, 75011 Paris', $property->full_address);
    }

    /* ----------------------------------------------------------------------
     | Filtres
     |----------------------------------------------------------------------*/

    public function test_the_new_amenities_are_filterable(): void
    {
        [$landlord, $portfolio] = $this->landlordWithPortfolio();

        Property::factory()->create(['portfolio_id' => $portfolio->id, 'has_terrace' => true]);
        Property::factory()->create(['portfolio_id' => $portfolio->id, 'has_terrace' => false]);

        $this->actingAs($landlord)
            ->getJson("/api/portfolios/{$portfolio->id}/properties?has_terrace=1")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_the_occupancy_status_is_filterable(): void
    {
        [$landlord, $portfolio] = $this->landlordWithPortfolio();

        Property::factory()->create([
            'portfolio_id' => $portfolio->id,
            'occupancy_status' => OccupancyStatus::EnTravaux->value,
        ]);
        Property::factory()->create([
            'portfolio_id' => $portfolio->id,
            'occupancy_status' => OccupancyStatus::Vacant->value,
        ]);

        $this->actingAs($landlord)
            ->getJson("/api/portfolios/{$portfolio->id}/properties?occupancy_status=en_travaux")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }
}
