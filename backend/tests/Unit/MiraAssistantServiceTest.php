<?php

namespace Tests\Unit;

use App\Services\MiraAssistantService;
use App\Services\MiraIntentService;
use App\Services\MiraOllamaClient;
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
        $intents->shouldReceive('isValid')->once()->with($intent)->andReturnTrue();

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

    public function test_greeting_returns_direct_reply_without_ai_or_database(): void
    {
        $intents = Mockery::mock(MiraIntentService::class);
        $intents->shouldNotReceive('understand');
        $queries = Mockery::mock(MiraQueryService::class);
        $queries->shouldNotReceive('run');
        $formatter = Mockery::mock(MiraResponseFormatter::class);
        $formatter->shouldNotReceive('format');
        $ollama = Mockery::mock(MiraOllamaClient::class);
        $ollama->shouldNotReceive('chat');

        $result = (new MiraAssistantService($intents, $queries, $formatter, $ollama))->answer('HaLo!!!');

        $this->assertSame('Halo! Saya MIRA. Ada yang bisa saya bantu?', $result['answer']);
        $this->assertSame(0, $result['intent_ms']);
    }

    public function test_greeting_prefix_is_removed_before_maintenance_routing(): void
    {
        $intents = Mockery::mock(MiraIntentService::class);
        $intents->shouldReceive('understand')->once()->with('berapa tiket open hari ini?')->andReturn([
            'intent' => [
                'supported' => true, 'entity' => 'ticket', 'metric' => 'count', 'group_by' => 'none',
                'period' => 'today', 'filter' => ['status' => 'open'], 'sort' => 'none', 'limit' => null,
            ],
            'duration_ms' => 0,
        ]);
        $intents->shouldReceive('isValid')->once()->andReturnTrue();
        $queries = Mockery::mock(MiraQueryService::class);
        $queries->shouldReceive('run')->once()->andReturn(null);
        $formatter = Mockery::mock(MiraResponseFormatter::class);
        $ollama = Mockery::mock(MiraOllamaClient::class);
        $ollama->shouldNotReceive('chat');

        (new MiraAssistantService($intents, $queries, $formatter, $ollama))->answer('Halo, berapa tiket open hari ini?');
    }

    public function test_general_question_uses_chat_without_intent_or_database_query(): void
    {
        $intents = Mockery::mock(MiraIntentService::class);
        $intents->shouldNotReceive('understand');
        $queries = Mockery::mock(MiraQueryService::class);
        $queries->shouldNotReceive('run');
        $formatter = Mockery::mock(MiraResponseFormatter::class);
        $formatter->shouldNotReceive('format');
        $ollama = Mockery::mock(MiraOllamaClient::class);
        $ollama->shouldReceive('chat')->once()->with(Mockery::on(fn ($messages) => count($messages) === 2
            && ! array_key_exists('format', $messages[0])
            && $messages[1]['content'] === 'Bagaimana cara kerja VPN?'))->andReturn([
                'content' => 'VPN membuat koneksi terenkripsi.', 'duration_ms' => 20,
            ]);

        $result = (new MiraAssistantService($intents, $queries, $formatter, $ollama))->answer('Bagaimana cara kerja VPN?');

        $this->assertSame('VPN membuat koneksi terenkripsi.', $result['answer']);
        $this->assertNull($result['query_type']);
    }

    public function test_supported_false_never_executes_database_query(): void
    {
        $intent = [
            'supported' => false, 'entity' => 'ticket', 'metric' => 'list', 'group_by' => 'none',
            'period' => 'all', 'filter' => [], 'sort' => 'none', 'limit' => null,
        ];
        $intents = Mockery::mock(MiraIntentService::class);
        $intents->shouldReceive('understand')->once()->andReturn(['intent' => $intent, 'duration_ms' => 1]);
        $intents->shouldReceive('isValid')->once()->andReturnTrue();
        $queries = Mockery::mock(MiraQueryService::class);
        $queries->shouldNotReceive('run');
        $formatter = Mockery::mock(MiraResponseFormatter::class);
        $ollama = Mockery::mock(MiraOllamaClient::class);

        $result = (new MiraAssistantService($intents, $queries, $formatter, $ollama))->answer('Berapa tiket alien di bulan Mars?');

        $this->assertFalse($result['understood']);
    }

    public function test_invalid_intent_never_executes_database_query(): void
    {
        $intents = Mockery::mock(MiraIntentService::class);
        $intents->shouldReceive('understand')->once()->andReturn(['intent' => null, 'duration_ms' => 1]);
        $queries = Mockery::mock(MiraQueryService::class);
        $queries->shouldNotReceive('run');
        $formatter = Mockery::mock(MiraResponseFormatter::class);
        $ollama = Mockery::mock(MiraOllamaClient::class);

        $result = (new MiraAssistantService($intents, $queries, $formatter, $ollama))->answer('Berapa jumlah tiket maintenance hari ini?');

        $this->assertFalse($result['understood']);
        $this->assertNull($result['intent']);
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
        $intents->shouldReceive('isValid')->once()->andReturnTrue();

        $queries = Mockery::mock(MiraQueryService::class);
        $queries->shouldNotReceive('run');

        $formatter = Mockery::mock(MiraResponseFormatter::class);
        $formatter->shouldNotReceive('format');

        $result = (new MiraAssistantService($intents, $queries, $formatter))->answer('Apa status tiket terbaru?');

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
        $intents->shouldReceive('isValid')->once()->andReturnTrue();

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
