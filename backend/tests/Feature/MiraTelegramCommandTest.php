<?php

namespace Tests\Feature;

use App\Services\MiraAssistantService;
use App\Services\MiraIntentService;
use App\Services\MiraQueryService;
use App\Services\MiraTelegramClient;
use App\Services\MiraTelegramMessageHandler;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MiraTelegramCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mira.telegram_maintenance_chat_id' => '-5398792908',
            'services.mira.telegram_bot_username' => 'mira_dev_bot',
        ]);
    }

    public function test_it_processes_a_telegram_question_and_sends_a_database_formatted_answer(): void
    {
        $question = 'Berapa tiket open hari ini?';
        $intent = [
            'supported' => true,
            'entity' => 'ticket',
            'metric' => 'count',
            'group_by' => 'none',
            'period' => 'today',
            'date_basis' => 'none',
            'filter' => ['status' => 'open'],
            'sort' => 'none',
            'limit' => null,
        ];
        $intents = Mockery::mock(MiraIntentService::class);
        $intents->shouldReceive('understand')->once()->with($question)->andReturn([
            'route' => 'maintenance',
            'intent' => $intent,
            'answer' => '',
            'clarification' => '',
            'duration_ms' => 500,
        ]);
        $intents->shouldReceive('isValid')->once()->andReturnTrue();
        $this->app->instance(MiraIntentService::class, $intents);

        $queries = Mockery::mock(MiraQueryService::class);
        $queries->shouldReceive('run')->once()->with($intent)->andReturn([
            'query_type' => 'ticket.count.none',
            'period' => 'hari ini',
            'data' => 3,
            'duration_ms' => 2,
        ]);
        $this->app->instance(MiraQueryService::class, $queries);

        $telegram = Mockery::mock(MiraTelegramClient::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->with(12345, 'Hari ini ada 3 tiket yang masih terbuka.');

        $handler = app(MiraTelegramMessageHandler::class);
        $handler->handle($telegram, 1001, [
            'text' => $question,
            'chat' => ['id' => 12345, 'type' => 'private'],
            'from' => ['id' => 67890, 'is_bot' => false],
        ]);

        $this->assertInstanceOf(MiraAssistantService::class, app(MiraAssistantService::class));
    }

    public function test_it_sends_a_safe_message_when_the_database_query_fails(): void
    {
        $intents = Mockery::mock(MiraIntentService::class);
        $intents->shouldReceive('understand')->once()->andReturn([
            'route' => 'maintenance',
            'intent' => [
                'supported' => true,
                'entity' => 'ticket',
                'metric' => 'count',
                'group_by' => 'none',
                'period' => 'today',
                'date_basis' => 'none',
                'filter' => ['status' => 'open'],
                'sort' => 'none',
                'limit' => null,
            ],
            'answer' => '',
            'clarification' => '',
            'duration_ms' => 500,
        ]);
        $intents->shouldReceive('isValid')->once()->andReturnTrue();
        $this->app->instance(MiraIntentService::class, $intents);

        $queries = Mockery::mock(MiraQueryService::class);
        $queries->shouldReceive('run')->once()->andThrow(new \RuntimeException('Database unavailable'));
        $this->app->instance(MiraQueryService::class, $queries);

        $telegram = Mockery::mock(MiraTelegramClient::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->with(12345, 'MIRA sedang mengalami gangguan sementara. Silakan coba lagi nanti.');

        app(MiraTelegramMessageHandler::class)->handle($telegram, 1002, [
            'text' => 'Berapa tiket open hari ini?',
            'chat' => ['id' => 12345, 'type' => 'private'],
            'from' => ['id' => 67890, 'is_bot' => false],
        ]);
    }

    public function test_it_ignores_group_messages_without_a_bot_mention(): void
    {
        $assistant = Mockery::mock(MiraAssistantService::class);
        $assistant->shouldNotReceive('answer');
        $this->app->instance(MiraAssistantService::class, $assistant);

        $telegram = Mockery::mock(MiraTelegramClient::class);
        $telegram->shouldNotReceive('sendMessage');

        app(MiraTelegramMessageHandler::class)->handle($telegram, 2001, [
            'text' => 'Berapa tiket open hari ini?',
            'chat' => ['id' => -5398792908, 'type' => 'group'],
            'from' => ['id' => 67890, 'is_bot' => false],
        ]);
    }

    #[DataProvider('groupMentionQuestions')]
    public function test_it_removes_group_mention_before_processing(string $text, string $expectedQuestion): void
    {
        $assistant = Mockery::mock(MiraAssistantService::class);
        $assistant->shouldReceive('answer')->once()->with($expectedQuestion)->andReturn([
            'understood' => true,
            'answer' => 'Hari ini ada 3 tiket yang masih terbuka.',
            'intent' => ['entity' => 'ticket', 'metric' => 'count'],
            'query_type' => 'ticket.count.none',
            'intent_ms' => 500,
            'query_ms' => 2,
            'total_ms' => 503,
        ]);
        $this->app->instance(MiraAssistantService::class, $assistant);

        $telegram = Mockery::mock(MiraTelegramClient::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->with(-5398792908, 'Hari ini ada 3 tiket yang masih terbuka.');

        app(MiraTelegramMessageHandler::class)->handle($telegram, 2002, [
            'text' => $text,
            'chat' => ['id' => -5398792908, 'type' => 'supergroup'],
            'from' => ['id' => 67890, 'is_bot' => false],
        ]);
    }

    public static function groupMentionQuestions(): array
    {
        return [
            'mention at start' => [
                '@mira_dev_bot berapa tiket open hari ini?',
                'berapa tiket open hari ini?',
            ],
            'mention in middle' => [
                'MIRA tolong @mira_dev_bot berapa tiket open hari ini?',
                'MIRA tolong  berapa tiket open hari ini?',
            ],
        ];
    }

    public function test_it_prompts_when_group_message_only_contains_the_mention(): void
    {
        $assistant = Mockery::mock(MiraAssistantService::class);
        $assistant->shouldNotReceive('answer');
        $this->app->instance(MiraAssistantService::class, $assistant);

        $telegram = Mockery::mock(MiraTelegramClient::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->with(-5398792908, 'Silakan tuliskan pertanyaan setelah memanggil MIRA.');

        app(MiraTelegramMessageHandler::class)->handle($telegram, 2003, [
            'text' => '  @mira_dev_bot  ',
            'chat' => ['id' => -5398792908, 'type' => 'group'],
            'from' => ['id' => 67890, 'is_bot' => false],
        ]);
    }

    public function test_private_chat_does_not_require_a_bot_mention(): void
    {
        $question = 'Berapa tiket open hari ini?';
        $assistant = Mockery::mock(MiraAssistantService::class);
        $assistant->shouldReceive('answer')->once()->with($question)->andReturn([
            'understood' => true,
            'answer' => 'Hari ini ada 3 tiket yang masih terbuka.',
            'intent' => ['entity' => 'ticket', 'metric' => 'count'],
            'query_type' => 'ticket.count.none',
            'intent_ms' => 500,
            'query_ms' => 2,
            'total_ms' => 503,
        ]);
        $this->app->instance(MiraAssistantService::class, $assistant);

        $telegram = Mockery::mock(MiraTelegramClient::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->with(12345, 'Hari ini ada 3 tiket yang masih terbuka.');

        app(MiraTelegramMessageHandler::class)->handle($telegram, 2004, [
            'text' => $question,
            'chat' => ['id' => 12345, 'type' => 'private'],
            'from' => ['id' => 67890, 'is_bot' => false],
        ]);
    }

    public function test_start_and_menu_send_an_inline_main_menu(): void
    {
        $assistant = Mockery::mock(MiraAssistantService::class);
        $assistant->shouldNotReceive('answer');
        $this->app->instance(MiraAssistantService::class, $assistant);
        $telegram = Mockery::mock(MiraTelegramClient::class);
        $telegram->shouldReceive('sendMessage')->once()->withArgs(fn ($chatId, $text, $markup) => $chatId === 12345
            && str_contains($text, 'Selamat datang')
            && isset($markup['inline_keyboard'])
            && count($markup['inline_keyboard']) === 3
            && $markup['inline_keyboard'][0][0]['callback_data'] === 'mira:menu:summary'
        );

        app(MiraTelegramMessageHandler::class)->handle($telegram, 3001, [
            'text' => '/start', 'chat' => ['id' => 12345, 'type' => 'private'], 'from' => ['id' => 67890],
        ]);
    }

    public function test_menu_command_also_opens_main_menu(): void
    {
        $telegram = Mockery::mock(MiraTelegramClient::class);
        $telegram->shouldReceive('sendMessage')->once()->withArgs(fn ($chatId, $text, $markup) => $chatId === 12345 && isset($markup['inline_keyboard'][2][1]['callback_data'])
        );
        app(MiraTelegramMessageHandler::class)->handle($telegram, 3004, [
            'text' => '/menu', 'chat' => ['id' => 12345, 'type' => 'private'], 'from' => ['id' => 67890],
        ]);
    }

    public function test_known_menu_callback_runs_database_query_without_calling_assistant(): void
    {
        $assistant = Mockery::mock(MiraAssistantService::class);
        $assistant->shouldNotReceive('answer');
        $this->app->instance(MiraAssistantService::class, $assistant);
        $queries = Mockery::mock(MiraQueryService::class);
        $queries->shouldReceive('runMenuAction')->once()->with('ticket.open', [])->andReturn([
            'query_type' => 'ticket.count.none', 'period' => 'saat ini', 'data' => 4, 'duration_ms' => 1,
        ]);
        $this->app->instance(MiraQueryService::class, $queries);
        $telegram = Mockery::mock(MiraTelegramClient::class);
        $telegram->shouldReceive('answerCallbackQuery')->once()->with('cb-1');
        $telegram->shouldReceive('editMessageText')->once()->withArgs(fn ($chatId, $messageId, $text, $markup) => $chatId === 12345 && $messageId === 77 && str_contains($text, '4 tiket') && isset($markup['inline_keyboard'])
        );

        app(MiraTelegramMessageHandler::class)->handleCallback($telegram, 3002, [
            'id' => 'cb-1', 'data' => 'mira:action:ticket.open',
            'message' => ['message_id' => 77, 'chat' => ['id' => 12345, 'type' => 'private']],
        ]);
    }

    public function test_unknown_callback_is_acknowledged_and_returns_to_main_menu(): void
    {
        $telegram = Mockery::mock(MiraTelegramClient::class);
        $telegram->shouldReceive('answerCallbackQuery')->once()->with('cb-unknown');
        $telegram->shouldReceive('editMessageText')->once()->withArgs(fn ($chatId, $messageId, $text, $markup) => str_contains($text, 'tidak dikenal') && isset($markup['inline_keyboard'])
        );

        app(MiraTelegramMessageHandler::class)->handleCallback($telegram, 3003, [
            'id' => 'cb-unknown', 'data' => 'mira:action:drop_table',
            'message' => ['message_id' => 78, 'chat' => ['id' => 12345, 'type' => 'private']],
        ]);
    }

    public function test_telegram_client_requests_callbacks_and_sends_inline_keyboard_payloads(): void
    {
        config(['services.mira.telegram_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $client = app(MiraTelegramClient::class);
        $client->getUpdates(null);
        $client->sendMessage(12345, 'Menu', ['inline_keyboard' => [[['text' => 'Ringkasan', 'callback_data' => 'mira:menu:summary']]]]);
        $client->answerCallbackQuery('cb-1');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/getUpdates')
            && in_array('callback_query', $request['allowed_updates'], true));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && $request['reply_markup']['inline_keyboard'][0][0]['callback_data'] === 'mira:menu:summary');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/answerCallbackQuery')
            && $request['callback_query_id'] === 'cb-1');
    }
}
