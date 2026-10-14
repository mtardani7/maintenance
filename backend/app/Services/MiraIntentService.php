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
                'content' => 'Klasifikasikan permintaan ke route maintenance, general, atau clarify. Keluarkan satu JSON sesuai schema, tanpa SQL. Untuk maintenance, pahami maksud dan variasi bahasa, bukan kecocokan kata persis. "Berapa tiket open hari ini?", "Berapa tiket yang terbuka hari ini?", "Cek jumlah tiket yang masih terbuka hari ini.", "Tolong hitung tiket yang belum selesai hari ini.", dan "Ada berapa ticket yang statusnya open hari ini?" memiliki intent sama: route=maintenance, supported=true, entity=ticket, metric=count, group_by=none, period=today, filter.status=open, sort=none, limit=null. Pertanyaan umum memakai route=general dan jawaban ringkas dalam bahasa pengguna; jangan mengaku punya akses internet, berita terkini, atau data database. Permintaan Maintenance yang ambigu atau kekurangan informasi penting memakai route=clarify dan clarification berisi pertanyaan spesifik. Untuk general/clarify isi intent dengan placeholder: supported=false, entity=ticket, metric=list, group_by=none, period=all, filter={}, sort=none, limit=null. answer atau clarification yang tidak dipakai harus string kosong. Jangan mengarang intent Maintenance untuk pertanyaan umum.',
            ],
            ['role' => 'user', 'content' => mb_substr($question, 0, 500)],
        ], self::schema());

        try {
            $response = json_decode($result['content'], true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->invalidResult($result['duration_ms']);
        }

        if (! is_array($response) || ! $this->isValidResponse($response)) {
            return $this->invalidResult($result['duration_ms']);
        }

        if ($response['group_by'] === 'none') {
            $response['sort'] = 'none';
        }

        return [
            'route' => $response['route'],
            'intent' => $response['route'] === 'maintenance' ? $this->intentFields($response) : null,
            'answer' => $response['answer'],
            'clarification' => $response['clarification'],
            'duration_ms' => $result['duration_ms'],
        ];
    }

    private function invalidResult(int $durationMs): array
    {
        return ['route' => 'invalid', 'intent' => null, 'answer' => '', 'clarification' => '', 'duration_ms' => $durationMs];
    }

    private function intentFields(array $response): array
    {
        return array_intersect_key($response, array_flip(['supported', 'entity', 'metric', 'group_by', 'period', 'filter', 'sort', 'limit']));
    }

    public function isValidResponse(array $response): bool
    {
        $keys = ['route', 'supported', 'entity', 'metric', 'group_by', 'period', 'filter', 'sort', 'limit', 'answer', 'clarification'];
        $intent = $this->intentFields($response);

        if (array_diff(array_keys($response), $keys) !== []
            || count($intent) !== 8
            || ! $this->isValid($intent)
            || ! in_array($response['route'] ?? null, ['maintenance', 'general', 'clarify'], true)
            || ! is_string($response['answer'] ?? null)
            || ! is_string($response['clarification'] ?? null)) {
            return false;
        }

        if ($response['route'] === 'maintenance') {
            return $response['supported'] === true;
        }

        $placeholderIntent = $response['supported'] === false
            && $response['entity'] === 'ticket'
            && $response['metric'] === 'list'
            && $response['group_by'] === 'none'
            && $response['period'] === 'all'
            && $response['filter'] === []
            && $response['sort'] === 'none'
            && $response['limit'] === null;

        if (! $placeholderIntent) {
            return false;
        }

        return $response['route'] === 'general'
            ? trim($response['answer']) !== ''
            : trim($response['clarification']) !== '';
    }

    public function isValid(array $intent): bool
    {
        $allowedKeys = ['supported', 'entity', 'metric', 'group_by', 'period', 'filter', 'sort', 'limit'];
        $filterKeys = ['status', 'problem_type', 'plant_id', 'machine'];

        if (array_diff(array_keys($intent), $allowedKeys) !== []
            || ! is_array($intent['filter'] ?? null)
            || array_diff(array_keys($intent['filter']), $filterKeys) !== []) {
            return false;
        }

        return Validator::make($intent, [
            'supported' => ['required', 'boolean'],
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
                'route' => ['type' => 'string', 'enum' => ['maintenance', 'general', 'clarify']],
                'supported' => ['type' => 'boolean'],
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
                'answer' => ['type' => 'string'],
                'clarification' => ['type' => 'string'],
            ],
            'required' => ['route', 'supported', 'entity', 'metric', 'group_by', 'period', 'filter', 'sort', 'limit', 'answer', 'clarification'],
            'additionalProperties' => false,
        ];
    }
}
