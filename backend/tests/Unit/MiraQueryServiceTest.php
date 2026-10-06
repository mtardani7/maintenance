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

    public function test_it_counts_open_tickets_created_today_from_the_mira_connection(): void
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
            'entity' => 'ticket',
            'metric' => 'count',
            'group_by' => 'none',
            'period' => 'today',
            'filter' => ['status' => 'open'],
            'sort' => 'none',
            'limit' => null,
        ]);

        $this->assertSame(1, $result['data']);
        $this->assertSame('ticket.count.none', $result['query_type']);
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
            'entity' => 'incident',
            'metric' => 'count',
            'group_by' => 'machine',
            'period' => 'last_30_days',
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

    public function test_it_rejects_unsupported_intents_before_querying(): void
    {
        DB::connection('pgsql_mira')->enableQueryLog();

        $result = app(MiraQueryService::class)->run([
            'entity' => 'ticket',
            'metric' => 'list',
            'group_by' => 'none',
            'period' => 'today',
            'filter' => [],
            'sort' => 'none',
            'limit' => null,
        ]);

        $this->assertNull($result);
        $this->assertSame([], DB::connection('pgsql_mira')->getQueryLog());
    }
}
