<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MachineQrFlowTest extends TestCase
{
    use RefreshDatabase;

    private function machine(User $user, string $plantCode, string $machineCode, bool $active = true): array
    {
        $plantId = DB::table('plants')->insertGetId([
            'code' => $plantCode, 'name' => "Plant {$plantCode}", 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $machineId = DB::table('machines')->insertGetId([
            'code' => $machineCode, 'name' => "Machine {$machineCode}", 'plant_id' => $plantId, 'section' => 'Test', 'is_active' => $active,
            'created_by' => $user->id, 'updated_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$plantId, $machineId];
    }

    private function payload(string $plantCode, string $machineCode): string
    {
        return 'MAINTENANCE-MACHINE|1|'.rawurlencode($plantCode).'|'.rawurlencode($machineCode);
    }

    public function test_authenticated_user_can_resolve_an_active_machine_from_plant_and_machine_codes(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $this->machine($operator, 'RX03', 'MCH-001');

        $this->actingAs($operator, 'sanctum')->postJson('/api/machines/resolve-qr', ['payload' => $this->payload('RX03', 'MCH-001')])
            ->assertOk()
            ->assertJsonPath('machine.code', 'MCH-001')
            ->assertJsonPath('machine.name', 'Machine MCH-001')
            ->assertJsonPath('plant.code', 'RX03');
    }

    public function test_invalid_unknown_and_inactive_machine_qr_return_safe_messages(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $this->machine($operator, 'RX03', 'MCH-OFF', false);
        $this->actingAs($operator, 'sanctum')->postJson('/api/machines/resolve-qr', ['payload' => 'not-a-machine-qr'])
            ->assertUnprocessable()->assertJsonPath('message', 'QR Mesin tidak valid.');
        $this->actingAs($operator, 'sanctum')->postJson('/api/machines/resolve-qr', ['payload' => $this->payload('RX03', 'MCH-MISSING')])
            ->assertNotFound()->assertJsonPath('message', 'Mesin tidak ditemukan.');
        $this->actingAs($operator, 'sanctum')->postJson('/api/machines/resolve-qr', ['payload' => $this->payload('RX03', 'MCH-OFF')])
            ->assertUnprocessable()->assertJsonPath('message', 'Mesin sedang tidak aktif dan tidak dapat digunakan untuk laporan.');
    }

    public function test_incident_submission_uses_machine_and_plant_from_qr_not_frontend_ids(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        [$scannedPlantId, $scannedMachineId] = $this->machine($operator, 'RX03', 'MCH-SCANNED');
        [$otherPlantId, $otherMachineId] = $this->machine($operator, 'RX04', 'MCH-FRONTEND');

        $response = $this->actingAs($operator, 'sanctum')->postJson('/api/incidents', [
            'plant_id' => $otherPlantId,
            'machine_id' => $otherMachineId,
            'problem_type' => 'Machine stopped',
            'status' => 'OPEN',
            'qr_payload' => $this->payload('RX03', 'MCH-SCANNED'),
        ])->assertCreated();

        $incident = Incident::findOrFail($response->json('id'));
        $this->assertSame($scannedMachineId, $incident->machine_id);
        $this->assertSame($scannedPlantId, $incident->plant_id);
    }

    public function test_inactive_machine_qr_cannot_be_used_to_submit_an_incident(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        [$plantId, $machineId] = $this->machine($operator, 'RX03', 'MCH-OFF', false);

        $this->actingAs($operator, 'sanctum')->postJson('/api/incidents', [
            'plant_id' => $plantId,
            'machine_id' => $machineId,
            'problem_type' => 'Machine stopped',
            'status' => 'OPEN',
            'qr_payload' => $this->payload('RX03', 'MCH-OFF'),
        ])->assertUnprocessable()->assertJsonPath('message', 'Mesin sedang tidak aktif dan tidak dapat digunakan untuk laporan.');

        $this->assertSame(0, Incident::query()->count());
    }
}
