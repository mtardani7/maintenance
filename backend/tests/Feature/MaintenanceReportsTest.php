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
            ->assertJsonPath('downtime.available', true)
            ->assertJsonPath('trend_granularity', 'day');
    }

    public function test_report_requires_manager_role_and_valid_date_range(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $this->actingAs($operator, 'sanctum')->getJson('/api/reports/metrics')->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum')->getJson('/api/reports/metrics?start_date=2026-10-22&end_date=2026-10-01')->assertUnprocessable();
    }

    public function test_downtime_uses_positive_closed_work_detail_duration_and_applies_period_plant_and_machine_filters(): void
    {
        [$plantOne, $machineOne] = $this->createLocations();
        $plantTwo = DB::table('plants')->insertGetId(['code' => 'PLT-2', 'name' => 'Plant Dua', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $machineTwo = DB::table('machines')->insertGetId(['plant_id' => $plantTwo, 'code' => 'MC-2', 'name' => 'Mesin Dua', 'section' => 'Line B', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $admin = User::factory()->create(['role' => 'admin']);
        $createTicket = function (string $number, int $plant, int $machine, string $status, ?float $hours, ?string $closedAt) use ($admin): void {
            MaintenanceTicket::query()->create([
                'ticket_number' => $number, 'plant_id' => $plant, 'machine_id' => $machine, 'problem_type' => 'Repair',
                'description' => 'Work detail duration test', 'source' => 'OPERATOR', 'status' => $status, 'priority' => 'MEDIUM',
                'reported_by' => $admin->id, 'executor_id' => $admin->id, 'reason' => 'Bearing failure', 'action_taken' => 'Bearing replaced',
                'solution' => 'Machine running', 'duration_hours' => $hours, 'closed_at' => $closedAt,
            ]);
        };
        $createTicket('DT-1', $plantOne, $machineOne, 'CLOSED', 2.5, '2026-10-10 10:00:00');
        $createTicket('DT-2', $plantOne, $machineOne, 'CLOSED', 1, '2026-10-20 10:00:00');
        $createTicket('DT-3', $plantTwo, $machineTwo, 'CLOSED', 4, '2026-10-21 10:00:00');
        $createTicket('DT-OUT', $plantOne, $machineOne, 'CLOSED', 8, '2026-09-29 10:00:00');
        $createTicket('DT-OPEN', $plantOne, $machineOne, 'OPEN', 6, '2026-10-11 10:00:00');
        $createTicket('DT-ZERO', $plantOne, $machineOne, 'CLOSED', 0, '2026-10-12 10:00:00');

        $base = '/api/reports/metrics?start_date=2026-10-01&end_date=2026-10-22&category=downtime';
        $response = $this->actingAs($admin, 'sanctum')->getJson($base)->assertOk();
        $response->assertJsonPath('downtime.total_hours', 7.5)
            ->assertJsonPath('downtime.work_count', 3)
            ->assertJsonPath('downtime.average_hours', 2.5)
            ->assertJsonPath('downtime.top_machine.code', 'MC-2')
            ->assertJsonPath('downtime.by_plant.0.name', 'Plant Dua')
            ->assertJsonPath('rows.total', 3)
            ->assertJsonPath('rows.data.2.work_start', '2026-10-10T07:30:00.000000Z')
            ->assertJsonPath('rows.data.2.work_end', '2026-10-10T10:00:00.000000Z');

        $this->getJson($base.'&plant_id='.$plantOne)->assertOk()
            ->assertJsonPath('downtime.total_hours', 3.5)->assertJsonPath('downtime.work_count', 2)->assertJsonPath('downtime.average_hours', 1.75);
        $this->getJson($base.'&machine_id='.$machineTwo)->assertOk()
            ->assertJsonPath('downtime.total_hours', 4)->assertJsonPath('downtime.work_count', 1);
        $this->getJson('/api/reports/metrics?start_date=2026-10-01&end_date=2026-10-15&category=downtime')->assertOk()
            ->assertJsonPath('downtime.total_hours', 2.5)->assertJsonPath('downtime.work_count', 1);

        $export = $this->get('/api/reports/export?start_date=2026-10-01&end_date=2026-10-22&category=downtime&page=2&per_page=5')->assertOk();
        $content = $export->streamedContent();
        $this->assertWorkbookPackage($content);
        $workbook = $this->exportEntry($content, 'xl/workbook.xml');
        foreach (['Ringkasan', 'Tiket Maintenance', 'Insiden', 'Mesin &amp; Plant', 'Spare Part', 'Downtime'] as $sheetName) {
            $this->assertStringContainsString('name="'.$sheetName.'"', $workbook);
        }
        $sheet = $this->exportSheet($content, 6);
        $this->assertStringContainsString('DT-1', $sheet);
        $this->assertStringContainsString('DT-2', $sheet);
        $this->assertStringContainsString('DT-3', $sheet);
        $this->assertStringContainsString('Bearing failure', $sheet);
        $this->assertStringContainsString('Bearing replaced', $sheet);
        $this->assertStringContainsString('Mulai downtime', $sheet);
        $this->assertStringContainsString('Selesai downtime', $sheet);
        $this->assertStringNotContainsString('DT-OPEN', $sheet);
        $this->assertStringNotContainsString('DT-OUT', $sheet);

        $filteredExport = $this->get('/api/reports/export?start_date=2026-10-01&end_date=2026-10-22&plant_id='.$plantOne.'&machine_id='.$machineOne.'&category=downtime')->assertOk();
        $filteredSheet = $this->exportSheet($filteredExport->streamedContent(), 6);
        $this->assertStringContainsString('DT-1', $filteredSheet);
        $this->assertStringContainsString('DT-2', $filteredSheet);
        $this->assertStringNotContainsString('DT-3', $filteredSheet);
    }

    public function test_admin_can_correct_actual_downtime_and_reopen_without_losing_close_history(): void
    {
        [$plant, $machine] = $this->createLocations();
        $admin = User::factory()->create(['role' => 'admin']);
        $technician = User::factory()->create(['role' => 'technician']);
        $ticket = MaintenanceTicket::query()->create([
            'ticket_number' => 'DT-REOPEN', 'plant_id' => $plant, 'machine_id' => $machine, 'problem_type' => 'Repair',
            'description' => 'Reopen and time correction test', 'source' => 'OPERATOR', 'status' => 'CLOSED', 'priority' => 'MEDIUM',
            'reported_by' => $admin->id, 'executor_id' => $technician->id, 'reason' => 'Old reason', 'action_taken' => 'Old action',
            'solution' => 'Old solution', 'duration_hours' => 2, 'closed_at' => '2026-10-10 12:00:00', 'closed_by_id' => $technician->id,
        ]);

        $this->actingAs($technician, 'sanctum')->postJson('/api/tickets/DT-REOPEN/reopen', ['reason' => 'Correction requested'])->assertForbidden();
        $this->actingAs($admin, 'sanctum')->postJson('/api/tickets/DT-REOPEN/reopen', ['reason' => 'Work Detail needs correction'])
            ->assertOk()->assertJsonPath('status', 'OPEN')->assertJsonPath('closed_at', '2026-10-10T12:00:00.000000Z');
        $this->postJson('/api/tickets/DT-REOPEN/reopen', ['reason' => 'Already open'])->assertUnprocessable();

        $this->putJson('/api/tickets/DT-REOPEN/downtime-times', [
            'actual_downtime_started_at' => '2026-10-10T08:00:00+07:00',
            'actual_downtime_ended_at' => '2026-10-10T10:30:00+07:00',
            'reason' => 'Verified from operator log',
        ])->assertOk()->assertJsonPath('duration_hours', 2.5)
            ->assertJsonPath('actual_downtime_started_at', '2026-10-10T01:00:00.000000Z')
            ->assertJsonPath('actual_downtime_ended_at', '2026-10-10T03:30:00.000000Z')
            ->assertJsonPath('audits.0.action', 'DOWNTIME_TIME_CORRECTED')
            ->assertJsonPath('audits.0.reason', 'Verified from operator log');

        $this->putJson('/api/tickets/DT-REOPEN/downtime-times', [
            'actual_downtime_started_at' => '2026-10-10T11:00:00+07:00',
            'actual_downtime_ended_at' => '2026-10-10T10:30:00+07:00',
            'reason' => 'Invalid range',
        ])->assertUnprocessable();

        $this->actingAs($technician, 'sanctum')->putJson('/api/tickets/DT-REOPEN/downtime-times', [
            'actual_downtime_started_at' => '2026-10-10T08:00:00+07:00',
            'actual_downtime_ended_at' => '2026-10-10T10:30:00+07:00',
            'reason' => 'Technician must not edit actual timestamps',
        ])->assertForbidden();
        $this->postJson('/api/tickets/DT-REOPEN/actions/close', [])->assertUnprocessable();
        $this->actingAs($technician, 'sanctum')->postJson('/api/tickets/DT-REOPEN/actions/close', [
            'reason' => 'Updated cause', 'action_taken' => 'Updated action', 'duration_hours' => 4, 'solution' => 'Updated solution',
        ])->assertOk()->assertJsonPath('status', 'CLOSED')->assertJsonPath('duration_hours', 2.5);

        $closed = MaintenanceTicket::query()->where('ticket_number', 'DT-REOPEN')->firstOrFail();
        $this->assertSame(3, $closed->audits()->count());
        $this->assertSame('2026-10-10T12:00:00.000000Z', $closed->audits()->where('action', 'REOPEN')->firstOrFail()->before_values['closed_at']);
        $this->actingAs($admin, 'sanctum')->getJson('/api/reports/metrics?start_date=2026-10-10&end_date=2026-10-10&category=downtime')
            ->assertOk()->assertJsonPath('downtime.total_hours', 2.5)->assertJsonPath('rows.data.0.work_start', '2026-10-10T01:00:00.000000Z');
        $this->getJson('/api/tickets/DT-REOPEN')->assertOk()->assertJsonPath('duration_hours', 2.5)
            ->assertJsonPath('actual_downtime_started_at', '2026-10-10T01:00:00.000000Z')
            ->assertJsonPath('actual_downtime_ended_at', '2026-10-10T03:30:00.000000Z');
        $export = $this->get('/api/reports/export?start_date=2026-10-10&end_date=2026-10-10&category=downtime')->assertOk();
        $downtimeSheet = $this->exportSheet($export->streamedContent(), 6);
        $this->assertStringContainsString('DT-REOPEN', $downtimeSheet);
        $this->assertStringContainsString('AKTUAL', $downtimeSheet);
        $this->assertStringContainsString('Mulai downtime', $downtimeSheet);
        $this->assertStringContainsString('Selesai downtime', $downtimeSheet);
        $this->assertStringContainsString('<v>2.5</v>', $downtimeSheet);
    }

    public function test_incomplete_actual_timestamps_are_not_counted_as_zero_or_legacy_duration(): void
    {
        [$plant, $machine] = $this->createLocations();
        $admin = User::factory()->create(['role' => 'admin']);
        $technician = User::factory()->create(['role' => 'technician']);
        MaintenanceTicket::query()->create([
            'ticket_number' => 'DT-PARTIAL', 'plant_id' => $plant, 'machine_id' => $machine, 'problem_type' => 'Repair',
            'description' => 'Incomplete downtime timestamp test', 'source' => 'OPERATOR', 'status' => 'CLOSED', 'priority' => 'MEDIUM',
            'reported_by' => $admin->id, 'executor_id' => $technician->id, 'reason' => 'Test', 'action_taken' => 'Test',
            'solution' => 'Test', 'duration_hours' => 2, 'closed_at' => '2026-10-10 10:00:00',
            'actual_downtime_started_at' => '2026-10-10 08:00:00',
        ]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/reports/metrics?start_date=2026-10-10&end_date=2026-10-10&category=downtime&search=DT-PARTIAL')
            ->assertOk()->assertJsonPath('downtime.work_count', 0)->assertJsonPath('downtime.total_hours', 0)
            ->assertJsonPath('rows.data.0.work_time_source', 'BELUM LENGKAP')
            ->assertJsonPath('rows.data.0.duration_hours', null)
            ->assertJsonPath('rows.data.0.work_start', '2026-10-10T08:00:00.000000Z')
            ->assertJsonPath('rows.data.0.work_end', null);

        $sheet = $this->get('/api/reports/export?start_date=2026-10-10&end_date=2026-10-10&category=downtime&search=DT-PARTIAL')->assertOk()->streamedContent();
        $downtimeSheet = $this->exportSheet($sheet, 6);
        $this->assertStringContainsString('DT-PARTIAL', $downtimeSheet);
        $this->assertStringContainsString('BELUM LENGKAP', $downtimeSheet);

        MaintenanceTicket::query()->create([
            'ticket_number' => 'DT-PARTIAL-OPEN', 'plant_id' => $plant, 'machine_id' => $machine, 'problem_type' => 'Repair',
            'description' => 'Incomplete open timestamp blocks closure', 'source' => 'OPERATOR', 'status' => 'OPEN', 'priority' => 'MEDIUM',
            'reported_by' => $admin->id, 'duration_hours' => 2, 'actual_downtime_started_at' => '2026-10-10 08:00:00',
        ]);
        $this->actingAs($technician, 'sanctum')->postJson('/api/tickets/DT-PARTIAL-OPEN/actions/close', [
            'reason' => 'Test', 'action_taken' => 'Test', 'duration_hours' => 2, 'solution' => 'Test',
        ])->assertUnprocessable()->assertJsonPath('message', 'Waktu downtime belum lengkap. Isi waktu mulai dan waktu selesai aktual.');
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
        $sheet = $this->exportSheet($response->streamedContent(), 2);
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
        $this->assertStringContainsString('MC-1', $this->exportSheet($machineExport->streamedContent(), 4));
        $spareExport = $this->get('/api/reports/export?start_date=2026-10-01&end_date=2026-10-22&category=spare-parts');
        $spareExport->assertOk();
        $this->assertStringContainsString('SNS-1', $this->exportSheet($spareExport->streamedContent(), 5));
    }

    private function exportEntry(string $content, string $entry): string
    {
        $path = tempnam(sys_get_temp_dir(), 'report-test-');
        file_put_contents($path, $content);
        try {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path) === true);
            $sheet = $zip->getFromName($entry);
            $zip->close();

            return $sheet;
        } finally {
            @unlink($path);
        }
    }

    private function exportSheet(string $content, int $sheetNumber = 1): string
    {
        return $this->exportEntry($content, 'xl/worksheets/sheet'.$sheetNumber.'.xml');
    }

    private function assertWorkbookPackage(string $content): void
    {
        $path = tempnam(sys_get_temp_dir(), 'report-test-');
        file_put_contents($path, $content);
        libxml_use_internal_errors(true);
        try {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path) === true);
            $xmlEntries = 0;
            $chartEntries = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
                    $xmlEntries++;
                    $this->assertNotFalse(simplexml_load_string($zip->getFromIndex($index)), "Invalid XML in {$name}");
                }
                if (preg_match('#^xl/charts/chart\d+\.xml$#', $name)) {
                    $chartEntries++;
                }
            }
            $zip->close();
            $this->assertGreaterThanOrEqual(19, $xmlEntries);
            $this->assertGreaterThanOrEqual(1, $chartEntries);
        } finally {
            libxml_clear_errors();
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
