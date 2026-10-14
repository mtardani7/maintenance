<?php

namespace Tests\Unit;

use App\Services\MiraIntentService;
use App\Services\MiraQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MiraQueryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.connections.pgsql_mira', array_merge(
            config('database.connections.sqlite'),
            ['database' => ':memory:'],
        ));
        DB::purge('pgsql_mira');

        Schema::connection('pgsql_mira')->create('machines', function ($table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->unsignedBigInteger('plant_id')->nullable();
            $table->boolean('is_active')->default(true);
        });
        Schema::connection('pgsql_mira')->create('plants', function ($table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
        });
        Schema::connection('pgsql_mira')->create('maintenance_tickets', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('machine_id');
            $table->unsignedBigInteger('plant_id')->nullable();
            $table->string('problem_type');
            $table->string('status');
            $table->dateTime('created_at');
            $table->dateTime('closed_at')->nullable();
        });
        Schema::connection('pgsql_mira')->create('incidents', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('machine_id');
            $table->string('problem_type');
            $table->string('status');
            $table->dateTime('created_at');
        });
        Schema::connection('pgsql_mira')->create('maintenance_ticket_spare_parts', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('maintenance_ticket_id');
            $table->string('name');
            $table->string('material_code');
            $table->unsignedInteger('quantity');
            $table->dateTime('created_at');
        });

        CarbonImmutable::setTestNow('2026-10-06 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::purge('pgsql_mira');

        parent::tearDown();
    }

    public function test_current_open_ticket_count_ignores_created_today_period(): void
    {
        DB::connection('pgsql_mira')->table('machines')->insert([
            ['id' => 1, 'code' => 'M-01', 'name' => 'Press 1', 'plant_id' => 1],
        ]);
        DB::connection('pgsql_mira')->table('maintenance_tickets')->insert([
            ['machine_id' => 1, 'plant_id' => 1, 'problem_type' => 'Electrical', 'status' => 'OPEN', 'created_at' => '2026-10-06 08:00:00'],
            ['machine_id' => 1, 'plant_id' => 1, 'problem_type' => 'Mechanical', 'status' => 'CLOSED', 'created_at' => '2026-10-06 09:00:00'],
            ['machine_id' => 1, 'plant_id' => 1, 'problem_type' => 'Electrical', 'status' => 'OPEN', 'created_at' => '2026-10-05 23:59:59'],
        ]);

        $result = app(MiraQueryService::class)->run([
            'supported' => true,
            'entity' => 'ticket',
            'metric' => 'count',
            'group_by' => 'none',
            'period' => 'today',
            'date_basis' => 'none',
            'filter' => ['status' => 'open'],
            'sort' => 'none',
            'limit' => null,
        ]);

        $this->assertSame(2, $result['data']);
        $this->assertSame('saat ini', $result['period']);
        $this->assertSame('ticket.count.none', $result['query_type']);
    }

    public function test_open_tickets_explicitly_created_today_filter_creation_time_and_status(): void
    {
        $this->insertTickets();

        $result = app(MiraQueryService::class)->run($this->ticketCountIntent('today', 'created_at', 'open'));

        $this->assertSame(1, $result['data']);
    }

    public function test_open_tickets_without_a_period_counts_all_current_open_tickets(): void
    {
        $this->insertTickets();

        $result = app(MiraQueryService::class)->run($this->ticketCountIntent('all', 'none', 'open'));

        $this->assertSame(3, $result['data']);
        $this->assertSame('saat ini', $result['period']);
    }

    public function test_telegram_menu_open_ticket_action_uses_current_status_without_created_date_filter(): void
    {
        $this->insertTickets();

        $result = app(MiraQueryService::class)->runMenuAction('ticket.open');

        $this->assertSame(3, $result['data']);
        $this->assertSame('saat ini', $result['period']);
    }

    public function test_tickets_created_in_last_seven_days_keep_created_at_filter(): void
    {
        $this->insertTickets();

        $result = app(MiraQueryService::class)->run($this->ticketCountIntent('last_7_days', 'created_at'));

        $this->assertSame(4, $result['data']);
    }

    public function test_closed_today_uses_closed_at(): void
    {
        DB::connection('pgsql_mira')->table('machines')->insert([
            ['id' => 1, 'code' => 'M-01', 'name' => 'Press 1', 'plant_id' => 1],
        ]);
        DB::connection('pgsql_mira')->table('maintenance_tickets')->insert([
            ['machine_id' => 1, 'problem_type' => 'Electrical', 'status' => 'CLOSED', 'created_at' => '2026-09-01 08:00:00', 'closed_at' => '2026-10-06 08:00:00'],
            ['machine_id' => 1, 'problem_type' => 'Mechanical', 'status' => 'CLOSED', 'created_at' => '2026-10-06 09:00:00', 'closed_at' => '2026-10-05 09:00:00'],
        ]);

        $result = app(MiraQueryService::class)->run($this->ticketCountIntent('today', 'closed_at', 'closed'));

        $this->assertSame(1, $result['data']);
    }

    public function test_it_ranks_incidents_by_machine_using_the_mira_connection(): void
    {
        DB::connection('pgsql_mira')->table('plants')->insert(['id' => 1, 'code' => 'PLANT-1', 'name' => 'Plant 1']);
        DB::connection('pgsql_mira')->table('machines')->insert([
            ['id' => 1, 'code' => 'M-01', 'name' => 'Press 1', 'plant_id' => 1],
            ['id' => 2, 'code' => 'M-02', 'name' => 'Lathe 2', 'plant_id' => 1],
        ]);
        DB::connection('pgsql_mira')->table('incidents')->insert([
            ['machine_id' => 1, 'problem_type' => 'Electrical', 'status' => 'OPEN', 'created_at' => '2026-10-06 08:00:00'],
            ['machine_id' => 1, 'problem_type' => 'Mechanical', 'status' => 'RESOLVED', 'created_at' => '2026-10-05 08:00:00'],
            ['machine_id' => 2, 'problem_type' => 'Mechanical', 'status' => 'OPEN', 'created_at' => '2026-10-06 09:00:00'],
        ]);

        $intent = [
            'supported' => true,
            'entity' => 'incident',
            'metric' => 'count',
            'group_by' => 'machine',
            'period' => 'last_30_days',
            'date_basis' => 'created_at',
            'filter' => [],
            'sort' => 'desc',
            'limit' => 5,
        ];
        $this->assertTrue(app(MiraIntentService::class)->isValid($intent));
        $result = app(MiraQueryService::class)->run($intent);

        $this->assertNotNull($result);
        $this->assertSame('M-01 - Press 1', $result['data'][0]['machine']);
        $this->assertSame(2, $result['data'][0]['count']);
    }

    public function test_incident_count_today_still_filters_incident_creation_time(): void
    {
        DB::connection('pgsql_mira')->table('machines')->insert([
            ['id' => 1, 'code' => 'M-01', 'name' => 'Press 1', 'plant_id' => 1],
        ]);
        DB::connection('pgsql_mira')->table('incidents')->insert([
            ['machine_id' => 1, 'problem_type' => 'Electrical', 'status' => 'OPEN', 'created_at' => '2026-10-06 08:00:00'],
            ['machine_id' => 1, 'problem_type' => 'Mechanical', 'status' => 'OPEN', 'created_at' => '2026-10-05 23:59:59'],
        ]);

        $result = app(MiraQueryService::class)->run([
            'supported' => true, 'entity' => 'incident', 'metric' => 'count', 'group_by' => 'none',
            'period' => 'today', 'date_basis' => 'created_at', 'filter' => [], 'sort' => 'none', 'limit' => null,
        ]);

        $this->assertSame(1, $result['data']);
    }

    public function test_machine_count_can_filter_active_state_and_plant(): void
    {
        $this->seedPlantAndMachines();
        $service = app(MiraQueryService::class);

        $active = $service->run($this->intent('machine', 'count', 'none', 'all', 'none', ['is_active' => true, 'plant' => '3']));
        $inactive = $service->run($this->intent('machine', 'count', 'none', 'all', 'none', ['is_active' => false]));

        $this->assertSame(1, $active['data']);
        $this->assertSame(1, $inactive['data']);
    }

    public function test_machine_and_plant_total_counts_use_master_data(): void
    {
        $this->seedPlantAndMachines();
        $service = app(MiraQueryService::class);

        $machines = $service->run($this->intent('machine', 'count', 'none', 'all', 'none'));
        $plants = $service->run($this->intent('plant', 'count', 'none', 'all', 'none'));

        $this->assertSame(2, $machines['data']);
        $this->assertSame(2, $plants['data']);
    }

    public function test_machine_count_can_be_grouped_by_plant(): void
    {
        $this->seedPlantAndMachines();

        $result = app(MiraQueryService::class)->run($this->intent('machine', 'count', 'plant', 'all', 'none', [], 'desc'));

        $this->assertSame([
            ['plant' => 'PLANT-3 - Plant 3', 'count' => 2],
            ['plant' => 'PLANT-4 - Plant 4', 'count' => 0],
        ], $result['data']);
    }

    public function test_plant_count_can_filter_active_state(): void
    {
        $this->seedPlantAndMachines();

        $result = app(MiraQueryService::class)->run($this->intent('plant', 'count', 'none', 'all', 'none', ['is_active' => true]));

        $this->assertSame(1, $result['data']);
    }

    public function test_incident_count_filters_by_machine_and_plant_and_can_rank_by_plant(): void
    {
        $this->seedPlantAndMachines();
        DB::connection('pgsql_mira')->table('incidents')->insert([
            ['machine_id' => 1, 'problem_type' => 'Electrical', 'status' => 'OPEN', 'created_at' => '2026-10-06 08:00:00'],
            ['machine_id' => 1, 'problem_type' => 'Mechanical', 'status' => 'OPEN', 'created_at' => '2026-10-05 08:00:00'],
            ['machine_id' => 2, 'problem_type' => 'Mechanical', 'status' => 'OPEN', 'created_at' => '2026-10-06 08:00:00'],
        ]);
        $service = app(MiraQueryService::class);

        $filtered = $service->run($this->intent('incident', 'count', 'none', 'last_30_days', 'created_at', [
            'plant' => 'Plant 3', 'machine' => 'BREYER 1',
        ]));
        $ranking = $service->run($this->intent('incident', 'count', 'plant', 'last_30_days', 'created_at', [], 'desc'));

        $this->assertSame(2, $filtered['data']);
        $this->assertSame(['plant' => 'PLANT-3 - Plant 3', 'count' => 3], $ranking['data'][0]);
    }

    public function test_ticket_count_filters_status_machine_and_plant(): void
    {
        $this->seedPlantAndMachines();
        DB::connection('pgsql_mira')->table('maintenance_tickets')->insert([
            ['machine_id' => 1, 'plant_id' => 3, 'problem_type' => 'Electrical', 'status' => 'OPEN', 'created_at' => '2026-10-06 08:00:00'],
            ['machine_id' => 2, 'plant_id' => 3, 'problem_type' => 'Mechanical', 'status' => 'OPEN', 'created_at' => '2026-10-06 08:00:00'],
            ['machine_id' => 1, 'plant_id' => 3, 'problem_type' => 'Mechanical', 'status' => 'CLOSED', 'created_at' => '2026-10-06 08:00:00'],
        ]);

        $result = app(MiraQueryService::class)->run(array_replace($this->ticketCountIntent('today', 'created_at', 'open'), [
            'filter' => ['status' => 'open', 'plant' => '3', 'machine' => 'BREYER 1'],
        ]));

        $this->assertSame(1, $result['data']);
    }

    public function test_ticket_status_distribution_uses_ticket_status_values(): void
    {
        $this->insertTickets();

        $result = app(MiraQueryService::class)->run($this->intent('ticket', 'status', 'none', 'all', 'none'));

        $this->assertSame([
            ['status' => 'closed', 'count' => 2],
            ['status' => 'open', 'count' => 3],
        ], $result['data']);
    }

    public function test_spare_part_usage_can_sum_quantity_and_rank_items_in_period(): void
    {
        DB::connection('pgsql_mira')->table('maintenance_ticket_spare_parts')->insert([
            ['maintenance_ticket_id' => 1, 'name' => 'Bearing', 'material_code' => 'BR-1', 'quantity' => 2, 'created_at' => '2026-10-06 08:00:00'],
            ['maintenance_ticket_id' => 2, 'name' => 'Bearing', 'material_code' => 'BR-1', 'quantity' => 3, 'created_at' => '2026-10-05 08:00:00'],
            ['maintenance_ticket_id' => 3, 'name' => 'Belt', 'material_code' => 'BL-1', 'quantity' => 1, 'created_at' => '2026-09-01 08:00:00'],
        ]);

        $total = app(MiraQueryService::class)->run($this->intent('spare_part', 'sum', 'none', 'last_30_days', 'created_at'));
        $ranking = app(MiraQueryService::class)->run($this->intent('spare_part', 'sum', 'spare_part', 'last_30_days', 'created_at', [], 'desc'));

        $this->assertSame(5, $total['data']);
        $this->assertSame(['name' => 'Bearing', 'material_code' => 'BR-1', 'quantity' => 5], $ranking['data'][0]);
    }

    public function test_it_rejects_unsupported_intents_before_querying(): void
    {
        DB::connection('pgsql_mira')->enableQueryLog();

        $result = app(MiraQueryService::class)->run([
            'supported' => true,
            'entity' => 'ticket',
            'metric' => 'list',
            'group_by' => 'none',
            'period' => 'today',
            'date_basis' => 'none',
            'filter' => [],
            'sort' => 'none',
            'limit' => null,
        ]);

        $this->assertNull($result);
        $this->assertSame([], DB::connection('pgsql_mira')->getQueryLog());
    }

    public function test_it_rejects_an_ambiguous_ticket_period_without_a_date_basis(): void
    {
        DB::connection('pgsql_mira')->enableQueryLog();

        $result = app(MiraQueryService::class)->run($this->ticketCountIntent('today', 'none'));

        $this->assertNull($result);
        $this->assertSame([], DB::connection('pgsql_mira')->getQueryLog());
    }

    private function ticketCountIntent(string $period, string $dateBasis, ?string $status = null): array
    {
        return [
            'supported' => true,
            'entity' => 'ticket',
            'metric' => 'count',
            'group_by' => 'none',
            'period' => $period,
            'date_basis' => $dateBasis,
            'filter' => $status === null ? [] : ['status' => $status],
            'sort' => 'none',
            'limit' => null,
        ];
    }

    private function intent(string $entity, string $metric, string $groupBy, string $period, string $dateBasis, array $filter = [], string $sort = 'none', ?int $limit = null): array
    {
        return [
            'supported' => true,
            'entity' => $entity,
            'metric' => $metric,
            'group_by' => $groupBy,
            'period' => $period,
            'date_basis' => $dateBasis,
            'filter' => $filter,
            'sort' => $sort,
            'limit' => $limit,
        ];
    }

    private function seedPlantAndMachines(): void
    {
        DB::connection('pgsql_mira')->table('plants')->insert([
            ['id' => 3, 'code' => 'PLANT-3', 'name' => 'Plant 3', 'is_active' => true],
            ['id' => 4, 'code' => 'PLANT-4', 'name' => 'Plant 4', 'is_active' => false],
        ]);
        DB::connection('pgsql_mira')->table('machines')->insert([
            ['id' => 1, 'code' => 'BR-1', 'name' => 'BREYER 1', 'plant_id' => 3, 'is_active' => true],
            ['id' => 2, 'code' => 'BR-2', 'name' => 'BREYER 2', 'plant_id' => 3, 'is_active' => false],
        ]);
    }

    private function insertTickets(): void
    {
        DB::connection('pgsql_mira')->table('machines')->insert([
            ['id' => 1, 'code' => 'M-01', 'name' => 'Press 1', 'plant_id' => 1],
        ]);
        DB::connection('pgsql_mira')->table('maintenance_tickets')->insert([
            ['machine_id' => 1, 'plant_id' => 1, 'problem_type' => 'Electrical', 'status' => 'OPEN', 'created_at' => '2026-10-06 08:00:00'],
            ['machine_id' => 1, 'plant_id' => 1, 'problem_type' => 'Mechanical', 'status' => 'CLOSED', 'created_at' => '2026-10-06 09:00:00'],
            ['machine_id' => 1, 'plant_id' => 1, 'problem_type' => 'Electrical', 'status' => 'OPEN', 'created_at' => '2026-10-05 23:59:59'],
            ['machine_id' => 1, 'plant_id' => 1, 'problem_type' => 'Electrical', 'status' => 'CLOSED', 'created_at' => '2026-09-30 10:00:00'],
            ['machine_id' => 1, 'plant_id' => 1, 'problem_type' => 'Electrical', 'status' => 'OPEN', 'created_at' => '2026-09-29 23:59:59'],
        ]);
    }
}
