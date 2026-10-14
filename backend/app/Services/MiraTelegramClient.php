<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class MiraTelegramClient
{
    public function getUpdates(?int $offset): array
    {
        $response = $this->request('getUpdates', array_filter([
            'offset' => $offset,
            'timeout' => 25,
            'allowed_updates' => ['message', 'callback_query'],
        ], fn (mixed $value): bool => $value !== null));

        return $response;
    }

    public function sendMessage(int|string $chatId, string $text, ?array $replyMarkup = null): void
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => mb_substr($text, 0, 4000),
        ];
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }
        $this->request('sendMessage', $payload);
    }

    public function editMessageText(int|string $chatId, int $messageId, string $text, ?array $replyMarkup = null): void
    {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => mb_substr($text, 0, 4000),
        ];
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }
        $this->request('editMessageText', $payload);
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        $payload = ['callback_query_id' => $callbackQueryId];
        if ($text !== null && $text !== '') {
            $payload['text'] = mb_substr($text, 0, 180);
        }
        $this->request('answerCallbackQuery', $payload);
    }

    private function request(string $method, array $payload): array
    {
        $token = (string) config('services.mira.telegram_token');

        if ($token === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        $response = Http::connectTimeout(5)
            ->timeout($method === 'getUpdates' ? 35 : 10)
            ->post("https://api.telegram.org/bot{$token}/{$method}", $payload);

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new RuntimeException('Telegram '.$method.' request failed with HTTP '.$response->status().'.');
        }

        $result = $response->json('result', []);

        return is_array($result) ? $result : [];
    }
}
