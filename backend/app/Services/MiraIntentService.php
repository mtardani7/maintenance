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
                'content' => 'Tugasmu mengubah pertanyaan menjadi intent JSON sesuai schema. Jangan menjawab dengan kalimat dan jangan membuat SQL.

                Pertanyaan tentang ticket, incident, machine, plant, spare_part, status maintenance, dan statistik maintenance adalah supported=true. Gunakan supported=false hanya jika pertanyaan jelas tidak berhubungan dengan Maintenance System.

                Contoh: Berapa tiket open hari ini? menghasilkan supported=true, entity=ticket, metric=count, group_by=none, period=today, filter={"status":"open"}, sort=none, limit=null.

                Contoh: Siapa presiden saat ini? menghasilkan supported=false, entity=ticket, metric=list, group_by=none, period=all, filter={}, sort=none, limit=null.

                Aturan:
                - Pertanyaan jumlah menggunakan metric=count.
                - Open atau terbuka berarti filter.status=open.
                - Closed atau ditutup berarti filter.status=closed.
                - Hari ini berarti period=today.
                - 7, 30, atau 90 hari terakhir berarti last_7_days, last_30_days, atau last_90_days.
                - Hitungan tanpa pengelompokan menggunakan group_by=none dan sort=none.
                - Peringkat mesin berdasarkan incident menggunakan entity=incident, metric=count, group_by=machine, sort=desc.
                - Peringkat jenis masalah menggunakan entity=incident, metric=count, group_by=problem_type, sort=desc.

                Keluarkan JSON saja sesuai schema.',
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

        if ($intent['group_by'] === 'none') {
            $intent['sort'] = 'none';
        }

        if ($intent['supported'] === false) {
            $intent = [
                'supported' => false,
                'entity' => 'ticket',
                'metric' => 'list',
                'group_by' => 'none',
                'period' => 'all',
                'filter' => [],
                'sort' => 'none',
                'limit' => null,
            ];
        }

        return ['intent' => $intent, 'duration_ms' => $result['duration_ms']];
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
            ],
            'required' => ['supported', 'entity', 'metric', 'group_by', 'period', 'filter', 'sort', 'limit'],
            'additionalProperties' => false,
        ];
    }
}
