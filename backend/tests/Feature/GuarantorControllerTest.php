<?php

namespace Tests\Feature;

use App\Enums\GuaranteeType;
use App\Models\Guarantor;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuarantorControllerTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Tenant} */
    private function landlordWithTenant(): array
    {
        $landlord = User::factory()->create();

        return [$landlord, Tenant::factory()->create(['user_id' => $landlord->id])];
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'guarantee_type' => GuaranteeType::CautionSolidaire->value,
            'first_name' => 'Marie',
            'last_name' => 'Dupont',
            'email' => 'marie@example.com',
            'monthly_income' => 3600,
        ], $overrides);
    }

    public function test_a_guarantor_is_attached_to_the_tenant(): void
    {
        [$landlord, $tenant] = $this->landlordWithTenant();

        $this->actingAs($landlord)
            ->postJson("/api/tenants/{$tenant->id}/guarantors", $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.display_name', 'Marie Dupont');

        $this->assertDatabaseHas('guarantors', [
            'tenant_id' => $tenant->id,
            'last_name' => 'Dupont',
        ]);
    }

    /** Deux parents se portent couramment caution pour un même étudiant. */
    public function test_a_tenant_can_have_several_guarantors(): void
    {
        [$landlord, $tenant] = $this->landlordWithTenant();

        $this->actingAs($landlord)
            ->postJson("/api/tenants/{$tenant->id}/guarantors", $this->payload(['last_name' => 'Dupont']))
            ->assertStatus(201);

        $this->actingAs($landlord)
            ->postJson("/api/tenants/{$tenant->id}/guarantors", $this->payload([
                'last_name' => 'Martin',
                'email' => 'martin@example.com',
            ]))
            ->assertStatus(201);

        $this->actingAs($landlord)
            ->getJson("/api/tenants/{$tenant->id}/guarantors")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    /** Une caution est une personne : sans nom, l'acte ne désigne personne. */
    public function test_a_personal_guarantee_requires_a_name(): void
    {
        [$landlord, $tenant] = $this->landlordWithTenant();

        $this->actingAs($landlord)
            ->postJson("/api/tenants/{$tenant->id}/guarantors", [
                'guarantee_type' => GuaranteeType::CautionSimple->value,
                'email' => 'sans-nom@example.com',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['last_name']);
    }

    /** Visale est un dispositif : ce qui l'identifie est un numéro de dossier. */
    public function test_an_institutional_guarantee_requires_a_reference(): void
    {
        [$landlord, $tenant] = $this->landlordWithTenant();

        $this->actingAs($landlord)
            ->postJson("/api/tenants/{$tenant->id}/guarantors", [
                'guarantee_type' => GuaranteeType::Visale->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contract_reference']);

        $this->actingAs($landlord)
            ->postJson("/api/tenants/{$tenant->id}/guarantors", [
                'guarantee_type' => GuaranteeType::Visale->value,
                'contract_reference' => 'VISALE-12345678',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.display_name', 'Visale (Action Logement)');
    }

    public function test_an_engagement_cannot_end_before_it_starts(): void
    {
        [$landlord, $tenant] = $this->landlordWithTenant();

        $this->actingAs($landlord)
            ->postJson("/api/tenants/{$tenant->id}/guarantors", $this->payload([
                'starts_on' => '2026-01-01',
                'ends_on' => '2025-01-01',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ends_on']);
    }

    /**
     * Un cautionnement échu ne couvre plus rien, et c'est quand l'impayé
     * survient qu'on s'en aperçoit. La fiche doit le dire d'elle-même.
     */
    public function test_an_expired_engagement_is_flagged(): void
    {
        [$landlord, $tenant] = $this->landlordWithTenant();

        $guarantor = Guarantor::factory()->expired()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($landlord)
            ->getJson("/api/tenants/{$tenant->id}/guarantors/{$guarantor->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.is_expired', true);
    }

    public function test_a_guarantor_is_updated(): void
    {
        [$landlord, $tenant] = $this->landlordWithTenant();

        $guarantor = Guarantor::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($landlord)
            ->putJson("/api/tenants/{$tenant->id}/guarantors/{$guarantor->id}", $this->payload([
                'last_name' => 'Corrige',
            ]))
            ->assertStatus(200)
            ->assertJsonPath('data.last_name', 'Corrige');
    }

    /** Suppression réversible : la prescription des loyers court sur trois ans. */
    public function test_deleting_a_guarantor_is_reversible(): void
    {
        [$landlord, $tenant] = $this->landlordWithTenant();

        $guarantor = Guarantor::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($landlord)
            ->deleteJson("/api/tenants/{$tenant->id}/guarantors/{$guarantor->id}")
            ->assertStatus(200);

        $this->assertSoftDeleted('guarantors', ['id' => $guarantor->id]);
    }

    /* ----------------------------------------------------------------------
     | Isolation
     |----------------------------------------------------------------------*/

    public function test_another_landlord_cannot_read_the_guarantors(): void
    {
        [, $tenant] = $this->landlordWithTenant();

        Guarantor::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs(User::factory()->create())
            ->getJson("/api/tenants/{$tenant->id}/guarantors")
            ->assertStatus(403);
    }

    public function test_another_landlord_cannot_add_a_guarantor(): void
    {
        [, $tenant] = $this->landlordWithTenant();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/tenants/{$tenant->id}/guarantors", $this->payload())
            ->assertStatus(403);
    }

    /** Un garant d'un autre dossier n'est pas atteignable par cette route. */
    public function test_a_guarantor_from_another_file_is_not_reachable(): void
    {
        [$landlord, $tenant] = $this->landlordWithTenant();
        [, $otherTenant] = $this->landlordWithTenant();

        $foreign = Guarantor::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->actingAs($landlord)
            ->getJson("/api/tenants/{$tenant->id}/guarantors/{$foreign->id}")
            ->assertStatus(404);
    }

    public function test_guarantors_require_authentication(): void
    {
        [, $tenant] = $this->landlordWithTenant();

        $this->getJson("/api/tenants/{$tenant->id}/guarantors")->assertStatus(401);
    }

    /* ----------------------------------------------------------------------
     | Règle des trois fois le loyer
     |----------------------------------------------------------------------*/

    public function test_the_income_rule_answers_only_for_a_person(): void
    {
        $personal = Guarantor::factory()->make(['monthly_income' => 3600]);
        $institutional = Guarantor::factory()->visale()->make();

        $this->assertTrue($personal->coversRent(1200));
        $this->assertFalse($personal->coversRent(1300));

        // La question ne se pose pas pour un organisme : il n'a pas de salaire.
        $this->assertNull($institutional->coversRent(1200));
    }
}
