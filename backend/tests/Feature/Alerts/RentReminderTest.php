<?php

namespace Tests\Feature\Alerts;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\NotificationTopic;
use App\Models\Alert;
use App\Models\Lease;
use App\Models\Portfolio;
use App\Models\Property;
use App\Models\RentPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantMessageNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Relance d'un loyer impayé.
 *
 * Cette route se contentait d'horodater. « Relancé le 12 septembre » sans que
 * rien ne parte est pire qu'un bouton absent : le bailleur croit avoir agi, le
 * locataire n'a rien reçu, et le litige se construit sur cette croyance.
 */
class RentReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    /** @return array{landlord: User, tenant: Tenant, alert: Alert, payment: RentPayment} */
    private function scene(array $tenantAttributes = []): array
    {
        $landlord = User::factory()->create();
        $portfolio = Portfolio::factory()->create(['user_id' => $landlord->id]);
        $property = Property::factory()->create(['portfolio_id' => $portfolio->id]);

        $tenant = Tenant::factory()->create(array_merge([
            'user_id' => $landlord->id,
            'email' => 'lea@example.com',
        ], $tenantAttributes));

        $lease = Lease::factory()->create([
            'property_id' => $property->id,
            'tenant_id' => $tenant->id,
        ]);

        $payment = RentPayment::factory()->create([
            'lease_id' => $lease->id,
            'paid_at' => null,
        ]);

        $alert = Alert::factory()->create([
            'user_id' => $landlord->id,
            'type' => AlertType::LoyerImpaye->value,
            'severity' => AlertSeverity::Critical->value,
            'alertable_type' => RentPayment::class,
            'alertable_id' => $payment->id,
            'message' => 'Le loyer de septembre 2026 n\'a pas été enregistré.',
        ]);

        return compact('landlord', 'tenant', 'alert', 'payment');
    }

    public function test_a_tenant_with_an_account_is_notified(): void
    {
        $account = User::factory()->tenant()->create();
        $scene = $this->scene(['account_user_id' => $account->id]);

        $this->actingAs($scene['landlord'])
            ->postJson("/api/alerts/{$scene['alert']->id}/remind")
            ->assertStatus(200)
            ->assertJsonPath('channels', ['mail', 'database']);

        Notification::assertSentTo($account, TenantMessageNotification::class);
    }

    /** Le locataire retrouve la relance dans sa cloche. */
    public function test_the_reminder_lands_in_the_tenant_bell(): void
    {
        $account = User::factory()->tenant()->create();
        $scene = $this->scene(['account_user_id' => $account->id]);

        $this->actingAs($scene['landlord'])
            ->postJson("/api/alerts/{$scene['alert']->id}/remind")
            ->assertStatus(200);

        $this->assertDatabaseHas('alerts', [
            'user_id' => $account->id,
            'type' => AlertType::RelanceLoyer->value,
        ]);

        $this->actingAs($account)
            ->getJson('/api/alerts')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', AlertType::RelanceLoyer->value);
    }

    /** Trois clics ne remplissent pas la cloche de trois messages identiques. */
    public function test_clicking_again_the_same_day_does_not_pile_up(): void
    {
        $account = User::factory()->tenant()->create();
        $scene = $this->scene(['account_user_id' => $account->id]);

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($scene['landlord'])
                ->postJson("/api/alerts/{$scene['alert']->id}/remind")
                ->assertStatus(200);
        }

        $this->assertSame(
            1,
            Alert::where('user_id', $account->id)->where('type', AlertType::RelanceLoyer->value)->count()
        );
    }

    /** Les préférences du locataire commandent le canal. */
    public function test_a_tenant_who_switched_off_mail_only_gets_the_bell(): void
    {
        $account = User::factory()->tenant()->create();

        $account->forceFill([
            'notification_preferences' => [
                NotificationTopic::RelanceLoyer->value => ['database'],
            ],
        ])->save();

        $scene = $this->scene(['account_user_id' => $account->id]);

        $this->actingAs($scene['landlord'])
            ->postJson("/api/alerts/{$scene['alert']->id}/remind")
            ->assertStatus(200)
            ->assertJsonPath('channels', ['database']);

        Notification::assertNothingSentTo($account);
    }

    public function test_a_tenant_who_switched_everything_off_receives_nothing(): void
    {
        $account = User::factory()->tenant()->create();

        $account->forceFill([
            'notification_preferences' => [NotificationTopic::RelanceLoyer->value => []],
        ])->save();

        $scene = $this->scene(['account_user_id' => $account->id]);

        $this->actingAs($scene['landlord'])
            ->postJson("/api/alerts/{$scene['alert']->id}/remind")
            ->assertStatus(200)
            ->assertJsonPath('channels', []);

        Notification::assertNothingSentTo($account);
    }

    /**
     * La grande majorité des dossiers se gèrent sans que le locataire se
     * connecte jamais : le courriel part alors sur l'adresse du dossier.
     */
    public function test_a_tenant_without_an_account_still_gets_an_email(): void
    {
        $scene = $this->scene();

        $this->actingAs($scene['landlord'])
            ->postJson("/api/alerts/{$scene['alert']->id}/remind")
            ->assertStatus(200)
            ->assertJsonPath('channels', ['mail']);

        Notification::assertSentOnDemand(TenantMessageNotification::class);
    }

    /**
     * Ni adresse ni compte : la relance est notée, et la réponse le dit. Taire
     * l'échec laisserait croire qu'un message est parti.
     */
    public function test_an_unreachable_tenant_is_reported(): void
    {
        $scene = $this->scene(['email' => null]);

        $response = $this->actingAs($scene['landlord'])
            ->postJson("/api/alerts/{$scene['alert']->id}/remind")
            ->assertStatus(200)
            ->assertJsonPath('channels', []);

        $this->assertStringContainsString('aucun message', $response->json('message'));
        $this->assertNotNull($scene['alert']->refresh()->reminded_at);

        Notification::assertNothingSent();
    }

    public function test_another_landlord_cannot_remind(): void
    {
        $scene = $this->scene();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/alerts/{$scene['alert']->id}/remind")
            ->assertStatus(403);

        Notification::assertNothingSent();
    }

    /** Une adresse non vérifiée ne reçoit rien, quelle que soit la préférence. */
    public function test_an_unverified_account_gets_the_bell_only(): void
    {
        $account = User::factory()->tenant()->unverified()->create();
        $scene = $this->scene(['account_user_id' => $account->id]);

        $this->actingAs($scene['landlord'])
            ->postJson("/api/alerts/{$scene['alert']->id}/remind")
            ->assertStatus(200)
            ->assertJsonPath('channels', ['database']);

        Notification::assertNothingSentTo($account);
    }
}
