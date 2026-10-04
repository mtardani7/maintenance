<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_incident_list_and_detail_are_scoped_to_owner_except_for_admin(): void
    {
        $userA = User::factory()->create(['role' => 'operator']);
        $userB = User::factory()->create(['role' => 'operator']);
        $admin = User::factory()->create(['role' => 'admin']);
        $incidentA = $this->createIncident($userA, 'User A incident');
        $incidentB = $this->createIncident($userB, 'User B incident');

        $this->actingAs($userA, 'sanctum')->getJson('/api/incidents')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $incidentA->id);
        $this->actingAs($userB, 'sanctum')->getJson('/api/incidents')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $incidentB->id);
        $this->actingAs($admin, 'sanctum')->getJson('/api/incidents')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($userA, 'sanctum')->getJson("/api/incidents/{$incidentB->id}")->assertForbidden();
        $this->actingAs($userB, 'sanctum')->getJson("/api/incidents/{$incidentA->id}")->assertForbidden();
        $this->actingAs($admin, 'sanctum')->getJson("/api/incidents/{$incidentA->id}")->assertOk();
        $this->actingAs($admin, 'sanctum')->getJson("/api/incidents/{$incidentB->id}")->assertOk();
    }

    public function test_user_cannot_update_or_delete_another_users_incident_but_admin_can(): void
    {
        $userA = User::factory()->create(['role' => 'operator']);
        $userB = User::factory()->create(['role' => 'operator']);
        $admin = User::factory()->create(['role' => 'admin']);
        $incidentA = $this->createIncident($userA, 'User A incident');
        $incidentB = $this->createIncident($userB, 'User B incident');

        $this->actingAs($userA, 'sanctum')->putJson("/api/incidents/{$incidentB->id}", [
            'status' => 'RESOLVED',
        ])->assertForbidden();
        $this->actingAs($userA, 'sanctum')->deleteJson("/api/incidents/{$incidentB->id}")->assertForbidden();

        $this->actingAs($userA, 'sanctum')->putJson("/api/incidents/{$incidentA->id}", [
            'status' => 'RESOLVED',
        ])->assertOk();
        $this->actingAs($admin, 'sanctum')->putJson("/api/incidents/{$incidentB->id}", [
            'status' => 'RESOLVED',
        ])->assertOk();
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/incidents/{$incidentB->id}")->assertNoContent();
    }

    private function createIncident(User $owner, string $description): Incident
    {
        return Incident::query()->create([
            'machine_id' => 1,
            'problem_type' => 'Machine stopped',
            'description' => $description,
            'status' => 'OPEN',
            'reported_by' => $owner->id,
        ]);
    }
}
