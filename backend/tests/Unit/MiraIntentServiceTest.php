<?php

namespace Tests\Unit;

use App\Services\MiraIntentService;
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

        $result = app(MiraIntentService::class)->understand('Berapa tiket open hari ini?');

        $this->assertSame('ticket', $result['intent']['entity']);
        $this->assertSame('open', $result['intent']['filter']['status']);
        Http::assertSent(fn ($request): bool => $request['format']['type'] === 'object'
            && $request['stream'] === false);
    }

    public function test_it_rejects_an_intent_that_contains_sql(): void
    {
        $service = app(MiraIntentService::class);

        $this->assertFalse($service->isValid([
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
