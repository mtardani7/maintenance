<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use JsonException;

class MiraIntentService
{
    public function __construct(private readonly MiraOllamaClient $ollama) {}

    public function understand(string $question): array
    {
        $result = $this->ollama->generateJson([
            [
                'role' => 'system',
                'content' => 'Ubah pertanyaan operator menjadi intent JSON sesuai skema. Jangan membuat SQL atau penjelasan. Untuk hitungan tiket gunakan entity ticket, metric count, group_by none; kata open menjadi filter.status open. Untuk peringkat mesin dari incident gunakan entity incident, metric count, group_by machine. Untuk peringkat jenis masalah gunakan entity incident, metric count, group_by problem_type. Gunakan sort none jika group_by none dan asc/desc hanya untuk peringkat. Jika filter tidak ada gunakan {} dan limit null. Status gunakan open, closed, atau resolved. Jika pertanyaan tidak didukung, gunakan metric list agar aplikasi menolaknya.',
            ],
            ['role' => 'user', 'content' => mb_substr($question, 0, 500)],
        ], self::schema());

        try {
            $intent = json_decode($result['content'], true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['intent' => null, 'duration_ms' => $result['duration_ms']];
        }

        if (! is_array($intent) || ! $this->isValid($intent)) {
            return ['intent' => null, 'duration_ms' => $result['duration_ms']];
        }

        return ['intent' => $intent, 'duration_ms' => $result['duration_ms']];
    }

    public function isValid(array $intent): bool
    {
        $allowedKeys = ['entity', 'metric', 'group_by', 'period', 'filter', 'sort', 'limit'];
        $filterKeys = ['status', 'problem_type', 'plant_id', 'machine'];

        if (array_diff(array_keys($intent), $allowedKeys) !== []
            || ! is_array($intent['filter'] ?? null)
            || array_diff(array_keys($intent['filter']), $filterKeys) !== []) {
            return false;
        }

        return Validator::make($intent, [
            'entity' => ['required', 'string', 'in:incident,ticket,machine,plant,spare_part'],
            'metric' => ['required', 'string', 'in:count,average,list,status'],
            'group_by' => ['required', 'string', 'in:machine,plant,problem_type,executor,none'],
            'period' => ['required', 'string', 'in:today,last_7_days,last_30_days,last_90_days,all'],
            'filter' => ['present', 'array'],
            'filter.status' => ['sometimes', 'string', 'in:open,closed,resolved'],
            'filter.problem_type' => ['sometimes', 'string', 'max:100'],
            'filter.plant_id' => ['sometimes', 'integer', 'min:1'],
            'filter.machine' => ['sometimes', 'string', 'max:100'],
            'sort' => ['required', 'string', 'in:asc,desc,none'],
            'limit' => ['present', 'nullable', 'integer', 'between:1,10'],
        ])->passes();
    }

    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entity' => ['type' => 'string', 'enum' => ['incident', 'ticket', 'machine', 'plant', 'spare_part']],
                'metric' => ['type' => 'string', 'enum' => ['count', 'average', 'list', 'status']],
                'group_by' => ['type' => 'string', 'enum' => ['machine', 'plant', 'problem_type', 'executor', 'none']],
                'period' => ['type' => 'string', 'enum' => ['today', 'last_7_days', 'last_30_days', 'last_90_days', 'all']],
                'filter' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['open', 'closed', 'resolved']],
                        'problem_type' => ['type' => 'string'],
                        'plant_id' => ['type' => 'integer'],
                        'machine' => ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ],
                'sort' => ['type' => 'string', 'enum' => ['asc', 'desc', 'none']],
                'limit' => ['type' => ['integer', 'null']],
            ],
            'required' => ['entity', 'metric', 'group_by', 'period', 'filter', 'sort', 'limit'],
            'additionalProperties' => false,
        ];
    }
}
