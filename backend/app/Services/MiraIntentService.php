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
                'content' => 'Pahami pertanyaan Maintenance dan keluarkan hanya JSON sesuai schema; jangan menjawab pertanyaan umum dan jangan membuat SQL. Variasikan bahasa dipetakan ke maksud yang sama. Contoh tiket OPEN hari ini = entity=ticket, metric=count, date_basis=none, period=today, filter.status=open; kata hari ini tidak membatasi tanggal dibuat. Gunakan created_at bila secara eksplisit ditanya dibuat, closed_at bila ditanya ditutup (status closed). Incident periode memakai created_at. Contoh: jumlah mesin aktif => entity=machine, metric=count, filter.is_active=true; jumlah mesin per plant => group_by=plant. Jumlah plant => entity=plant, metric=count. Incident: hitung dengan filter machine/plant; ranking mesin atau plant gunakan group_by terkait dan sort desc/asc sesuai paling sering/sedikit. Distribusi status tiket => entity=ticket, metric=status. Spare part terpakai => entity=spare_part, metric=sum, group_by=spare_part untuk ranking atau none untuk total, periode memakai created_at pada catatan pemakaian. Plant dapat dinyatakan lewat kode/nama dalam filter.plant atau ID dalam filter.plant_id. Mesin gunakan filter.machine. Periode 7/30/90 hari gunakan last_7_days/last_30_days/last_90_days. Jika informasi penting ambigu, clarify=true, supported=false, gunakan intent placeholder (ticket/list/none/all/date_basis none/filter kosong/sort none/limit null) dan ajukan satu klarifikasi spesifik. Pertanyaan umum gunakan supported=false, clarify=false, placeholder sama, clarification kosong. Untuk Maintenance jelas: supported=true, clarify=false, isi intent sesuai schema. Jangan mengarang intent atau SQL.',
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
        $filterKeys = ['status', 'problem_type', 'plant_id', 'plant', 'machine', 'is_active'];

        if (array_diff(array_keys($intent), $allowedKeys) !== []
            || ! is_array($intent['filter'] ?? null)
            || array_diff(array_keys($intent['filter']), $filterKeys) !== []) {
            return false;
        }

        return Validator::make($intent, [
            'supported' => ['required', 'boolean'],
            'entity' => ['required', 'string', 'in:incident,ticket,machine,plant,spare_part'],
            'metric' => ['required', 'string', 'in:count,average,list,status,sum'],
            'group_by' => ['required', 'string', 'in:machine,plant,problem_type,executor,spare_part,none'],
            'period' => ['required', 'string', 'in:today,last_7_days,last_30_days,last_90_days,all'],
            'date_basis' => ['required', 'string', 'in:none,created_at,closed_at'],
            'filter' => ['present', 'array'],
            'filter.status' => ['sometimes', 'string', 'in:open,closed,resolved'],
            'filter.problem_type' => ['sometimes', 'string', 'max:100'],
            'filter.plant_id' => ['sometimes', 'integer', 'min:1'],
            'filter.plant' => ['sometimes', 'string', 'max:100'],
            'filter.machine' => ['sometimes', 'string', 'max:100'],
            'filter.is_active' => ['sometimes', 'boolean'],
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
                'metric' => ['type' => 'string', 'enum' => ['count', 'average', 'list', 'status', 'sum']],
                'group_by' => ['type' => 'string', 'enum' => ['machine', 'plant', 'problem_type', 'executor', 'spare_part', 'none']],
                'period' => ['type' => 'string', 'enum' => ['today', 'last_7_days', 'last_30_days', 'last_90_days', 'all']],
                'date_basis' => ['type' => 'string', 'enum' => ['none', 'created_at', 'closed_at']],
                'filter' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['open', 'closed', 'resolved']],
                        'problem_type' => ['type' => 'string'],
                        'plant_id' => ['type' => 'integer'],
                        'plant' => ['type' => 'string'],
                        'machine' => ['type' => 'string'],
                        'is_active' => ['type' => 'boolean'],
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
