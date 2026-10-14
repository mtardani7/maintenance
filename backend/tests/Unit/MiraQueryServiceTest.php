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
        });
        Schema::connection('pgsql_mira')->create('plants', function ($table): void {
            $table->id();
            $table->string('name');
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
        DB::connection('pgsql_mira')->table('plants')->insert(['id' => 1, 'name' => 'Plant 1']);
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
