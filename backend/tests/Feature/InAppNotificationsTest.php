<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\User;
use App\Events\TicketCreated;
use App\Events\TicketClosed;
use App\Notifications\MaintenanceTicketClosedNotification;
use App\Notifications\MaintenanceTicketCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InAppNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function createIncident(User $operator): array
    {
        $plantId = DB::table('plants')->insertGetId([
            'code' => 'RX03', 'name' => 'Plant Test', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $machineId = DB::table('machines')->insertGetId([
            'code' => 'M01', 'name' => 'Machine Test', 'plant_id' => $plantId, 'section' => 'Test', 'is_active' => true,
            'created_by' => $operator->id, 'updated_by' => $operator->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($operator, 'sanctum')->postJson('/api/incidents', [
            'plant_id' => $plantId, 'machine_id' => $machineId, 'problem_type' => 'Machine stopped', 'status' => 'OPEN',
        ])->assertCreated();

        return [$response->json('id'), $response->json('ticket_number')];
    }

    public function test_new_ticket_notification_is_sent_to_maintenance_only_and_is_deduplicated(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $otherOperator = User::factory()->create(['role' => 'operator']);
        $maintenance = User::factory()->create(['role' => 'technician']);
        $inactiveMaintenance = User::factory()->create(['role' => 'technician', 'is_active' => false]);
        [$incidentId, $ticketNumber] = $this->createIncident($operator);

        $incident = Incident::query()->with('maintenanceTicket')->findOrFail($incidentId);
        event(new TicketCreated($incident->maintenanceTicket->load('machine'), $incident));

        $this->assertSame(1, $maintenance->notifications()->where('type', MaintenanceTicketCreatedNotification::class)->count());
        $this->assertSame(0, $otherOperator->notifications()->count());
        $this->assertSame(0, $inactiveMaintenance->notifications()->count());

        $maintenance->notifications()->first()->markAsRead();
        $this->actingAs($maintenance, 'sanctum')->getJson('/api/notifications')
            ->assertOk()->assertJsonPath('unread_count', 0)->assertJsonPath('data.0.ticketNumber', $ticketNumber);
        $this->actingAs($otherOperator, 'sanctum')->getJson('/api/notifications')->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($maintenance, 'sanctum')->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('count', 0);
        $this->actingAs($maintenance, 'sanctum')->patchJson('/api/notifications/read-all')->assertOk();
        $this->assertSame(0, $operator->notifications()->where('type', MaintenanceTicketCreatedNotification::class)->count());
        $this->assertSame('OPEN', Incident::findOrFail($incidentId)->status);
    }

    public function test_closing_ticket_resolves_incident_and_notifies_only_its_operator_once(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $otherOperator = User::factory()->create(['role' => 'operator']);
        $maintenance = User::factory()->create(['role' => 'technician']);
        [$incidentId, $ticketNumber] = $this->createIncident($operator);

        $this->actingAs($maintenance, 'sanctum')->postJson("/api/tickets/{$ticketNumber}/actions/close", [
            'reason' => 'A failed sensor caused a stop.',
            'action_taken' => 'Replaced and tested the sensor.',
            'duration_hours' => 1.5,
            'solution' => 'Machine operation restored successfully.',
        ])->assertOk()->assertJsonPath('status', 'CLOSED');

        $this->assertSame('RESOLVED', Incident::findOrFail($incidentId)->status);
        $this->assertSame(1, $operator->notifications()->where('type', MaintenanceTicketClosedNotification::class)->count());
        $this->assertSame(0, $otherOperator->notifications()->count());
        $notification = $operator->notifications()->where('type', MaintenanceTicketClosedNotification::class)->firstOrFail();
        $this->assertSame($ticketNumber, $notification->data['ticket_number']);
        $this->assertSame($maintenance->name, $notification->data['closed_by']);
        $this->assertNotEmpty($notification->data['closed_at']);
        $closedTicket = Incident::query()->with('maintenanceTicket')->findOrFail($incidentId)->maintenanceTicket->fresh(['machine', 'closedBy']);
        event(new TicketClosed($closedTicket));
        $this->assertSame(1, $operator->notifications()->where('type', MaintenanceTicketClosedNotification::class)->count());

        $this->actingAs($maintenance, 'sanctum')->postJson("/api/tickets/{$ticketNumber}/actions/close", [
            'reason' => 'Trying again.', 'action_taken' => 'Retry close.', 'duration_hours' => 1, 'solution' => 'Retry.',
        ])->assertUnprocessable();
        $this->assertSame(1, $operator->notifications()->where('type', MaintenanceTicketClosedNotification::class)->count());

        $this->actingAs($operator, 'sanctum')->getJson('/api/notifications')->assertOk()->assertJsonPath('data.0.type', 'ticket_closed');
        $this->actingAs($operator, 'sanctum')->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('count', 1);
    }

    public function test_a_user_cannot_mark_another_users_notification_as_read(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $maintenance = User::factory()->create(['role' => 'technician']);
        [, $ticketNumber] = $this->createIncident($operator);
        $notificationId = $maintenance->notifications()->firstOrFail()->id;

        $this->actingAs($operator, 'sanctum')->patchJson("/api/notifications/{$notificationId}/read")->assertNotFound();
        $this->assertNull($maintenance->notifications()->findOrFail($notificationId)->read_at);
        $this->assertNotEmpty($ticketNumber);
    }

    public function test_assigned_maintenance_user_is_the_only_recipient_when_assignment_exists(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $assigned = User::factory()->create(['role' => 'technician']);
        $otherMaintenance = User::factory()->create(['role' => 'technician']);
        [$incidentId] = $this->createIncident($operator);
        $incident = Incident::query()->with('maintenanceTicket')->findOrFail($incidentId);
        $ticket = $incident->maintenanceTicket;

        User::query()->whereIn('id', [$assigned->id, $otherMaintenance->id])->get()->each(fn (User $user) => $user->notifications()->delete());
        $ticket->update(['action_by_id' => $assigned->id]);
        event(new TicketCreated($ticket->fresh('machine'), $incident));

        $this->assertSame(1, $assigned->notifications()->where('type', MaintenanceTicketCreatedNotification::class)->count());
        $this->assertSame(0, $otherMaintenance->notifications()->where('type', MaintenanceTicketCreatedNotification::class)->count());
    }
}
