<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MiraQueryService
{
    public function __construct(private readonly MiraIntentService $intentValidator) {}

    public function run(array $intent): ?array
    {
        if (! $this->intentValidator->isValid($intent) || ! $this->supports($intent)) {
            return null;
        }

        $startedAt = microtime(true);
        $connection = DB::connection('pgsql_mira');
        $range = $this->periodRange($intent['period']);

        $data = match ([$intent['entity'], $intent['metric'], $intent['group_by']]) {
            ['ticket', 'count', 'none'] => $this->ticketCount($connection, $intent, $range),
            ['ticket', 'status', 'none'] => $this->ticketStatus($connection, $intent, $range),
            ['incident', 'count', 'none'] => $this->incidentCount($connection, $intent, $range),
            ['incident', 'count', 'machine'] => $this->incidentMachineRanking($connection, $intent, $range),
            ['incident', 'count', 'problem_type'] => $this->incidentProblemRanking($connection, $intent, $range),
            default => null,
        };

        if ($data === null) {
            return null;
        }

        return [
            'query_type' => $intent['entity'].'.'.$intent['metric'].'.'.$intent['group_by'],
            'period' => $this->periodLabel($intent['period']),
            'data' => $data,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    private function supports(array $intent): bool
    {
        $supported = match ([$intent['entity'], $intent['metric'], $intent['group_by']]) {
            ['ticket', 'count', 'none'],
            ['ticket', 'status', 'none'],
            ['incident', 'count', 'none'],
            ['incident', 'count', 'machine'],
            ['incident', 'count', 'problem_type'] => true,
            default => false,
        };

        if (! $supported) {
            return false;
        }

        if ($intent['entity'] === 'ticket'
            && ($intent['filter']['status'] ?? null) === 'resolved') {
            return false;
        }

        if ($intent['entity'] === 'incident'
            && isset($intent['filter']['status'])
            && ! in_array($intent['filter']['status'], ['open', 'resolved'], true)) {
            return false;
        }

        return $intent['group_by'] === 'none'
            ? $intent['sort'] === 'none'
            : in_array($intent['sort'], ['asc', 'desc'], true);
    }

    private function ticketCount(Connection $connection, array $intent, array $range): int
    {
        $query = $this->ticketBaseQuery($connection, $intent, $range);

        return (int) $query->count();
    }

    private function ticketStatus(Connection $connection, array $intent, array $range): array
    {
        return $this->ticketBaseQuery($connection, $intent, $range)
            ->select('t.status')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('t.status')
            ->orderBy('t.status')
            ->get()
            ->map(fn (object $row): array => [
                'status' => strtolower((string) $row->status),
                'count' => (int) $row->total,
            ])
            ->all();
    }

    private function ticketBaseQuery(Connection $connection, array $intent, array $range)
    {
        $query = $connection->table('maintenance_tickets as t')
            ->join('machines as m', 'm.id', '=', 't.machine_id');

        $this->applyPeriod($query, 't.created_at', $range);

        if (isset($intent['filter']['status'])) {
            $query->where('t.status', strtoupper($intent['filter']['status']));
        }

        if (isset($intent['filter']['problem_type'])) {
            $this->applyMachineFilter($query, 't.problem_type', $intent['filter']['problem_type']);
        }

        if (isset($intent['filter']['plant_id'])) {
            $query->where('t.plant_id', (int) $intent['filter']['plant_id']);
        }

        if (isset($intent['filter']['machine'])) {
            $this->applyMachineFilter($query, 'm.name', $intent['filter']['machine']);
        }

        return $query;
    }

    private function incidentCount(Connection $connection, array $intent, array $range): int
    {
        $query = $connection->table('incidents as i')
            ->join('machines as m', 'm.id', '=', 'i.machine_id');
        $this->applyIncidentFilters($query, $intent, $range);

        return (int) $query->count('i.id');
    }

    private function incidentMachineRanking(Connection $connection, array $intent, array $range): array
    {
        $query = $connection->table('incidents as i')
            ->join('machines as m', 'm.id', '=', 'i.machine_id')
            ->leftJoin('plants as p', 'p.id', '=', 'm.plant_id')
            ->select([
                'm.id as machine_id',
                'm.code as machine_code',
                'm.name as machine_name',
                'p.name as plant_name',
            ])
            ->selectRaw('COUNT(i.id) as total');
        $this->applyIncidentFilters($query, $intent, $range);

        return $query->groupBy('m.id', 'm.code', 'm.name', 'p.name')
            ->orderBy('total', $intent['sort'])
            ->orderBy('m.code')
            ->limit($intent['limit'] ?? 5)
            ->get()
            ->map(fn (object $row): array => [
                'machine' => trim((string) $row->machine_code.' - '.(string) $row->machine_name),
                'plant' => $row->plant_name,
                'count' => (int) $row->total,
            ])
            ->all();
    }

    private function incidentProblemRanking(Connection $connection, array $intent, array $range): array
    {
        $query = $connection->table('incidents as i')
            ->join('machines as m', 'm.id', '=', 'i.machine_id')
            ->select('i.problem_type')
            ->selectRaw('COUNT(i.id) as total');
        $this->applyIncidentFilters($query, $intent, $range);

        return $query->groupBy('i.problem_type')
            ->orderBy('total', $intent['sort'])
            ->orderBy('i.problem_type')
            ->limit($intent['limit'] ?? 5)
            ->get()
            ->map(fn (object $row): array => [
                'problem_type' => $row->problem_type,
                'count' => (int) $row->total,
            ])
            ->all();
    }

    private function applyIncidentFilters($query, array $intent, array $range): void
    {
        $this->applyPeriod($query, 'i.created_at', $range);
        $query->whereIn('i.status', ['OPEN', 'RESOLVED']);

        if (isset($intent['filter']['status'])) {
            $query->where('i.status', strtoupper($intent['filter']['status']));
        }

        if (isset($intent['filter']['problem_type'])) {
            $this->applyMachineFilter($query, 'i.problem_type', $intent['filter']['problem_type']);
        }

        if (isset($intent['filter']['plant_id'])) {
            $query->where('m.plant_id', (int) $intent['filter']['plant_id']);
        }

        if (isset($intent['filter']['machine'])) {
            $this->applyMachineFilter($query, 'm.name', $intent['filter']['machine']);
        }
    }

    private function applyMachineFilter($query, string $column, string $value): void
    {
        $query->whereRaw('LOWER('.$column.') LIKE ?', ['%'.mb_strtolower($value).'%']);
    }

    private function applyPeriod($query, string $column, array $range): void
    {
        if ($range['start'] !== null) {
            $query->where($column, '>=', $range['start']);
        }

        if ($range['end'] !== null) {
            $query->where($column, '<', $range['end']);
        }
    }

    private function periodRange(string $period): array
    {
        $now = CarbonImmutable::now();
        $today = $now->startOfDay();

        $start = match ($period) {
            'today' => $today,
            'last_7_days' => $today->subDays(6),
            'last_30_days' => $today->subDays(29),
            'last_90_days' => $today->subDays(89),
            'all' => null,
            default => throw new RuntimeException('Unsupported MIRA query period.'),
        };

        return ['start' => $start, 'end' => $period === 'today' ? $today->addDay() : $now];
    }

    private function periodLabel(string $period): string
    {
        return match ($period) {
            'today' => 'hari ini',
            'last_7_days' => '7 hari terakhir',
            'last_30_days' => '30 hari terakhir',
            'last_90_days' => '90 hari terakhir',
            'all' => 'seluruh periode',
            default => throw new RuntimeException('Unsupported MIRA query period.'),
        };
    }
}
