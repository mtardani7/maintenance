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
                'content' => 'Tentukan apakah pertanyaan terkait data Maintenance, umum, atau perlu klarifikasi. Keluarkan hanya JSON sesuai schema; jangan menjawab pertanyaan umum dan jangan membuat SQL. Jika Maintenance, supported=true, clarify=false dan isi intent yang tepat. Contoh "Berapa tiket open hari ini?", "Berapa tiket yang terbuka hari ini?", "Cek jumlah tiket yang masih terbuka hari ini.", "Tolong hitung tiket yang belum selesai hari ini.", "Ada berapa ticket yang statusnya open hari ini?" semuanya: entity=ticket, metric=count, group_by=none, period=today, date_basis=none, filter.status=open, sort=none, limit=null. Ini menghitung seluruh tiket yang saat ini OPEN; kata hari ini tidak berarti tiket dibuat hari ini. Hanya gunakan date_basis=created_at jika pengguna menanyakan tiket yang dibuat/diajukan pada periode itu. Gunakan date_basis=closed_at jika menanyakan tiket yang ditutup/selesai pada periode itu; statusnya closed. Untuk status saat ini tanpa waktu pembuatan/penutupan gunakan date_basis=none dan period=all. Untuk incident, gunakan date_basis=created_at saat periode diminta. Jumlah=count; status terbuka/belum selesai=open; hari ini=today; periode 7/30/90 hari gunakan last_7_days/last_30_days/last_90_days. Untuk peringkat mesin incident gunakan entity=incident, metric=count, group_by=machine, sort=desc. Pertanyaan non-Maintenance: supported=false, clarify=false, gunakan intent placeholder entity=ticket, metric=list, group_by=none, period=all, date_basis=none, filter={}, sort=none, limit=null. Pertanyaan Maintenance yang ambigu/kekurangan detail: supported=false, clarify=true, placeholder intent yang sama, dan clarification berupa satu pertanyaan spesifik. Untuk semua kasus lain, clarification kosong. Jangan mengarang intent tiket untuk pertanyaan umum.',
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

        $route = $response['supported'] ? 'maintenance' : ($response['clarify'] ? 'clarify' : 'general');

        return [
            'route' => $route,
            'intent' => $route === 'maintenance' ? $this->intentFields($response) : null,
            'clarification' => $response['clarification'],
            'duration_ms' => $result['duration_ms'],
        ];
    }

    private function invalidResult(int $durationMs): array
    {
        return ['route' => 'invalid', 'intent' => null, 'clarification' => '', 'duration_ms' => $durationMs];
    }

    private function intentFields(array $response): array
    {
        return array_intersect_key($response, array_flip(['supported', 'entity', 'metric', 'group_by', 'period', 'date_basis', 'filter', 'sort', 'limit']));
    }

    public function isValidResponse(array $response): bool
    {
        $keys = ['supported', 'clarify', 'entity', 'metric', 'group_by', 'period', 'date_basis', 'filter', 'sort', 'limit', 'clarification'];
        $intent = $this->intentFields($response);

        if (array_diff(array_keys($response), $keys) !== []
            || count($intent) !== 9
            || ! $this->isValid($intent)
            || ! is_bool($response['supported'] ?? null)
            || ! is_bool($response['clarify'] ?? null)
            || ! is_string($response['clarification'] ?? null)) {
            return false;
        }

        if ($response['supported'] === true) {
            return $response['clarify'] === false && $response['clarification'] === '';
        }

        $isPlaceholder = $response['entity'] === 'ticket'
            && $response['metric'] === 'list'
            && $response['group_by'] === 'none'
            && $response['period'] === 'all'
            && $response['date_basis'] === 'none'
            && $response['filter'] === []
            && $response['sort'] === 'none'
            && $response['limit'] === null;

        if (! $isPlaceholder) {
            return false;
        }

        return $response['clarify']
            ? trim($response['clarification']) !== ''
            : $response['clarification'] === '';
    }

    public function isValid(array $intent): bool
    {
        $allowedKeys = ['supported', 'entity', 'metric', 'group_by', 'period', 'date_basis', 'filter', 'sort', 'limit'];
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
            'date_basis' => ['required', 'string', 'in:none,created_at,closed_at'],
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
                'supported' => ['type' => 'boolean'],
                'clarify' => ['type' => 'boolean'],
                'entity' => ['type' => 'string', 'enum' => ['incident', 'ticket', 'machine', 'plant', 'spare_part']],
                'metric' => ['type' => 'string', 'enum' => ['count', 'average', 'list', 'status']],
                'group_by' => ['type' => 'string', 'enum' => ['machine', 'plant', 'problem_type', 'executor', 'none']],
                'period' => ['type' => 'string', 'enum' => ['today', 'last_7_days', 'last_30_days', 'last_90_days', 'all']],
                'date_basis' => ['type' => 'string', 'enum' => ['none', 'created_at', 'closed_at']],
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
                'clarification' => ['type' => 'string'],
            ],
            'required' => ['supported', 'clarify', 'entity', 'metric', 'group_by', 'period', 'date_basis', 'filter', 'sort', 'limit', 'clarification'],
            'additionalProperties' => false,
        ];
    }
}
