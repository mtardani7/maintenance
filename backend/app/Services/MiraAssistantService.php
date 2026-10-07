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

        if ($intent === null) {
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
}
