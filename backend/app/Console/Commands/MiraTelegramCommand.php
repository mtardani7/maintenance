<?php

namespace App\Console\Commands;

use App\Services\MiraAssistantService;
use App\Services\MiraTelegramClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class MiraTelegramCommand extends Command
{
    protected $signature = 'mira:telegram';

    protected $description = 'Poll Telegram messages and answer Maintenance questions with MIRA';

    private bool $shouldStop = false;

    public function handle(MiraTelegramClient $telegram, MiraAssistantService $assistant): int
    {
        if (! config('services.mira.telegram_token')) {
            $this->error('TELEGRAM_BOT_TOKEN is not configured.');

            return self::FAILURE;
        }

        if (! function_exists('pcntl_async_signals')) {
            $this->error('The PHP pcntl extension is required for graceful shutdown.');

            return self::FAILURE;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
        pcntl_signal(SIGINT, fn () => $this->shouldStop = true);

        $offset = Cache::get('mira.telegram.update_offset');
        $offset = is_numeric($offset) ? (int) $offset : null;

        $this->info('MIRA Telegram polling started.');

        while (! $this->shouldStop) {
            try {
                $updates = $telegram->getUpdates($offset);
            } catch (Throwable $exception) {
                Log::warning('MIRA Telegram polling failed; retrying.', [
                    'exception' => $exception::class,
                ]);
                $this->pause(3);

                continue;
            }

            foreach ($updates as $update) {
                if ($this->shouldStop) {
                    break;
                }

                if (! is_array($update) || ! is_numeric($update['update_id'] ?? null)) {
                    continue;
                }

                $updateId = (int) $update['update_id'];

                if ($offset !== null && $updateId < $offset) {
                    continue;
                }

                $message = $update['message'] ?? null;

                if (is_array($message)
                    && is_string($message['text'] ?? null)
                    && trim($message['text']) !== ''
                    && isset($message['chat']['id'])
                    && is_numeric($message['chat']['id'])
                    && ! ($message['from']['is_bot'] ?? false)) {
                    $this->processMessage($telegram, $assistant, $updateId, $message);
                }

                $offset = $updateId + 1;

                try {
                    Cache::forever('mira.telegram.update_offset', $offset);
                } catch (Throwable $exception) {
                    Log::error('MIRA Telegram update offset could not be persisted.', [
                        'update_id' => $updateId,
                        'exception' => $exception::class,
                    ]);
                }
            }
        }

        $this->info('MIRA Telegram polling stopped.');

        return self::SUCCESS;
    }

    private function processMessage(
        MiraTelegramClient $telegram,
        MiraAssistantService $assistant,
        int $updateId,
        array $message,
    ): void {
        $chatId = $message['chat']['id'];
        $userId = is_numeric($message['from']['id'] ?? null) ? (int) $message['from']['id'] : null;
        $startedAt = microtime(true);
        $chatType = $message['chat']['type'] ?? 'private';
        $maintenanceChatId = (string) config('services.mira.telegram_maintenance_chat_id', '');

        if ($chatType !== 'private'
            && ($maintenanceChatId === '' || (string) $chatId !== $maintenanceChatId)) {
            Log::notice('MIRA Telegram ignored a message from an unconfigured group.', [
                'update_id' => $updateId,
                'telegram_user_id' => $userId,
                'chat_id' => $chatId,
            ]);

            return;
        }

        try {
            $result = $assistant->answer(trim($message['text']));

            Log::info('MIRA Telegram question processed.', [
                'update_id' => $updateId,
                'telegram_user_id' => $userId,
                'chat_id' => $chatId,
                'intent' => $result['intent'],
                'query_type' => $result['query_type'],
                'intent_ms' => $result['intent_ms'],
                'query_ms' => $result['query_ms'],
                'ollama_response_ms' => $result['response_ms'],
                'total_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            $telegram->sendMessage($chatId, $result['answer']);
        } catch (Throwable $exception) {
            Log::error('MIRA Telegram question failed.', [
                'update_id' => $updateId,
                'telegram_user_id' => $userId,
                'chat_id' => $chatId,
                'exception' => $exception::class,
                'total_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            try {
                $telegram->sendMessage($chatId, 'MIRA sedang mengalami gangguan sementara. Silakan coba lagi nanti.');
            } catch (Throwable $sendException) {
                Log::error('MIRA Telegram safe error response could not be sent.', [
                    'update_id' => $updateId,
                    'telegram_user_id' => $userId,
                    'chat_id' => $chatId,
                    'exception' => $sendException::class,
                ]);
            }
        }
    }

    private function pause(int $seconds): void
    {
        for ($elapsed = 0; $elapsed < $seconds && ! $this->shouldStop; $elapsed++) {
            sleep(1);
        }
    }
}
