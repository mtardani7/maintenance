<?php

namespace App\Services;

use RuntimeException;

class MiraAssistantService
{
    public function __construct(
        private readonly MiraIntentService $intents,
        private readonly MiraQueryService $queries,
        private readonly MiraOllamaClient $ollama,
    ) {}

    public function answer(string $question): array
    {
        $intentResult = $this->intents->understand($question);
        $intent = $intentResult['intent'];

        if ($intent === null) {
            return [
                'understood' => false,
                'answer' => 'MIRA belum memahami pertanyaan tersebut.',
                'intent' => null,
                'query_type' => null,
                'intent_ms' => $intentResult['duration_ms'],
                'query_ms' => 0,
                'response_ms' => 0,
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
                'response_ms' => 0,
            ];
        }

        $response = $this->composeAnswer($question, $queryResult);

        return [
            'understood' => true,
            'answer' => $response['answer'],
            'intent' => $intent,
            'query_type' => $queryResult['query_type'],
            'intent_ms' => $intentResult['duration_ms'],
            'query_ms' => $queryResult['duration_ms'],
            'response_ms' => $response['duration_ms'],
        ];
    }

    public function composeAnswer(string $question, array $queryResult): array
    {
        $response = $this->ollama->generateJson([
            [
                'role' => 'system',
                'content' => 'Jawab dalam Bahasa Indonesia, singkat dan natural untuk operator. Gunakan hanya angka dan label pada data yang diberikan. Jangan mengarang, menjelaskan query, menyebut SQL atau database, atau memberikan reasoning. Kembalikan hanya JSON sesuai skema.',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'pertanyaan' => mb_substr($question, 0, 500),
                    'periode' => $queryResult['period'],
                    'hasil' => $queryResult['data'],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ],
        ], [
            'type' => 'object',
            'properties' => [
                'answer' => ['type' => 'string'],
            ],
            'required' => ['answer'],
            'additionalProperties' => false,
        ]);

        $decoded = json_decode($response['content'], true);
        $answer = is_array($decoded) ? trim((string) ($decoded['answer'] ?? '')) : '';

        if ($answer === '') {
            throw new RuntimeException('MIRA Ollama returned an invalid answer.');
        }

        return [
            'answer' => mb_substr($answer, 0, 350),
            'duration_ms' => $response['duration_ms'],
        ];
    }
}
