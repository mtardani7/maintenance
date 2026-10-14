<?php

namespace Tests\Unit;

use App\Services\MiraIntentService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MiraIntentServiceTest extends TestCase
{
    private function maintenanceResponse(array $overrides = []): array
    {
        return array_replace([
            'route' => 'maintenance',
            'supported' => true,
            'entity' => 'ticket',
            'metric' => 'count',
            'group_by' => 'none',
            'period' => 'today',
            'filter' => ['status' => 'open'],
            'sort' => 'none',
            'limit' => null,
            'answer' => '',
            'clarification' => '',
        ], $overrides);
    }

    public function test_variations_of_open_ticket_questions_have_same_maintenance_intent(): void
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
        Http::fake(['ollama.test:11434/api/chat' => Http::sequence()->push([
            'message' => ['content' => json_encode($this->maintenanceResponse(), JSON_THROW_ON_ERROR)],
        ])->push([
            'message' => ['content' => json_encode($this->maintenanceResponse(), JSON_THROW_ON_ERROR)],
        ])->push([
            'message' => ['content' => json_encode($this->maintenanceResponse(), JSON_THROW_ON_ERROR)],
        ])->push([
            'message' => ['content' => json_encode($this->maintenanceResponse(), JSON_THROW_ON_ERROR)],
        ])->push([
            'message' => ['content' => json_encode($this->maintenanceResponse(), JSON_THROW_ON_ERROR)],
        ])]);

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
        Http::assertSent(fn ($request): bool => $request['format']['type'] === 'object'
            && $request['think'] === false
            && $request['keep_alive'] === '5m'
            && $request['options']['temperature'] === 0);
    }

    public function test_general_route_has_no_maintenance_intent(): void
    {
        Config::set('services.mira.ollama_url', 'http://ollama.test:11434');
        Config::set('services.mira.ollama_model', 'qwen3.5:2b');
        $response = $this->maintenanceResponse([
            'route' => 'general', 'supported' => false, 'metric' => 'list', 'period' => 'all',
            'filter' => [], 'answer' => 'VPN membuat koneksi terenkripsi.',
        ]);
        Http::fake(['ollama.test:11434/api/chat' => Http::response([
            'message' => ['content' => json_encode($response, JSON_THROW_ON_ERROR)],
        ])]);

        $result = app(MiraIntentService::class)->understand('Bagaimana cara kerja VPN?');

        $this->assertSame('general', $result['route']);
        $this->assertNull($result['intent']);
        $this->assertSame('VPN membuat koneksi terenkripsi.', $result['answer']);
    }

    public function test_clarify_route_has_no_intent_to_execute(): void
    {
        $response = $this->maintenanceResponse([
            'route' => 'clarify', 'supported' => false, 'metric' => 'list', 'period' => 'all',
            'filter' => [], 'clarification' => 'Maksudnya tiket open atau closed?',
        ]);
        Http::fake(['*' => Http::response(['message' => ['content' => json_encode($response, JSON_THROW_ON_ERROR)]])]);

        $result = app(MiraIntentService::class)->understand('Berapa jumlah tiket?');

        $this->assertSame('clarify', $result['route']);
        $this->assertNull($result['intent']);
        $this->assertNotSame('', $result['clarification']);
    }

    public function test_invalid_structured_output_returns_safe_invalid_route(): void
    {
        Http::fake(['*' => Http::response(['message' => ['content' => '{invalid json']])]);

        $result = app(MiraIntentService::class)->understand('Berapa tiket hari ini?');

        $this->assertSame('invalid', $result['route']);
        $this->assertNull($result['intent']);
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
