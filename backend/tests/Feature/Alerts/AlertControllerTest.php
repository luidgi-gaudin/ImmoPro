<?php

namespace Tests\Feature\Alerts;

use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── Listing & isolation ─────────────────────────────────────────────────

    public function test_index_returns_only_the_authenticated_users_alerts(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Alert::factory()->count(2)->create(['user_id' => $user->id]);
        Alert::factory()->create(['user_id' => $other->id]);

        $response = $this->actingAs($user)->getJson('/api/alerts');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_index_hides_resolved_alerts_by_default(): void
    {
        $user = User::factory()->create();
        Alert::factory()->create(['user_id' => $user->id]);
        Alert::factory()->create(['user_id' => $user->id, 'resolved_at' => now()]);

        $this->actingAs($user)->getJson('/api/alerts')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($user)->getJson('/api/alerts?resolved=1')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_user_cannot_read_another_users_alert(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $alert = Alert::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($intruder)->postJson("/api/alerts/{$alert->id}/read")->assertForbidden();
        $this->assertNull($alert->refresh()->read_at);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/alerts')->assertUnauthorized();
    }

    // ── Actions ─────────────────────────────────────────────────────────────

    public function test_mark_as_read_and_mark_all_as_read(): void
    {
        $user = User::factory()->create();
        $alerts = Alert::factory()->count(3)->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson("/api/alerts/{$alerts[0]->id}/read")->assertOk();
        $this->assertNotNull($alerts[0]->refresh()->read_at);

        $this->actingAs($user)->postJson('/api/alerts/read-all')->assertOk();
        $this->assertSame(0, Alert::forUser($user)->unread()->count());
    }

    public function test_resolve_marks_the_alert_resolved(): void
    {
        $user = User::factory()->create();
        $alert = Alert::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson("/api/alerts/{$alert->id}/resolve")->assertOk();

        $this->assertNotNull($alert->refresh()->resolved_at);
    }

    public function test_remind_only_applies_to_unpaid_rent_alerts(): void
    {
        $user = User::factory()->create();
        $impaye = Alert::factory()->create(['user_id' => $user->id, 'type' => AlertType::LoyerImpaye->value]);
        $other = Alert::factory()->create(['user_id' => $user->id, 'type' => AlertType::FinBail->value]);

        $this->actingAs($user)->postJson("/api/alerts/{$impaye->id}/remind")->assertOk();
        $this->assertNotNull($impaye->refresh()->reminded_at);

        $this->actingAs($user)->postJson("/api/alerts/{$other->id}/remind")->assertStatus(422);
        $this->assertNull($other->refresh()->reminded_at);
    }
}
