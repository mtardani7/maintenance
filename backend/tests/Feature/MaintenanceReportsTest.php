<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\MaintenanceTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MaintenanceReportsTest extends TestCase
{
    use RefreshDatabase;

    private function createLocations(): array
    {
        $plant = DB::table('plants')->insertGetId(['code' => 'PLT-1', 'name' => 'Plant Satu', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $machine = DB::table('machines')->insertGetId(['plant_id' => $plant, 'code' => 'MC-1', 'name' => 'Mesin Satu', 'section' => 'Line A', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return [$plant, $machine];
    }

    public function test_summary_distinguishes_created_open_and_closed_ticket_periods_and_filters_by_machine(): void
    {
        [$plant, $machine] = $this->createLocations();
        $manager = User::factory()->create(['role' => 'supervisor']);
        $ticket = MaintenanceTicket::query()->create([
            'ticket_number' => 'T-NEW', 'plant_id' => $plant, 'machine_id' => $machine, 'problem_type' => 'Vibration',
            'description' => 'Ticket dibuat minggu ini', 'source' => 'OPERATOR', 'status' => 'OPEN', 'priority' => 'MEDIUM', 'reported_by' => $manager->id,
            'created_at' => '2026-10-20 10:00:00', 'updated_at' => '2026-10-20 10:00:00',
        ]);
        MaintenanceTicket::query()->create([
            'ticket_number' => 'T-OLD-OPEN', 'plant_id' => $plant, 'machine_id' => $machine, 'problem_type' => 'Bearing',
            'description' => 'Tiket lama masih terbuka', 'source' => 'OPERATOR', 'status' => 'OPEN', 'priority' => 'LOW', 'reported_by' => $manager->id,
            'created_at' => '2026-09-01 10:00:00', 'updated_at' => '2026-09-01 10:00:00',
        ]);
        DB::table('maintenance_tickets')->where('ticket_number', 'T-OLD-OPEN')->update(['created_at' => '2026-09-01 10:00:00']);
        MaintenanceTicket::query()->create([
            'ticket_number' => 'T-CLOSED', 'plant_id' => $plant, 'machine_id' => $machine, 'problem_type' => 'Motor',
            'description' => 'Tiket lama selesai di periode', 'source' => 'OPERATOR', 'status' => 'CLOSED', 'priority' => 'LOW', 'reported_by' => $manager->id,
            'created_at' => '2026-09-01 10:00:00', 'closed_at' => '2026-10-21 12:00:00', 'updated_at' => '2026-10-21 12:00:00',
        ]);
        DB::table('maintenance_tickets')->where('ticket_number', 'T-CLOSED')->update(['created_at' => '2026-09-01 10:00:00']);
        Incident::query()->create([
            'plant_id' => $plant, 'machine_id' => $machine, 'problem_type' => 'Vibration', 'description' => 'Insiden periode ini',
            'status' => 'OPEN', 'reported_by' => $manager->id, 'maintenance_ticket_id' => $ticket->id,
            'created_at' => '2026-10-20 10:00:00', 'updated_at' => '2026-10-20 10:00:00',
        ]);
        $outsideIncident = Incident::query()->create([
            'plant_id' => $plant, 'machine_id' => $machine, 'problem_type' => 'Old', 'description' => 'Insiden sebelum periode',
            'status' => 'RESOLVED', 'reported_by' => $manager->id,
        ]);
        DB::table('incidents')->where('id', $outsideIncident->id)->update(['created_at' => '2026-09-30 16:59:59']);

        $this->actingAs($manager, 'sanctum')->getJson('/api/reports/metrics?start_date=2026-10-01&end_date=2026-10-22&plant_id='.$plant.'&machine_id='.$machine.'&category=summary')
            ->assertOk()
            ->assertJsonPath('summary.tickets_created', 1)
            ->assertJsonPath('summary.tickets_open_now', 2)
            ->assertJsonPath('summary.tickets_closed', 1)
            ->assertJsonPath('summary.incidents', 1)
            ->assertJsonPath('downtime.available', false)
            ->assertJsonPath('trend_granularity', 'day');
    }

    public function test_report_requires_manager_role_and_valid_date_range(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $this->actingAs($operator, 'sanctum')->getJson('/api/reports/metrics')->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum')->getJson('/api/reports/metrics?start_date=2026-10-22&end_date=2026-10-01')->assertUnprocessable();
    }

    public function test_filtered_ticket_export_is_excel_and_only_contains_matching_rows(): void
    {
        [$plant, $machine] = $this->createLocations();
        $admin = User::factory()->create(['role' => 'admin']);
        MaintenanceTicket::query()->create([
            'ticket_number' => 'T-CSV', 'plant_id' => $plant, 'machine_id' => $machine, 'problem_type' => 'Vibration',
            'description' => 'CSV filter test', 'source' => 'OPERATOR', 'status' => 'OPEN', 'priority' => 'MEDIUM', 'reported_by' => $admin->id,
            'created_at' => '2026-10-20 10:00:00', 'updated_at' => '2026-10-20 10:00:00',
        ]);
        $response = $this->actingAs($admin, 'sanctum')->get('/api/reports/export?start_date=2026-10-01&end_date=2026-10-22&plant_id='.$plant.'&category=tickets');
        $response->assertOk();
        $this->assertStringContainsString('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('content-type'));
        $sheet = $this->exportSheet($response->streamedContent());
        $this->assertStringContainsString('T-CSV', $sheet);
        $this->assertStringNotContainsString('T-OTHER', $sheet);
    }

    public function test_report_categories_return_paginated_machine_incident_and_spare_part_data(): void
    {
        [$plant, $machine] = $this->createLocations();
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = MaintenanceTicket::query()->create([
            'ticket_number' => 'T-DETAIL', 'plant_id' => $plant, 'machine_id' => $machine, 'problem_type' => 'Sensor',
            'description' => 'Detail report test', 'source' => 'OPERATOR', 'status' => 'OPEN', 'priority' => 'MEDIUM', 'reported_by' => $admin->id,
        ]);
        Incident::query()->create([
            'plant_id' => $plant, 'machine_id' => $machine, 'problem_type' => 'Sensor', 'description' => 'Sensor issue',
            'status' => 'OPEN', 'reported_by' => $admin->id, 'maintenance_ticket_id' => $ticket->id,
        ]);
        DB::table('maintenance_ticket_spare_parts')->insert([
            'maintenance_ticket_id' => $ticket->id, 'name' => 'Sensor unit', 'material_code' => 'SNS-1', 'quantity' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/reports/metrics?start_date=2026-10-01&end_date=2026-10-22&category=machines')
            ->assertOk()->assertJsonPath('machines.total', 1)->assertJsonPath('machines.data.0.incident_count', 1);
        $this->getJson('/api/reports/metrics?start_date=2026-10-01&end_date=2026-10-22&category=incidents')
            ->assertOk()->assertJsonPath('rows.total', 1)->assertJsonPath('incident_types.0.problem_type', 'Sensor');
        $this->getJson('/api/reports/metrics?start_date=2026-10-01&end_date=2026-10-22&category=spare-parts')
            ->assertOk()->assertJsonPath('spare_parts.transactions', 1)->assertJsonPath('spare_parts.quantity', 2)->assertJsonPath('rows.data.0.quantity', 2);
        $machineExport = $this->get('/api/reports/export?start_date=2026-10-01&end_date=2026-10-22&category=machines');
        $machineExport->assertOk();
        $this->assertStringContainsString('MC-1', $this->exportSheet($machineExport->streamedContent()));
        $spareExport = $this->get('/api/reports/export?start_date=2026-10-01&end_date=2026-10-22&category=spare-parts');
        $spareExport->assertOk();
        $this->assertStringContainsString('SNS-1', $this->exportSheet($spareExport->streamedContent()));
    }

    private function exportSheet(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'report-test-');
        file_put_contents($path, $content);
        try {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path) === true);
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();

            return $sheet;
        } finally {
            @unlink($path);
        }
    }

    public function test_date_filter_uses_indonesia_day_boundaries_for_utc_timestamps(): void
    {
        [$plant, $machine] = $this->createLocations();
        $admin = User::factory()->create(['role' => 'admin']);
        foreach ([['T-BEFORE', '2026-09-30 16:59:59'], ['T-START', '2026-09-30 17:00:00'], ['T-END', '2026-10-22 16:59:59'], ['T-AFTER', '2026-10-22 17:00:00']] as [$number, $createdAt]) {
            $ticket = MaintenanceTicket::query()->create([
                'ticket_number' => $number, 'plant_id' => $plant, 'machine_id' => $machine, 'problem_type' => 'Boundary',
                'description' => 'Boundary test', 'source' => 'OPERATOR', 'status' => 'OPEN', 'priority' => 'LOW', 'reported_by' => $admin->id,
            ]);
            DB::table('maintenance_tickets')->where('id', $ticket->id)->update(['created_at' => $createdAt]);
        }
        $this->actingAs($admin, 'sanctum')->getJson('/api/reports/metrics?start_date=2026-10-01&end_date=2026-10-22')
            ->assertOk()->assertJsonPath('summary.tickets_created', 2);
    }
}
