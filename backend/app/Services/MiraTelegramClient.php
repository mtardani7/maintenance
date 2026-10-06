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
            'allowed_updates' => ['message'],
        ], fn (mixed $value): bool => $value !== null));

        return $response;
    }

    public function sendMessage(int|string $chatId, string $text): void
    {
        $this->request('sendMessage', [
            'chat_id' => $chatId,
            'text' => mb_substr($text, 0, 4000),
        ]);
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
