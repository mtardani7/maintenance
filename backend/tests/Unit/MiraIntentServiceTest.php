<?php

namespace Tests\Unit;

use App\Services\MiraIntentService;
use App\Services\MiraOllamaClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MiraIntentServiceTest extends TestCase
{
    public function test_it_parses_a_structured_ticket_count_intent(): void
    {
        Config::set('services.mira.ollama_url', 'http://ollama.test:11434');
        Config::set('services.mira.ollama_model', 'qwen3:0.6b');
        Http::fake([
            'ollama.test:11434/api/chat' => Http::response([
                'message' => [
                    'content' => json_encode([
                        'supported' => true,
                        'entity' => 'ticket',
                        'metric' => 'count',
                        'group_by' => 'none',
                        'period' => 'today',
                        'filter' => ['status' => 'open'],
                        'sort' => 'none',
                        'limit' => null,
                    ], JSON_THROW_ON_ERROR),
                ],
            ]),
        ]);

        $result = app(MiraIntentService::class)->understand('Berapa jumlah tiket maintenance hari ini?');

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
        $this->assertTrue($result['intent']['supported']);

        Http::assertSent(function ($request): bool {
            return $request['format']['type'] === 'object'
                && $request['stream'] === false
                && $request['think'] === false
                && $request['keep_alive'] === '5m'
                && $request['options']['num_ctx'] === 2048
                && $request['options']['num_predict'] === 256
                && $request['options']['temperature'] === 0;
        });
    }

    public function test_simple_open_ticket_count_is_parsed_without_calling_ollama(): void
    {
        Http::fake();

        $result = app(MiraIntentService::class)->understand('Berapa tiket open hari ini?');

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
        Http::assertNothingSent();
    }

    public function test_general_chat_payload_uses_keep_alive_without_json_schema(): void
    {
        Config::set('services.mira.ollama_url', 'http://ollama.test:11434');
        Config::set('services.mira.ollama_model', 'qwen3:0.6b');
        Http::fake(['ollama.test:11434/api/chat' => Http::response([
            'message' => ['content' => 'VPN mengenkripsi koneksi.'],
        ])]);

        $result = app(MiraOllamaClient::class)->chat([
            ['role' => 'user', 'content' => 'Apa itu VPN?'],
        ]);

        $this->assertSame('VPN mengenkripsi koneksi.', $result['content']);
        Http::assertSent(fn ($request): bool => $request['keep_alive'] === '5m'
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

    public function test_it_normalizes_sort_for_non_grouped_ticket_count_intents(): void
    {
        Config::set('services.mira.ollama_url', 'http://ollama.test:11434');
        Config::set('services.mira.ollama_model', 'qwen3:0.6b');
        Http::fake([
            'ollama.test:11434/api/chat' => Http::response([
                'message' => [
                    'content' => json_encode([
                        'supported' => true,
                        'entity' => 'ticket',
                        'metric' => 'count',
                        'group_by' => 'none',
                        'period' => 'today',
                        'filter' => ['status' => 'open'],
                        'sort' => 'asc',
                        'limit' => null,
                    ], JSON_THROW_ON_ERROR),
                ],
            ]),
        ]);

        $result = app(MiraIntentService::class)->understand('Berapa tiket open hari ini?');

        $this->assertNotNull($result['intent']);
        $this->assertTrue($result['intent']['supported']);
        $this->assertSame('none', $result['intent']['sort']);
    }

    public function test_it_preserves_sort_for_machine_ranking_intents(): void
    {
        Config::set('services.mira.ollama_url', 'http://ollama.test:11434');
        Config::set('services.mira.ollama_model', 'qwen3:0.6b');
        Http::fake([
            'ollama.test:11434/api/chat' => Http::response([
                'message' => [
                    'content' => json_encode([
                        'supported' => true,
                        'entity' => 'incident',
                        'metric' => 'count',
                        'group_by' => 'machine',
                        'period' => 'last_30_days',
                        'filter' => [],
                        'sort' => 'desc',
                        'limit' => 5,
                    ], JSON_THROW_ON_ERROR),
                ],
            ]),
        ]);

        $result = app(MiraIntentService::class)->understand('Mesin mana yang paling sering mengalami incident?');

        $this->assertNotNull($result['intent']);
        $this->assertTrue($result['intent']['supported']);
        $this->assertSame('desc', $result['intent']['sort']);
    }

    public function test_it_recognizes_a_president_question_as_unsupported(): void
    {
        Config::set('services.mira.ollama_url', 'http://ollama.test:11434');
        Config::set('services.mira.ollama_model', 'qwen3:0.6b');
        Http::fake([
            'ollama.test:11434/api/chat' => Http::response([
                'message' => [
                    'content' => json_encode([
                        'supported' => false,
                        'entity' => 'ticket',
                        'metric' => 'list',
                        'group_by' => 'none',
                        'period' => 'all',
                        'filter' => [],
                        'sort' => 'none',
                        'limit' => null,
                    ], JSON_THROW_ON_ERROR),
                ],
            ]),
        ]);

        $result = app(MiraIntentService::class)->understand('Siapa presiden saat ini?');

        $this->assertSame([
            'supported' => false,
            'entity' => 'ticket',
            'metric' => 'list',
            'group_by' => 'none',
            'period' => 'all',
            'filter' => [],
            'sort' => 'none',
            'limit' => null,
        ], $result['intent']);
    }

    public function test_it_recognizes_a_docker_installation_question_as_unsupported(): void
    {
        Config::set('services.mira.ollama_url', 'http://ollama.test:11434');
        Config::set('services.mira.ollama_model', 'qwen3:0.6b');
        Http::fake([
            'ollama.test:11434/api/chat' => Http::response([
                'message' => [
                    'content' => json_encode([
                        'supported' => false,
                        'entity' => 'ticket',
                        'metric' => 'list',
                        'group_by' => 'none',
                        'period' => 'all',
                        'filter' => [],
                        'sort' => 'none',
                        'limit' => null,
                    ], JSON_THROW_ON_ERROR),
                ],
            ]),
        ]);

        $result = app(MiraIntentService::class)->understand('Bagaimana cara install Docker?');

        $this->assertFalse($result['intent']['supported']);
        $this->assertSame('ticket', $result['intent']['entity']);
        $this->assertSame('list', $result['intent']['metric']);
    }
}
