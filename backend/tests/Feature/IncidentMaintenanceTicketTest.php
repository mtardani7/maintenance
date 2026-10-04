<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IncidentMaintenanceTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_incident_returns_and_persists_its_created_ticket_number(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $plantId = DB::table('plants')->insertGetId([
            'code' => 'RX03',
            'name' => 'Plant Test',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $machineId = DB::table('machines')->insertGetId([
            'code' => 'M01',
            'name' => 'Machine Test',
            'plant_id' => $plantId,
            'section' => 'Test',
            'is_active' => true,
            'created_by' => $operator->id,
            'updated_by' => $operator->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($operator, 'sanctum')->postJson('/api/incidents', [
            'plant_id' => $plantId,
            'machine_id' => $machineId,
            'problem_type' => 'Machine stopped',
            'status' => 'OPEN',
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'OPEN');
        $ticketNumber = $response->json('ticket_number');
        $this->assertIsString($ticketNumber);
        $this->assertNotEmpty($ticketNumber);

        $incident = Incident::query()->with('maintenanceTicket')->findOrFail($response->json('id'));
        $this->assertNotNull($incident->maintenance_ticket_id);
        $this->assertSame($ticketNumber, $incident->maintenanceTicket->ticket_number);
        $this->actingAs($operator, 'sanctum')->getJson('/api/incidents?per_page=100')
            ->assertOk()
            ->assertJsonPath('data.0.ticket_number', $ticketNumber);
        $this->actingAs($operator, 'sanctum')->getJson("/api/tickets/{$ticketNumber}")
            ->assertOk()
            ->assertJsonPath('ticket_number', $ticketNumber);

        $this->actingAs($operator, 'sanctum')->postJson('/api/incidents', [
            'plant_id' => $plantId,
            'machine_id' => $machineId,
            'problem_type' => 'Abnormal sound',
            'status' => 'RESOLVED',
            'action_taken' => 'Operator cleaned the affected area.',
            'result' => 'Machine is running normally now.',
        ])->assertCreated()->assertJsonPath('ticket_number', null);
    }
}
