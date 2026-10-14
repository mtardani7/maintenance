<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

class MiraAssistantService
{
    public function __construct(
        private readonly MiraIntentService $intents,
        private readonly MiraQueryService $queries,
        private readonly MiraResponseFormatter $formatter,
        private readonly ?MiraOllamaClient $ollama = null,
    ) {}

    public function answer(string $question): array
    {
        $startedAt = microtime(true);
        $question = trim($question);
        $greeting = $this->greetingReply($question);

        if ($greeting !== null) {
            return $this->directReply($greeting, $startedAt);
        }

        $question = $this->removeLeadingGreeting($question);

        if (! $this->isMaintenanceQuestion($question)) {
            return $this->answerGeneralQuestion($question, $startedAt);
        }

        try {
            $intentResult = $this->intents->understand($question);
        } catch (Throwable $exception) {
            Log::warning('MIRA intent understanding failed.', [
                'exception' => $exception::class,
            ]);

            return [
                'understood' => false,
                'answer' => 'MIRA belum memahami pertanyaan tersebut.',
                'intent' => null,
                'query_type' => null,
                'intent_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'query_ms' => 0,
                'total_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        }

        $intent = $intentResult['intent'];

        if ($intent === null || ! is_array($intent) || ! $this->intents->isValid($intent)) {
            return [
                'understood' => false,
                'answer' => 'MIRA belum memahami pertanyaan tersebut.',
                'intent' => null,
                'query_type' => null,
                'intent_ms' => $intentResult['duration_ms'],
                'query_ms' => 0,
                'total_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        }

        if ($intent['supported'] === false) {
            return [
                'understood' => false,
                'answer' => 'Maaf, saya saat ini fokus membantu informasi Maintenance System, seperti tiket, incident, mesin, plant, dan spare part.',
                'intent' => $intent,
                'query_type' => null,
                'intent_ms' => $intentResult['duration_ms'],
                'query_ms' => 0,
                'total_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        }

        $queryResult = $this->queries->run($intent);

        if ($queryResult === null) {
            return [
                'understood' => false,
                'answer' => 'MIRA belum memahami pertanyaan tersebut.',
                'intent' => $intent,
                'query_type' => null,
                'intent_ms' => $intentResult['duration_ms'],
                'query_ms' => 0,
                'total_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        }

        try {
            $answer = $this->formatter->format($question, $intent, $queryResult);
        } catch (Throwable $exception) {
            Log::error('MIRA response formatting failed.', [
                'query_type' => $queryResult['query_type'],
                'exception' => $exception::class,
            ]);
            $answer = 'Data berhasil diperoleh, tetapi jawaban tidak dapat disusun. Silakan coba lagi.';
        }

        return [
            'understood' => true,
            'answer' => $answer,
            'intent' => $intent,
            'query_type' => $queryResult['query_type'],
            'intent_ms' => $intentResult['duration_ms'],
            'query_ms' => $queryResult['duration_ms'],
            'total_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    private function greetingReply(string $question): ?string
    {
        $normalized = mb_strtolower(trim(preg_replace('/[[:punct:]\s]+/u', '', $question) ?? $question));

        if (in_array($normalized, ['halo', 'hai', 'hi', 'hallo', 'selamatpagi', 'selamatsiang', 'selamatsore', 'selamatmalam'], true)) {
            return 'Halo! Saya MIRA. Ada yang bisa saya bantu?';
        }

        if (in_array($normalized, ['terimakasih', 'makasih', 'thanks', 'thankyou'], true)) {
            return 'Sama-sama!';
        }

        return null;
    }

    private function removeLeadingGreeting(string $question): string
    {
        $question = trim((string) preg_replace('/^(?:halo|hai|hi|hallo|selamat\s+pagi|selamat\s+siang|selamat\s+sore|selamat\s+malam)[\s,!:.-]*/iu', '', $question));

        return $question;
    }

    private function isMaintenanceQuestion(string $question): bool
    {
        return preg_match('/\b(?:maintenance|tiket|tickets?|incident|mesin|machine|plant|spare[ -]?part|perbaikan|kerusakan|downtime)\b/iu', $question) === 1;
    }

    private function answerGeneralQuestion(string $question, float $startedAt): array
    {
        try {
            if ($this->ollama === null) {
                throw new \RuntimeException('MIRA chat client is unavailable.');
            }

            $chat = $this->ollama->chat([
                ['role' => 'system', 'content' => 'Jawab pertanyaan umum secara ringkas dan akurat dalam bahasa yang digunakan pengguna. Jika bahasanya tidak jelas, gunakan Bahasa Indonesia. Jangan mengaku menggunakan data Maintenance System dan jangan membuat klaim bahwa Anda memeriksa database.'],
                ['role' => 'user', 'content' => mb_substr($question, 0, 1000)],
            ]);

            return [
                'understood' => true,
                'answer' => $chat['content'],
                'intent' => null,
                'query_type' => null,
                'intent_ms' => 0,
                'query_ms' => 0,
                'total_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        } catch (Throwable $exception) {
            Log::warning('MIRA general chat failed.', ['exception' => $exception::class]);

            return [
                'understood' => false,
                'answer' => 'MIRA belum dapat menjawab saat ini. Silakan coba lagi.',
                'intent' => null,
                'query_type' => null,
                'intent_ms' => 0,
                'query_ms' => 0,
                'total_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        }
    }

    private function directReply(string $answer, float $startedAt): array
    {
        return [
            'understood' => true,
            'answer' => $answer,
            'intent' => null,
            'query_type' => null,
            'intent_ms' => 0,
            'query_ms' => 0,
            'total_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }
}
