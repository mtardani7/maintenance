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
            ['machine', 'count', 'none'] => $this->machineCount($connection, $intent),
            ['machine', 'count', 'plant'] => $this->machineCountByPlant($connection, $intent),
            ['plant', 'count', 'none'] => $this->plantCount($connection, $intent),
            ['ticket', 'count', 'none'] => $this->ticketCount($connection, $intent, $range),
            ['ticket', 'status', 'none'] => $this->ticketStatus($connection, $intent, $range),
            ['incident', 'count', 'none'] => $this->incidentCount($connection, $intent, $range),
            ['incident', 'count', 'machine'] => $this->incidentMachineRanking($connection, $intent, $range),
            ['incident', 'count', 'plant'] => $this->incidentPlantRanking($connection, $intent, $range),
            ['incident', 'count', 'problem_type'] => $this->incidentProblemRanking($connection, $intent, $range),
            ['spare_part', 'sum', 'none'] => $this->sparePartQuantity($connection, $intent, $range, false),
            ['spare_part', 'sum', 'spare_part'] => $this->sparePartQuantity($connection, $intent, $range, true),
            default => null,
        };

        if ($data === null) {
            return null;
        }

        return [
            'query_type' => $intent['entity'].'.'.$intent['metric'].'.'.$intent['group_by'],
            'period' => $intent['entity'] === 'ticket' && $intent['date_basis'] === 'none'
                ? 'saat ini'
                : $this->periodLabel($intent['period']),
            'data' => $data,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /** Run a fixed, read-only menu action. Callback data never supplies SQL or column names. */
    public function runMenuAction(string $action, array $parameters = []): ?array
    {
        $intents = [
            'ticket.open' => ['ticket', 'count', 'none', 'all', 'none', ['status' => 'open']],
            'ticket.created_today' => ['ticket', 'count', 'none', 'today', 'created_at', []],
            'ticket.closed_today' => ['ticket', 'count', 'none', 'today', 'closed_at', ['status' => 'closed']],
            'ticket.open_by_plant' => ['ticket', 'count', 'none', 'all', 'none', ['status' => 'open']],
            'incident.today' => ['incident', 'count', 'none', 'today', 'created_at', []],
            'incident.open' => ['incident', 'count', 'none', 'all', 'created_at', ['status' => 'open']],
            'incident.last7' => ['incident', 'count', 'none', 'last_7_days', 'created_at', []],
            'incident.last30' => ['incident', 'count', 'none', 'last_30_days', 'created_at', []],
            'incident.machine_rank7' => ['incident', 'count', 'machine', 'last_7_days', 'created_at', []],
            'incident.problem_rank30' => ['incident', 'count', 'problem_type', 'last_30_days', 'created_at', []],
            'spare.total30' => ['spare_part', 'sum', 'none', 'last_30_days', 'created_at', []],
            'spare.top' => ['spare_part', 'sum', 'spare_part', 'last_30_days', 'created_at', []],
            'machine.count' => ['machine', 'count', 'none', 'all', 'none', []],
            'machine.by_plant' => ['machine', 'count', 'plant', 'all', 'none', []],
            'plant.count' => ['plant', 'count', 'none', 'all', 'none', []],
            'ticket.by_plant' => ['ticket', 'status', 'none', 'all', 'none', []],
        ];

        if (isset($intents[$action])) {
            [$entity, $metric, $groupBy, $period, $dateBasis, $filter] = $intents[$action];
            if ($action === 'ticket.open_by_plant' && isset($parameters['plant_id'])) {
                $filter['plant_id'] = (int) $parameters['plant_id'];
            }
            if ($action === 'ticket.by_plant') {
                $filter = ['plant' => ''];
                unset($filter['plant']);
            }
            if ($action === 'incident.machine_rank7' || $action === 'incident.problem_rank30' || $action === 'machine.by_plant' || $action === 'ticket.by_plant') {
                $sort = 'desc';
            } else {
                $sort = 'none';
            }
            if ($action === 'ticket.by_plant') {
                // Handled below by the dedicated grouped query.
                return ['query_type' => 'menu.ticket.by_plant', 'period' => '', 'data' => $this->ticketPlantSummary(), 'duration_ms' => 0];
            }

            return $this->run([
                'supported' => true, 'entity' => $entity, 'metric' => $metric,
                'group_by' => $groupBy, 'period' => $period, 'date_basis' => $dateBasis,
                'filter' => $filter, 'sort' => $sort,
                'limit' => in_array($action, ['incident.machine_rank7', 'incident.problem_rank30'], true) ? 5 : null,
            ]);
        }

        $connection = DB::connection('pgsql_mira');
        $startedAt = microtime(true);
        $data = match ($action) {
            'ticket.open_list' => $this->openTicketList($connection, (int) ($parameters['page'] ?? 0)),
            'ticket.detail' => $this->ticketDetail($connection, (string) ($parameters['ticket_number'] ?? '')),
            'incident.latest' => $this->latestIncidents($connection, (int) ($parameters['page'] ?? 0)),
            'machine.list' => $this->machinesByPlant($connection, (int) ($parameters['plant_id'] ?? 0), (int) ($parameters['page'] ?? 0)),
            'plant.options' => $connection->table('plants as p')->where('p.is_active', true)->orderBy('p.code')->get(['p.id', 'p.code', 'p.name'])->map(fn ($row) => ['id' => (int) $row->id, 'label' => trim($row->code.' - '.$row->name)])->all(),
            'incident.machine_history' => $this->machineIncidentHistory($connection, (int) ($parameters['machine_id'] ?? 0), (int) ($parameters['page'] ?? 0)),
            'spare.list' => $this->sparePartRecords($connection, (int) ($parameters['page'] ?? 0)),
            default => null,
        };
        if ($data === null) {
            return null;
        }

        return ['query_type' => 'menu.'.$action, 'period' => '', 'data' => $data, 'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000)];
    }

    private function openTicketList(Connection $db, int $page): array
    {
        return $db->table('maintenance_tickets as t')->join('machines as m', 'm.id', '=', 't.machine_id')->leftJoin('plants as p', 'p.id', '=', 't.plant_id')->where('t.status', 'OPEN')->orderByDesc('t.created_at')->offset(max(0, $page) * 10)->limit(10)->get(['t.ticket_number', 't.problem_type', 't.priority', 't.created_at', 'm.code as machine_code', 'm.name as machine_name', 'p.code as plant_code'])->map(fn ($r) => (array) $r)->all();
    }

    private function ticketDetail(Connection $db, string $number): array
    {
        if (! preg_match('/^[A-Za-z0-9_-]{2,80}$/', $number)) {
            return [];
        }
        $row = $db->table('maintenance_tickets as t')->join('machines as m', 'm.id', '=', 't.machine_id')->leftJoin('plants as p', 'p.id', '=', 't.plant_id')->where('t.ticket_number', $number)->first(['t.ticket_number', 't.status', 't.priority', 't.problem_type', 't.description', 't.solution', 't.created_at', 't.closed_at', 'm.code as machine_code', 'm.name as machine_name', 'p.code as plant_code']);

        return $row === null ? [] : [(array) $row];
    }

    private function latestIncidents(Connection $db, int $page): array
    {
        return $db->table('incidents as i')->join('machines as m', 'm.id', '=', 'i.machine_id')->leftJoin('plants as p', 'p.id', '=', 'i.plant_id')->orderByDesc('i.created_at')->offset(max(0, $page) * 10)->limit(10)->get(['i.problem_type', 'i.description', 'i.status', 'i.created_at', 'm.code as machine_code', 'm.name as machine_name', 'p.code as plant_code'])->map(fn ($r) => (array) $r)->all();
    }

    private function machinesByPlant(Connection $db, int $plantId, int $page): array
    {
        if ($plantId < 1) {
            return [];
        }

        return $db->table('machines as m')->where('m.plant_id', $plantId)->orderBy('m.code')->offset(max(0, $page) * 10)->limit(10)->get(['m.id', 'm.code', 'm.name', 'm.is_active'])->map(fn ($r) => (array) $r)->all();
    }

    private function machineIncidentHistory(Connection $db, int $machineId, int $page): array
    {
        if ($machineId < 1) {
            return [];
        }

        return $db->table('incidents as i')->where('i.machine_id', $machineId)->orderByDesc('i.created_at')->offset(max(0, $page) * 10)->limit(10)->get(['i.problem_type', 'i.description', 'i.status', 'i.created_at'])->map(fn ($r) => (array) $r)->all();
    }

    private function sparePartRecords(Connection $db, int $page): array
    {
        return $db->table('maintenance_ticket_spare_parts as s')->join('maintenance_tickets as t', 't.id', '=', 's.maintenance_ticket_id')->orderByDesc('s.created_at')->offset(max(0, $page) * 10)->limit(10)->get(['s.name', 's.material_code', 's.quantity', 's.created_at', 't.ticket_number'])->map(fn ($r) => (array) $r)->all();
    }

    private function ticketPlantSummary(): array
    {
        $rows = DB::connection('pgsql_mira')->table('maintenance_tickets as t')->leftJoin('plants as p', 'p.id', '=', 't.plant_id')->selectRaw("COALESCE(p.code, 'Tanpa plant') as plant")->selectRaw("SUM(CASE WHEN t.status = 'OPEN' THEN 1 ELSE 0 END) as open_count")->selectRaw('COUNT(*) as total')->groupBy('p.code')->orderBy('plant')->limit(20)->get();

        return $rows->map(fn ($r) => ['plant' => (string) $r->plant, 'open' => (int) $r->open_count, 'total' => (int) $r->total])->all();
    }

    private function supports(array $intent): bool
    {
        $supported = match ([$intent['entity'], $intent['metric'], $intent['group_by']]) {
            ['machine', 'count', 'none'],
            ['machine', 'count', 'plant'],
            ['plant', 'count', 'none'],
            ['ticket', 'count', 'none'],
            ['ticket', 'status', 'none'],
            ['incident', 'count', 'none'],
            ['incident', 'count', 'machine'],
            ['incident', 'count', 'plant'],
            ['incident', 'count', 'problem_type'] => true,
            ['spare_part', 'sum', 'none'],
            ['spare_part', 'sum', 'spare_part'] => true,
            default => false,
        };

        if (! $supported) {
            return false;
        }

        $allowedFilters = match ($intent['entity']) {
            'machine' => ['plant_id', 'plant', 'machine', 'is_active'],
            'plant' => ['plant_id', 'plant', 'is_active'],
            'ticket', 'incident' => ['status', 'problem_type', 'plant_id', 'plant', 'machine'],
            'spare_part' => [],
        };

        if (array_diff(array_keys($intent['filter']), $allowedFilters) !== []) {
            return false;
        }

        if (in_array($intent['entity'], ['machine', 'plant'], true)
            && ($intent['period'] !== 'all' || $intent['date_basis'] !== 'none')) {
            return false;
        }

        if ($intent['entity'] === 'spare_part'
            && ($intent['date_basis'] !== 'created_at'
                || array_intersect(array_keys($intent['filter']), ['status', 'problem_type', 'plant_id', 'plant', 'machine', 'is_active']) !== [])) {
            return false;
        }

        if ($intent['entity'] === 'ticket'
            && ($intent['filter']['status'] ?? null) === 'resolved') {
            return false;
        }

        if ($intent['entity'] === 'ticket'
            && $intent['date_basis'] === 'closed_at'
            && ($intent['filter']['status'] ?? null) !== 'closed') {
            return false;
        }

        if ($intent['entity'] === 'ticket'
            && $intent['date_basis'] === 'none'
            && $intent['period'] !== 'all'
            && ($intent['filter']['status'] ?? null) !== 'open') {
            return false;
        }

        if ($intent['entity'] === 'incident' && $intent['date_basis'] !== 'created_at') {
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

    private function machineCount(Connection $connection, array $intent): int
    {
        $query = $connection->table('machines as m')->leftJoin('plants as p', 'p.id', '=', 'm.plant_id');
        $this->applyMachineFilters($query, $intent);

        return (int) $query->count('m.id');
    }

    private function machineCountByPlant(Connection $connection, array $intent): array
    {
        $query = $connection->table('plants as p')
            ->leftJoin('machines as m', 'm.plant_id', '=', 'p.id')
            ->select(['p.id as plant_id', 'p.code as plant_code', 'p.name as plant_name'])
            ->selectRaw('COUNT(m.id) as total');
        $this->applyMachineFilters($query, $intent);

        return $query->groupBy('p.id', 'p.code', 'p.name')
            ->orderBy('p.code')
            ->get()
            ->map(fn (object $row): array => [
                'plant' => trim((string) $row->plant_code.' - '.(string) $row->plant_name),
                'count' => (int) $row->total,
            ])
            ->all();
    }

    private function plantCount(Connection $connection, array $intent): int
    {
        $query = $connection->table('plants as p');

        if (isset($intent['filter']['is_active'])) {
            $query->where('p.is_active', (bool) $intent['filter']['is_active']);
        }
        if (isset($intent['filter']['plant_id'])) {
            $query->where('p.id', (int) $intent['filter']['plant_id']);
        }
        $this->applyPlantFilter($query, $intent, 'p.code', 'p.name');

        return (int) $query->count('p.id');
    }

    private function applyMachineFilters($query, array $intent): void
    {
        if (isset($intent['filter']['is_active'])) {
            $query->where('m.is_active', (bool) $intent['filter']['is_active']);
        }
        if (isset($intent['filter']['plant_id'])) {
            $query->where('m.plant_id', (int) $intent['filter']['plant_id']);
        }

        if (isset($intent['filter']['machine'])) {
            $this->applyMachineFilter($query, 'm.name', $intent['filter']['machine']);
        }
        $this->applyPlantFilter($query, $intent, 'p.code', 'p.name');
    }

    private function applyPlantFilter($query, array $intent, string $codeColumn, string $nameColumn): void
    {
        if (isset($intent['filter']['plant'])) {
            $value = '%'.mb_strtolower($intent['filter']['plant']).'%';
            $query->where(fn ($nested) => $nested
                ->whereRaw('LOWER('.$codeColumn.') LIKE ?', [$value])
                ->orWhereRaw('LOWER('.$nameColumn.') LIKE ?', [$value]));
        }
    }

    private function sparePartQuantity(Connection $connection, array $intent, array $range, bool $grouped): mixed
    {
        $query = $connection->table('maintenance_ticket_spare_parts as s')
            ->selectRaw('SUM(s.quantity) as total');
        $this->applyPeriod($query, 's.created_at', $range);

        if ($grouped) {
            $query->addSelect('s.name', 's.material_code')
                ->groupBy('s.name', 's.material_code')
                ->orderBy('total', $intent['sort'])
                ->orderBy('s.name')
                ->limit($intent['limit'] ?? 5);

            return $query->get()->map(fn (object $row): array => [
                'name' => (string) $row->name,
                'material_code' => (string) $row->material_code,
                'quantity' => (int) $row->total,
            ])->all();
        }

        return (int) ($query->value('total') ?? 0);
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
            ->join('machines as m', 'm.id', '=', 't.machine_id')
            ->leftJoin('plants as p', 'p.id', '=', 't.plant_id');

        $dateColumn = match ($intent['date_basis']) {
            'created_at' => 't.created_at',
            'closed_at' => 't.closed_at',
            default => null,
        };

        if ($dateColumn !== null) {
            $this->applyPeriod($query, $dateColumn, $range);
        }

        if (isset($intent['filter']['status'])) {
            $query->where('t.status', strtoupper($intent['filter']['status']));
        }

        if (isset($intent['filter']['problem_type'])) {
            $this->applyMachineFilter($query, 't.problem_type', $intent['filter']['problem_type']);
        }

        if (isset($intent['filter']['plant_id'])) {
            $query->where('t.plant_id', (int) $intent['filter']['plant_id']);
        }

        $this->applyPlantFilter($query, $intent, 'p.code', 'p.name');

        if (isset($intent['filter']['machine'])) {
            $this->applyMachineFilter($query, 'm.name', $intent['filter']['machine']);
        }

        return $query;
    }

    private function incidentCount(Connection $connection, array $intent, array $range): int
    {
        $query = $connection->table('incidents as i')
            ->join('machines as m', 'm.id', '=', 'i.machine_id')
            ->leftJoin('plants as p', 'p.id', '=', 'm.plant_id');
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
            ->leftJoin('plants as p', 'p.id', '=', 'm.plant_id')
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

    private function incidentPlantRanking(Connection $connection, array $intent, array $range): array
    {
        $query = $connection->table('incidents as i')
            ->join('machines as m', 'm.id', '=', 'i.machine_id')
            ->join('plants as p', 'p.id', '=', 'm.plant_id')
            ->select(['p.id as plant_id', 'p.code as plant_code', 'p.name as plant_name'])
            ->selectRaw('COUNT(i.id) as total');
        $this->applyIncidentFilters($query, $intent, $range);

        return $query->groupBy('p.id', 'p.code', 'p.name')
            ->orderBy('total', $intent['sort'])
            ->orderBy('p.code')
            ->limit($intent['limit'] ?? 5)
            ->get()
            ->map(fn (object $row): array => [
                'plant' => trim((string) $row->plant_code.' - '.(string) $row->plant_name),
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

        $this->applyPlantFilter($query, $intent, 'p.code', 'p.name');

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
