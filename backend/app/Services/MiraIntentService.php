<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use JsonException;

class MiraIntentService
{
    public function __construct(private readonly MiraOllamaClient $ollama) {}

    public function understand(string $question): array
    {
        if ($intent = $this->parseSimpleTicketCount($question)) {
            return ['intent' => $intent, 'duration_ms' => 0];
        }

        $result = $this->ollama->generateJson([
            [
                'role' => 'system',
                'Ubah pertanyaan Maintenance menjadi satu intent JSON sesuai schema. Jangan menjawab, membuat SQL, atau menambahkan kunci. Untuk pertanyaan non-Maintenance gunakan supported=false. Pertanyaan tentang ticket/tiket, incident, machine/mesin, plant, spare part, atau statistik Maintenance didukung. Jumlah tiket open hari ini: {"supported":true,"entity":"ticket","metric":"count","group_by":"none","period":"today","filter":{"status":"open"},"sort":"none","limit":null}. Gunakan metric=count untuk jumlah; status open/terbuka= open, closed/selesai/ditutup=closed; hari ini=today; 7/30/90 hari terakhir=last_7_days/last_30_days/last_90_days. Tanpa pengelompokan, group_by dan sort harus none. Peringkat mesin incident memakai entity=incident, metric=count, group_by=machine, sort=desc. Peringkat jenis masalah memakai group_by=problem_type, sort=desc. Keluarkan JSON saja.',
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

    private function parseSimpleTicketCount(string $question): ?array
    {
        $text = mb_strtolower(trim(preg_replace('/[?!.,]+$/u', '', $question) ?? $question));
        $pattern = '/^(?:berapa|jumlah|hitung)\s+(?:jumlah\s+)?(?:tiket|ticket)\s+(open|terbuka|closed|ditutup|selesai)\s+(hari ini|dalam 7 hari terakhir|7 hari terakhir|dalam 30 hari terakhir|30 hari terakhir)$/u';

        if (preg_match($pattern, $text, $matches) !== 1) {
            return null;
        }

        $status = match ($matches[1]) {
            'open', 'terbuka' => 'open',
            default => 'closed',
        };
        $period = match ($matches[2]) {
            'hari ini' => 'today',
            '7 hari terakhir', 'dalam 7 hari terakhir' => 'last_7_days',
            default => 'last_30_days',
        };

        return [
            'supported' => true,
            'entity' => 'ticket',
            'metric' => 'count',
            'group_by' => 'none',
            'period' => $period,
            'filter' => ['status' => $status],
            'sort' => 'none',
            'limit' => null,
        ];
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
