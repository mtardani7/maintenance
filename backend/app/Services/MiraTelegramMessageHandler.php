<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

class MiraTelegramMessageHandler
{
    public function __construct(private readonly MiraAssistantService $assistant) {}

    public function handle(MiraTelegramClient $telegram, int $updateId, array $message): void
    {
        $chatId = $message['chat']['id'];
        $userId = is_numeric($message['from']['id'] ?? null) ? (int) $message['from']['id'] : null;
        $startedAt = microtime(true);
        $chatType = $message['chat']['type'] ?? 'private';
        $maintenanceChatId = (string) config('services.mira.telegram_maintenance_chat_id', '');
        $question = trim((string) ($message['text'] ?? ''));

        if ($chatType !== 'private'
            && ($maintenanceChatId === '' || (string) $chatId !== $maintenanceChatId)) {
            Log::notice('MIRA Telegram ignored a message from an unconfigured group.', [
                'update_id' => $updateId,
                'telegram_user_id' => $userId,
                'chat_id' => $chatId,
            ]);

            return;
        }

        if (in_array($chatType, ['group', 'supergroup'], true)) {
            $username = trim((string) config('services.mira.telegram_bot_username', ''));
            $mentionPattern = $username === ''
                ? null
                : '/(?<![\p{L}\p{N}_])@'.preg_quote(ltrim($username, '@'), '/').'(?![\p{L}\p{N}_])/iu';

            if ($mentionPattern === null || preg_match($mentionPattern, $question) !== 1) {
                return;
            }

            $question = trim((string) preg_replace($mentionPattern, '', $question));

            if ($question === '') {
                $telegram->sendMessage($chatId, 'Silakan tuliskan pertanyaan setelah memanggil MIRA.');

                return;
            }
        }

        try {
            $result = $this->assistant->answer($question);
            $totalMs = $result['total_ms'] ?? (int) round((microtime(true) - $startedAt) * 1000);

            Log::info('MIRA Telegram question processed.', [
                'update_id' => $updateId,
                'telegram_user_id' => $userId,
                'chat_id' => $chatId,
                'intent' => $result['intent'],
                'query_type' => $result['query_type'],
                'intent_ms' => $result['intent_ms'],
                'query_ms' => $result['query_ms'],
                'total_ms' => $totalMs,
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
}
