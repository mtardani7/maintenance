<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class MiraOllamaClient
{
    public function generateJson(array $messages, array $schema): array
    {
        $baseUrl = rtrim((string) config('services.mira.ollama_url'), '/');
        $model = (string) config('services.mira.ollama_model');

        if ($baseUrl === '' || $model === '') {
            throw new RuntimeException('MIRA Ollama configuration is incomplete.');
        }

        $startedAt = microtime(true);
        $response = Http::connectTimeout(4)
            ->timeout(90)
            ->post($baseUrl.'/api/chat', [
                'model' => $model,
                'messages' => $messages,
                'format' => $schema,
                'stream' => false,
                'keep_alive' => 0,
                'options' => [
                    'num_ctx' => 2048,
                    'num_predict' => 180,
                    'temperature' => 0,
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('MIRA Ollama request failed with HTTP '.$response->status().'.');
        }

        $content = $response->json('message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('MIRA Ollama returned an empty response.');
        }

        return [
            'content' => trim($content),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    public function check(): void
    {
        $baseUrl = rtrim((string) config('services.mira.ollama_url'), '/');
        $model = (string) config('services.mira.ollama_model');

        if ($baseUrl === '' || $model === '') {
            throw new RuntimeException('OLLAMA_URL or OLLAMA_MODEL is not configured.');
        }

        $response = Http::connectTimeout(4)->timeout(8)->get($baseUrl.'/api/tags');

        if (! $response->successful()) {
            throw new RuntimeException('Ollama returned HTTP '.$response->status().'.');
        }

        $models = collect($response->json('models', []))
            ->pluck('name')
            ->filter(fn (mixed $name): bool => is_string($name))
            ->all();

        if (! in_array($model, $models, true) && ! in_array($model.':latest', $models, true)) {
            throw new RuntimeException('Configured Ollama model is not installed.');
        }
    }
}
