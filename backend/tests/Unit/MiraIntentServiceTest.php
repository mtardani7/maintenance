<?php

namespace Tests\Unit;

use App\Services\MiraAssistantService;
use App\Services\MiraIntentService;
use App\Services\MiraOllamaClient;
use App\Services\MiraQueryService;
use App\Services\MiraResponseFormatter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MiraIntentServiceTest extends TestCase
{
    private function maintenanceResponse(array $overrides = []): array
    {
        return array_replace([
            'supported' => true,
            'clarify' => false,
            'entity' => 'ticket',
            'metric' => 'count',
            'group_by' => 'none',
            'period' => 'today',
            'filter' => ['status' => 'open'],
            'sort' => 'none',
            'limit' => null,
            'clarification' => '',
        ], $overrides);
    }

    public function test_different_open_ticket_wording_resolves_to_equivalent_intent_without_regex_routes(): void
    {
        Config::set('services.mira.ollama_url', 'http://ollama.test:11434');
        Config::set('services.mira.ollama_model', 'qwen3.5:2b');
        $questions = [
            'Berapa tiket open hari ini?',
            'Berapa tiket yang terbuka hari ini?',
            'Cek jumlah tiket yang masih terbuka hari ini.',
            'Tolong hitung tiket yang belum selesai hari ini.',
            'Ada berapa ticket yang statusnya open hari ini?',
        ];
        $sequence = Http::sequence();
        foreach ($questions as $_question) {
            $sequence->push(['message' => ['content' => json_encode($this->maintenanceResponse(), JSON_THROW_ON_ERROR)]]);
        }
        Http::fake(['ollama.test:11434/api/chat' => $sequence]);

        foreach ($questions as $question) {
            $result = app(MiraIntentService::class)->understand($question);
            $this->assertSame('maintenance', $result['route']);
            $this->assertSame([
                'supported' => true,
                'entity' => 'ticket',
                'metric' => 'count',
                'group_by' => 'none',
                'period' => 'today',
                'filter' => ['status' => 'open'],
                'sort' => 'none',
                'limit' => null,
            ], $result['intent']);
        }

        Http::assertSentCount(5);
        foreach ($questions as $question) {
            Http::assertSent(fn ($request): bool => $request['messages'][1]['content'] === $question
                && $request['messages'][0]['role'] === 'system'
                && isset($request['messages'][0]['content'])
                && $request['keep_alive'] === '5m'
                && $request['think'] === false
                && $request['options']['temperature'] === 0);
        }
    }

    public function test_general_classification_has_placeholder_intent_then_chat_request_has_no_json_schema(): void
    {
        Config::set('services.mira.ollama_url', 'http://ollama.test:11434');
        Config::set('services.mira.ollama_model', 'qwen3.5:2b');
        $general = $this->maintenanceResponse([
            'supported' => false, 'entity' => 'ticket', 'metric' => 'list', 'period' => 'all',
            'filter' => [],
        ]);
        Http::fake(['ollama.test:11434/api/chat' => Http::sequence()
            ->push(['message' => ['content' => json_encode($general, JSON_THROW_ON_ERROR)]])
            ->push(['message' => ['content' => 'VPN mengenkripsi koneksi.']])]);
        $queries = \Mockery::mock(MiraQueryService::class);
        $queries->shouldNotReceive('run');
        $formatter = \Mockery::mock(MiraResponseFormatter::class);
        $assistant = new MiraAssistantService(
            app(MiraIntentService::class), $queries, $formatter, app(MiraOllamaClient::class),
        );

        $result = $assistant->answer('Bagaimana cara kerja VPN?');

        $this->assertSame('VPN mengenkripsi koneksi.', $result['answer']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => ($request->data()['format']['type'] ?? null) === 'object');
        Http::assertSent(fn ($request): bool => $request['messages'][0]['role'] === 'system'
            && ! array_key_exists('format', $request->data()));
    }

    public function test_ambiguous_question_returns_clarification_route(): void
    {
        $response = $this->maintenanceResponse([
            'supported' => false, 'clarify' => true, 'entity' => 'ticket', 'metric' => 'list',
            'period' => 'all', 'filter' => [], 'clarification' => 'Maksudnya status open atau closed?',
        ]);
        Http::fake(['*' => Http::response(['message' => ['content' => json_encode($response, JSON_THROW_ON_ERROR)]])]);

        $result = app(MiraIntentService::class)->understand('Berapa jumlah tiket?');

        $this->assertSame('clarify', $result['route']);
        $this->assertNull($result['intent']);
        $this->assertNotSame('', $result['clarification']);
    }

    public function test_contradictory_or_invalid_structured_output_is_rejected(): void
    {
        $service = app(MiraIntentService::class);
        $contradictory = $this->maintenanceResponse(['supported' => false]);

        $this->assertFalse($service->isValidResponse($contradictory));
        Http::fake(['*' => Http::response(['message' => ['content' => '{invalid json']])]);
        $result = $service->understand('Berapa tiket hari ini?');
        $this->assertSame('invalid', $result['route']);
        $this->assertNull($result['intent']);
    }

    public function test_chat_request_uses_keep_alive_and_preserves_model_options(): void
    {
        Config::set('services.mira.ollama_url', 'http://ollama.test:11434');
        Config::set('services.mira.ollama_model', 'qwen3.5:2b');
        Config::set('services.mira.ollama_keep_alive', '9m');
        Http::fake(['ollama.test:11434/api/chat' => Http::response(['message' => ['content' => 'Jawaban.']])]);

        app(MiraOllamaClient::class)->chat([['role' => 'user', 'content' => 'Halo']]);

        Http::assertSent(fn ($request): bool => $request['keep_alive'] === '9m'
            && $request['think'] === false
            && $request['options']['temperature'] === 0
            && ! array_key_exists('format', $request->data()));
    }

    public function test_it_rejects_an_intent_that_contains_sql(): void
    {
        $service = app(MiraIntentService::class);

        $this->assertFalse($service->isValid([
            'supported' => true,
            'entity' => 'ticket',
            'metric' => 'count',
            'group_by' => 'none',
            'period' => 'today',
            'filter' => ['sql' => 'DROP TABLE maintenance_tickets'],
            'sort' => 'none',
            'limit' => null,
        ]));
    }
}
