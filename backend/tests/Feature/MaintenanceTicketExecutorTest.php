<?php

namespace Tests\Feature;

use App\Models\MaintenanceTicket;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceTicketExecutorTest extends TestCase
{
    use RefreshDatabase;

    public function test_closing_ticket_uses_authenticated_maintenance_user_as_executor(): void
    {
        $maintenanceUser = User::factory()->create([
            'name' => 'Demo Maintenance 1',
            'role' => 'technician',
        ]);
        $spoofedUser = User::factory()->create([
            'role' => 'technician',
        ]);
        $ticket = MaintenanceTicket::query()->create([
            'ticket_number' => 'TEST-EXECUTOR-001',
            'plant_id' => 1,
            'machine_id' => 1,
            'problem_type' => 'Machine stopped',
            'description' => 'Test ticket for executor assignment.',
            'source' => 'OPERATOR',
            'status' => 'OPEN',
            'priority' => 'MEDIUM',
            'reported_by' => $maintenanceUser->id,
        ]);
        $incident = Incident::query()->create([
            'plant_id' => 1,
            'machine_id' => 1,
            'problem_type' => 'Machine stopped',
            'description' => 'Linked incident for ticket status synchronization.',
            'status' => 'OPEN',
            'reported_by' => $maintenanceUser->id,
        ]);
        $incident->maintenanceTicket()->associate($ticket);
        $incident->save();

        $operator = User::factory()->create(['role' => 'operator']);
        $this->actingAs($operator, 'sanctum')->putJson("/api/tickets/{$ticket->ticket_number}", [
            'description' => 'Operator must not edit the ticket.',
        ])->assertForbidden();
        $this->actingAs($operator, 'sanctum')->postJson("/api/tickets/{$ticket->ticket_number}/actions/close", [
            'reason' => 'No permission',
            'action_taken' => 'No permission',
            'duration_hours' => 1,
            'solution' => 'No permission',
        ])->assertForbidden();
        $this->actingAs($operator, 'sanctum')->deleteJson("/api/tickets/{$ticket->ticket_number}")
            ->assertForbidden();
        $this->actingAs($operator, 'sanctum')->postJson("/api/tickets/{$ticket->ticket_number}/spare-parts", [
            'name' => 'Bearing',
            'material_code' => 'MAT-001',
            'quantity' => 1,
        ])->assertForbidden();
        $this->actingAs($operator, 'sanctum')->deleteJson("/api/tickets/{$ticket->ticket_number}/spare-parts/999")
            ->assertForbidden();

        $response = $this->actingAs($maintenanceUser, 'sanctum')->postJson(
            "/api/tickets/{$ticket->ticket_number}/actions/close",
            [
                'reason' => 'Bearing aus',
                'action_taken' => 'Mengganti bearing',
                'executor_id' => $spoofedUser->id,
                'duration_hours' => 2,
                'solution' => 'Mesin kembali normal',
            ],
        );

        $response->assertOk()
            ->assertJsonPath('executor.id', $maintenanceUser->id)
            ->assertJsonPath('executor.name', 'Demo Maintenance 1');
        $this->assertNotEmpty($response->json('closed_at'));
        $response->assertJsonPath('closed_by.id', $maintenanceUser->id)
            ->assertJsonPath('closed_by.name', 'Demo Maintenance 1');
        $closedTicket = $ticket->fresh();
        $this->assertNotNull($closedTicket->closed_at);
        $this->assertSame($maintenanceUser->id, $closedTicket->closed_by_id);
        $this->assertSame('RESOLVED', $incident->fresh()->status);
        $this->assertDatabaseHas('maintenance_tickets', [
            'id' => $ticket->id,
            'status' => 'CLOSED',
            'executor_id' => $maintenanceUser->id,
        ]);
    }
}
