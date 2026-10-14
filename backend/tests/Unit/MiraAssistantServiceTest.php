<?php

namespace Tests\Unit;

use App\Services\MiraAssistantService;
use App\Services\MiraIntentService;
use App\Services\MiraQueryService;
use App\Services\MiraResponseFormatter;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MiraAssistantServiceTest extends TestCase
{
    private function maintenanceIntent(): array
    {
        return [
            'supported' => true, 'entity' => 'ticket', 'metric' => 'count', 'group_by' => 'none',
            'period' => 'today', 'filter' => ['status' => 'open'], 'sort' => 'none', 'limit' => null,
        ];
    }

    public function test_it_runs_valid_maintenance_intent_and_formats_database_result(): void
    {
        $question = 'Berapa tiket yang terbuka hari ini?';
        $intent = $this->maintenanceIntent();
        $queryResult = ['query_type' => 'ticket.count.none', 'period' => 'hari ini', 'data' => 3, 'duration_ms' => 2];
        $intents = Mockery::mock(MiraIntentService::class);
        $intents->shouldReceive('understand')->once()->with($question)->andReturn([
            'route' => 'maintenance', 'intent' => $intent, 'answer' => '', 'clarification' => '', 'duration_ms' => 10,
        ]);
        $intents->shouldReceive('isValid')->once()->with($intent)->andReturnTrue();
        $queries = Mockery::mock(MiraQueryService::class);
        $queries->shouldReceive('run')->once()->with($intent)->andReturn($queryResult);
        $formatter = Mockery::mock(MiraResponseFormatter::class);
        $formatter->shouldReceive('format')->once()->with($question, $intent, $queryResult)->andReturn('Hari ini ada 3 tiket yang masih terbuka.');

        $result = (new MiraAssistantService($intents, $queries, $formatter))->answer($question);

        $this->assertSame('Hari ini ada 3 tiket yang masih terbuka.', $result['answer']);
        $this->assertSame(10, $result['intent_ms']);
        $this->assertSame(2, $result['query_ms']);
    }

    public function test_greeting_returns_direct_reply_without_ai_or_database(): void
    {
        $intents = Mockery::mock(MiraIntentService::class);
        $intents->shouldNotReceive('understand');
        $queries = Mockery::mock(MiraQueryService::class);
        $queries->shouldNotReceive('run');
        $formatter = Mockery::mock(MiraResponseFormatter::class);
        $formatter->shouldNotReceive('format');

        $result = (new MiraAssistantService($intents, $queries, $formatter))->answer('HaLo!!!');

        $this->assertSame('Halo! Saya MIRA. Ada yang bisa saya bantu?', $result['answer']);
        $this->assertSame(0, $result['intent_ms']);
    }

    public function test_greeting_prefix_is_removed_before_understanding_question(): void
    {
        $question = 'berapa tiket yang terbuka hari ini?';
        $intents = Mockery::mock(MiraIntentService::class);
        $intents->shouldReceive('understand')->once()->with($question)->andReturn([
            'route' => 'clarify', 'intent' => null, 'answer' => '', 'clarification' => 'Which status?', 'duration_ms' => 1,
        ]);
        $queries = Mockery::mock(MiraQueryService::class);
        $queries->shouldNotReceive('run');
        $formatter = Mockery::mock(MiraResponseFormatter::class);

        $result = (new MiraAssistantService($intents, $queries, $formatter))->answer('Halo, '.$question);

        $this->assertSame('Which status?', $result['answer']);
    }

    public function test_general_question_uses_model_answer_without_database_query(): void
    {
        $intents = Mockery::mock(MiraIntentService::class);
        $intents->shouldReceive('understand')->once()->with('Bagaimana cara kerja VPN?')->andReturn([
            'route' => 'general', 'intent' => null, 'answer' => 'VPN mengenkripsi koneksi.', 'clarification' => '', 'duration_ms' => 20,
        ]);
        $queries = Mockery::mock(MiraQueryService::class);
        $queries->shouldNotReceive('run');
        $formatter = Mockery::mock(MiraResponseFormatter::class);
        $formatter->shouldNotReceive('format');

        $result = (new MiraAssistantService($intents, $queries, $formatter))->answer('Bagaimana cara kerja VPN?');

        $this->assertSame('VPN mengenkripsi koneksi.', $result['answer']);
        $this->assertNull($result['query_type']);
    }

    public function test_clarify_route_asks_for_information_without_querying(): void
    {
        $intents = Mockery::mock(MiraIntentService::class);
        $intents->shouldReceive('understand')->once()->andReturn([
            'route' => 'clarify', 'intent' => null, 'answer' => '', 'clarification' => 'Tiket open atau closed?', 'duration_ms' => 8,
        ]);
        $queries = Mockery::mock(MiraQueryService::class);
        $queries->shouldNotReceive('run');
        $formatter = Mockery::mock(MiraResponseFormatter::class);

        $result = (new MiraAssistantService($intents, $queries, $formatter))->answer('Berapa jumlah tiket?');

        $this->assertSame('Tiket open atau closed?', $result['answer']);
        $this->assertFalse($result['understood']);
    }

    public function test_invalid_intent_never_executes_database_query(): void
    {
        $intents = Mockery::mock(MiraIntentService::class);
        $intents->shouldReceive('understand')->once()->andReturn([
            'route' => 'maintenance', 'intent' => ['supported' => true], 'answer' => '', 'clarification' => '', 'duration_ms' => 1,
        ]);
        $intents->shouldReceive('isValid')->once()->andReturnFalse();
        $queries = Mockery::mock(MiraQueryService::class);
        $queries->shouldNotReceive('run');
        $formatter = Mockery::mock(MiraResponseFormatter::class);

        $result = (new MiraAssistantService($intents, $queries, $formatter))->answer('Berapa tiket hari ini?');

        $this->assertFalse($result['understood']);
        $this->assertNull($result['query_type']);
    }

    public function test_invalid_response_and_model_failure_are_safe_without_querying(): void
    {
        $intents = Mockery::mock(MiraIntentService::class);
        $intents->shouldReceive('understand')->once()->andThrow(new RuntimeException('Invalid response'));
        $queries = Mockery::mock(MiraQueryService::class);
        $queries->shouldNotReceive('run');
        $formatter = Mockery::mock(MiraResponseFormatter::class);

        $result = (new MiraAssistantService($intents, $queries, $formatter))->answer('Ada tiket maintenance?');

        $this->assertFalse($result['understood']);
        $this->assertNull($result['intent']);
    }

    public function test_it_uses_a_safe_fallback_when_formatter_fails(): void
    {
        $intent = $this->maintenanceIntent();
        $queryResult = ['query_type' => 'ticket.count.none', 'data' => 3, 'duration_ms' => 2];
        $intents = Mockery::mock(MiraIntentService::class);
        $intents->shouldReceive('understand')->once()->andReturn([
            'route' => 'maintenance', 'intent' => $intent, 'answer' => '', 'clarification' => '', 'duration_ms' => 10,
        ]);
        $intents->shouldReceive('isValid')->once()->andReturnTrue();
        $queries = Mockery::mock(MiraQueryService::class);
        $queries->shouldReceive('run')->once()->andReturn($queryResult);
        $formatter = Mockery::mock(MiraResponseFormatter::class);
        $formatter->shouldReceive('format')->once()->andThrow(new RuntimeException('Formatter failed'));

        $result = (new MiraAssistantService($intents, $queries, $formatter))->answer('Berapa tiket open?');

        $this->assertSame('Data berhasil diperoleh, tetapi jawaban tidak dapat disusun. Silakan coba lagi.', $result['answer']);
    }
}
