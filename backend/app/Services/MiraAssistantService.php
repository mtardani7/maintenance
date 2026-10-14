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

        try {
            $routing = $this->intents->understand($question);
        } catch (Throwable $exception) {
            Log::warning('MIRA request understanding failed.', ['exception' => $exception::class]);

            return $this->safeClarification('MIRA belum memahami permintaan tersebut. Bisa jelaskan maksudnya?', null, $startedAt);
        }

        $intentMs = $routing['duration_ms'] ?? 0;

        if (($routing['route'] ?? 'invalid') === 'general') {
            return [
                'understood' => true,
                'answer' => $routing['answer'],
                'intent' => null,
                'query_type' => null,
                'intent_ms' => $intentMs,
                'query_ms' => 0,
                'total_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        }

        if (($routing['route'] ?? null) === 'clarify') {
            return $this->safeClarification($routing['clarification'], $intentMs, $startedAt);
        }

        $intent = $routing['intent'] ?? null;

        if (($routing['route'] ?? null) !== 'maintenance'
            || ! is_array($intent)
            || ! $this->intents->isValid($intent)
            || ($intent['supported'] ?? false) !== true) {
            return $this->safeClarification('MIRA belum memahami permintaan tersebut. Bisa jelaskan tiket, incident, mesin, atau periode yang dimaksud?', $intentMs, $startedAt);
        }

        $queryResult = $this->queries->run($intent);

        if ($queryResult === null) {
            return $this->safeClarification('MIRA belum dapat memproses permintaan itu. Bisa berikan detail yang lebih spesifik?', $intentMs, $startedAt, $intent);
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
            'intent_ms' => $intentMs,
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
        return trim((string) preg_replace('/^(?:halo|hai|hi|hallo|selamat\s+pagi|selamat\s+siang|selamat\s+sore|selamat\s+malam)[\s,!:.-]*/iu', '', $question));
    }

    private function safeClarification(string $answer, ?int $intentMs, float $startedAt, ?array $intent = null): array
    {
        return [
            'understood' => false,
            'answer' => trim($answer) !== '' ? $answer : 'Bisa jelaskan maksud pertanyaan Anda?',
            'intent' => $intent,
            'query_type' => null,
            'intent_ms' => $intentMs ?? (int) round((microtime(true) - $startedAt) * 1000),
            'query_ms' => 0,
            'total_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
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
