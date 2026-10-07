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
    public function test_it_uses_intent_query_and_deterministic_formatter_without_second_inference(): void
    {
        $question = 'Berapa tiket open hari ini?';
        $intent = [
            'supported' => true,
            'entity' => 'ticket',
            'metric' => 'count',
            'group_by' => 'none',
            'period' => 'today',
            'filter' => ['status' => 'open'],
            'sort' => 'none',
            'limit' => null,
        ];
        $queryResult = [
            'query_type' => 'ticket.count.none',
            'period' => 'hari ini',
            'data' => 3,
            'duration_ms' => 2,
        ];

        $intents = Mockery::mock(MiraIntentService::class);
        $intents->shouldReceive('understand')->once()->with($question)->andReturn([
            'intent' => $intent,
            'duration_ms' => 10,
        ]);

        $queries = Mockery::mock(MiraQueryService::class);
        $queries->shouldReceive('run')->once()->with($intent)->andReturn($queryResult);

        $formatter = Mockery::mock(MiraResponseFormatter::class);
        $formatter->shouldReceive('format')->once()->with($question, $intent, $queryResult)
            ->andReturn('Hari ini ada 3 tiket yang masih terbuka.');

        $result = (new MiraAssistantService($intents, $queries, $formatter))->answer($question);

        $this->assertSame('Hari ini ada 3 tiket yang masih terbuka.', $result['answer']);
        $this->assertSame(10, $result['intent_ms']);
        $this->assertSame(2, $result['query_ms']);
        $this->assertArrayNotHasKey('response_ms', $result);
    }

    public function test_it_returns_understanding_fallback_when_qwen_intent_fails(): void
    {
        $intents = Mockery::mock(MiraIntentService::class);
        $intents->shouldReceive('understand')->once()->andThrow(new RuntimeException('Ollama unavailable'));

        $queries = Mockery::mock(MiraQueryService::class);
        $queries->shouldNotReceive('run');

        $formatter = Mockery::mock(MiraResponseFormatter::class);
        $formatter->shouldNotReceive('format');

        $result = (new MiraAssistantService($intents, $queries, $formatter))->answer('Berapa tiket open hari ini?');

        $this->assertFalse($result['understood']);
        $this->assertSame('MIRA belum memahami pertanyaan tersebut.', $result['answer']);
    }

    public function test_it_returns_fixed_scope_message_without_querying_for_unsupported_intents(): void
    {
        $intent = [
            'supported' => false,
            'entity' => 'ticket',
            'metric' => 'list',
            'group_by' => 'none',
            'period' => 'all',
            'filter' => [],
            'sort' => 'none',
            'limit' => null,
        ];
        $intents = Mockery::mock(MiraIntentService::class);
        $intents->shouldReceive('understand')->once()->andReturn([
            'intent' => $intent,
            'duration_ms' => 10,
        ]);

        $queries = Mockery::mock(MiraQueryService::class);
        $queries->shouldNotReceive('run');

        $formatter = Mockery::mock(MiraResponseFormatter::class);
        $formatter->shouldNotReceive('format');

        $result = (new MiraAssistantService($intents, $queries, $formatter))->answer('Siapa presiden Indonesia?');

        $this->assertFalse($result['understood']);
        $this->assertFalse($result['intent']['supported']);
        $this->assertSame(
            'Maaf, saya saat ini fokus membantu informasi Maintenance System, seperti tiket, incident, mesin, plant, dan spare part.',
            $result['answer'],
        );
    }

    public function test_it_uses_a_safe_fallback_when_formatter_fails(): void
    {
        $intent = ['supported' => true, 'entity' => 'ticket', 'metric' => 'count', 'group_by' => 'none'];
        $queryResult = ['query_type' => 'ticket.count.none', 'data' => 3, 'duration_ms' => 2];

        $intents = Mockery::mock(MiraIntentService::class);
        $intents->shouldReceive('understand')->once()->andReturn(['intent' => $intent, 'duration_ms' => 10]);

        $queries = Mockery::mock(MiraQueryService::class);
        $queries->shouldReceive('run')->once()->andReturn($queryResult);

        $formatter = Mockery::mock(MiraResponseFormatter::class);
        $formatter->shouldReceive('format')->once()->andThrow(new RuntimeException('Formatter failed'));

        $result = (new MiraAssistantService($intents, $queries, $formatter))->answer('Berapa tiket?');

        $this->assertSame(
            'Data berhasil diperoleh, tetapi jawaban tidak dapat disusun. Silakan coba lagi.',
            $result['answer'],
        );
    }
}
