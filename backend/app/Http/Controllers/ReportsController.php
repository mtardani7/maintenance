<?php

namespace App\Http\Controllers;

use App\Services\ReportsExcelWorkbook;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    private function filters(Request $request): array
    {
        abort_unless(in_array($request->user()?->role, ['supervisor', 'qa', 'admin'], true), 403);
        $data = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'plant_id' => ['nullable', 'integer', 'exists:plants,id'],
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'category' => ['nullable', 'in:summary,tickets,incidents,machines,spare-parts,downtime'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'in:newest,oldest,incidents_desc,incidents_asc'],
        ]);
        if (! empty($data['machine_id']) && ! empty($data['plant_id'])) {
            abort_unless(DB::table('machines')->where('id', $data['machine_id'])->where('plant_id', $data['plant_id'])->exists(), 422, 'Mesin tidak termasuk dalam plant yang dipilih.');
        }
        $data['start_date'] ??= now('Asia/Jakarta')->subDays(29)->toDateString();
        $data['end_date'] ??= now('Asia/Jakarta')->toDateString();
        abort_if(Carbon::parse($data['start_date'])->diffInDays(Carbon::parse($data['end_date'])) > 1826, 422, 'Rentang laporan maksimal lima tahun.');

        return $data;
    }

    private function withinPeriod(Builder $query, string $column, array $filters): Builder
    {
        $from = Carbon::parse($filters['start_date'], 'Asia/Jakarta')->startOfDay()->utc();
        $until = Carbon::parse($filters['end_date'], 'Asia/Jakarta')->startOfDay()->addDay()->utc();

        return $query->where($column, '>=', $from)->where($column, '<', $until);
    }

    private function tickets(array $filters, bool $inPeriod = true): Builder
    {
        $query = DB::table('maintenance_tickets as t')
            ->leftJoin('plants as p', 'p.id', '=', 't.plant_id')
            ->leftJoin('machines as m', 'm.id', '=', 't.machine_id')
            ->leftJoin('users as u', 'u.id', '=', 't.executor_id')
            ->when($filters['plant_id'] ?? null, fn ($q, $id) => $q->where('t.plant_id', $id))
            ->when($filters['machine_id'] ?? null, fn ($q, $id) => $q->where('t.machine_id', $id));

        return $inPeriod ? $this->withinPeriod($query, 't.created_at', $filters) : $query;
    }

    private function incidents(array $filters): Builder
    {
        $query = DB::table('incidents as i')
            ->leftJoin('plants as p', 'p.id', '=', 'i.plant_id')
            ->leftJoin('machines as m', 'm.id', '=', 'i.machine_id')
            ->leftJoin('maintenance_tickets as t', 't.id', '=', 'i.maintenance_ticket_id')
            ->when($filters['plant_id'] ?? null, fn ($q, $id) => $q->where('i.plant_id', $id))
            ->when($filters['machine_id'] ?? null, fn ($q, $id) => $q->where('i.machine_id', $id));

        return $this->withinPeriod($query, 'i.created_at', $filters);
    }

    private function downtimeWork(array $filters, bool $includeIncomplete = false): Builder
    {
        return DB::table('maintenance_tickets as t')
            ->join('machines as m', 'm.id', '=', 't.machine_id')
            ->leftJoin('plants as p', 'p.id', '=', 'm.plant_id')
            ->leftJoin('users as u', 'u.id', '=', 't.executor_id')
            ->where('t.status', 'CLOSED')
            ->whereNotNull('t.closed_at')
            ->when($filters['plant_id'] ?? null, fn ($q, $id) => $q->where('m.plant_id', $id))
            ->when($filters['machine_id'] ?? null, fn ($q, $id) => $q->where('t.machine_id', $id))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->whereRaw('LOWER(t.ticket_number) LIKE ?', ['%'.strtolower($s).'%']))
            ->when($includeIncomplete, fn ($q) => $q->where(fn ($valid) => $valid
                ->where(fn ($partial) => $partial
                    ->where(fn ($startMissing) => $startMissing->whereNull('t.actual_downtime_started_at')->whereNotNull('t.actual_downtime_ended_at'))
                    ->orWhere(fn ($endMissing) => $endMissing->whereNotNull('t.actual_downtime_started_at')->whereNull('t.actual_downtime_ended_at')))
                ->orWhere(fn ($complete) => $complete->whereNotNull('t.duration_hours')->where('t.duration_hours', '>', 0)
                    ->where(fn ($timestamps) => $timestamps
                        ->where(fn ($legacy) => $legacy->whereNull('t.actual_downtime_started_at')->whereNull('t.actual_downtime_ended_at'))
                        ->orWhere(fn ($actual) => $actual->whereNotNull('t.actual_downtime_started_at')->whereNotNull('t.actual_downtime_ended_at'))))))
            ->when(! $includeIncomplete, fn ($q) => $q->whereNotNull('t.duration_hours')->where('t.duration_hours', '>', 0)
                ->where(fn ($valid) => $valid
                    ->where(fn ($legacy) => $legacy->whereNull('t.actual_downtime_started_at')->whereNull('t.actual_downtime_ended_at'))
                    ->orWhere(fn ($actual) => $actual->whereNotNull('t.actual_downtime_started_at')->whereNotNull('t.actual_downtime_ended_at'))))
            ->whereRaw('COALESCE(t.actual_downtime_ended_at, t.closed_at) >= ?', [Carbon::parse($filters['start_date'], 'Asia/Jakarta')->startOfDay()->utc()])
            ->whereRaw('COALESCE(t.actual_downtime_ended_at, t.closed_at) < ?', [Carbon::parse($filters['end_date'], 'Asia/Jakarta')->startOfDay()->addDay()->utc()]);
    }

    private function series(Builder $query, string $column, string $alias, array $filters, bool $monthly): array
    {
        $driver = DB::connection()->getDriverName();
        $key = $monthly
            ? ($driver === 'sqlite' ? "strftime('%Y-%m', datetime({$column}, '+7 hours'))" : "TO_CHAR(DATE_TRUNC('month', {$column} + INTERVAL '7 hours'), 'YYYY-MM')")
            : ($driver === 'sqlite' ? "DATE(datetime({$column}, '+7 hours'))" : "CAST(({$column} + INTERVAL '7 hours') AS DATE)");
        $items = $query->selectRaw("{$key} as period, COUNT(*) as total")
            ->groupByRaw($key)->orderBy('period')->get()->keyBy('period');
        $start = Carbon::parse($filters['start_date'])->startOfDay();
        $end = Carbon::parse($filters['end_date'])->startOfDay();
        $cursor = $monthly ? $start->copy()->startOfMonth() : $start;
        $last = $monthly ? $end->copy()->startOfMonth() : $end;
        $result = [];
        while ($cursor <= $last) {
            $period = $monthly ? $cursor->format('Y-m') : $cursor->toDateString();
            $result[] = ['period' => $period, $alias => (int) ($items->get($period)?->total ?? 0)];
            $cursor->add($monthly ? 'month' : 'day', 1);
        }

        return $result;
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $monthly = Carbon::parse($filters['start_date'])->diffInDays(Carbon::parse($filters['end_date'])) > 60;
        $ticketBase = $this->tickets($filters);
        $incidentBase = $this->incidents($filters);
        $createdTrend = $this->series(clone $ticketBase, 't.created_at', 'created', $filters, $monthly);
        $incidentTrend = $this->series(clone $incidentBase, 'i.created_at', 'total', $filters, $monthly);
        $closedPeriod = $this->withinPeriod($this->tickets($filters, false)->where('t.status', 'CLOSED')->whereNotNull('t.closed_at'), 't.closed_at', $filters);
        $closedTrend = $this->series(clone $closedPeriod, 't.closed_at', 'closed', $filters, $monthly);
        $closedByPeriod = collect($closedTrend)->keyBy('period');
        $trend = collect($createdTrend)->map(fn ($row) => [...$row, 'closed' => $closedByPeriod->get($row['period'])['closed'] ?? 0])->values();

        $summary = [
            'tickets_created' => (clone $ticketBase)->count(),
            'tickets_open_now' => $this->tickets($filters, false)->where('t.status', 'OPEN')->count(),
            'tickets_closed' => (clone $closedPeriod)->count(),
            'incidents' => (clone $incidentBase)->count(),
        ];
        $statusDistribution = (clone $ticketBase)->select('t.status')->selectRaw('COUNT(*) as total')->groupBy('t.status')->orderBy('t.status')->get();
        $topProblemTypes = (clone $incidentBase)->select('i.problem_type')->selectRaw('COUNT(*) as total')->groupBy('i.problem_type')->orderByDesc('total')->limit(10)->get();
        $topMachines = (clone $incidentBase)->select('i.machine_id', 'm.code', 'm.name', 'p.name as plant')->selectRaw('COUNT(i.id) as total')->groupBy('i.machine_id', 'm.code', 'm.name', 'p.name')->orderByDesc('total')->limit(10)->get();
        $incidentsByPlant = (clone $incidentBase)->select('i.plant_id', 'p.code', 'p.name')->selectRaw('COUNT(i.id) as total')->groupBy('i.plant_id', 'p.code', 'p.name')->orderByDesc('total')->get();
        $partBase = $this->withinPeriod(DB::table('maintenance_ticket_spare_parts as s')->join('maintenance_tickets as t', 't.id', '=', 's.maintenance_ticket_id')->leftJoin('machines as m', 'm.id', '=', 't.machine_id')->leftJoin('plants as p', 'p.id', '=', 't.plant_id')->when($filters['plant_id'] ?? null, fn ($q, $id) => $q->where('t.plant_id', $id))->when($filters['machine_id'] ?? null, fn ($q, $id) => $q->where('t.machine_id', $id)), 's.created_at', $filters);
        $machineBase = DB::table('machines as m')->leftJoin('plants as p', 'p.id', '=', 'm.plant_id')->where('m.is_active', true)->when($filters['plant_id'] ?? null, fn ($q, $id) => $q->where('m.plant_id', $id))->when($filters['machine_id'] ?? null, fn ($q, $id) => $q->where('m.id', $id))->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->whereRaw('LOWER(m.code) LIKE ?', ['%'.strtolower($s).'%'])->orWhereRaw('LOWER(m.name) LIKE ?', ['%'.strtolower($s).'%'])))->select('m.id', 'm.code', 'm.name', 'p.code as plant_code', 'p.name as plant_name')->selectSub(fn ($q) => $this->withinPeriod($q->from('incidents as ix')->whereColumn('ix.machine_id', 'm.id'), 'ix.created_at', $filters)->selectRaw('COUNT(*)'), 'incident_count')->orderBy('incident_count', ($filters['sort'] ?? 'incidents_desc') === 'incidents_asc' ? 'asc' : 'desc');
        $partsTop = (clone $partBase)->select('s.name', 's.material_code')->selectRaw('COUNT(*) as transactions, SUM(s.quantity) as quantity')->groupBy('s.name', 's.material_code')->orderByDesc('quantity')->limit(10)->get();

        $category = $filters['category'] ?? 'summary';
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);
        $rows = null;
        if ($category === 'tickets') {
            $query = (clone $ticketBase)->select('t.id', 't.ticket_number', 't.created_at', 't.problem_type', 't.description', 't.status', 't.closed_at', 'p.name as plant', 'm.code as machine_code', 'm.name as machine', 'u.name as executor')->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->whereRaw('LOWER(t.ticket_number) LIKE ?', ['%'.strtolower($s).'%'])->orWhereRaw('LOWER(m.name) LIKE ?', ['%'.strtolower($s).'%'])->orWhereRaw('LOWER(t.problem_type) LIKE ?', ['%'.strtolower($s).'%'])))->orderBy('t.created_at', ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc');
            $rows = $query->paginate($perPage, ['*'], 'page', $page);
        } elseif ($category === 'incidents') {
            $query = (clone $incidentBase)->select('i.id', 'i.created_at', 'i.problem_type', 'i.description', 'i.status', 'i.plant_id', 'i.machine_id', 'p.name as plant', 'm.code as machine_code', 'm.name as machine', 't.ticket_number')->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->whereRaw('LOWER(m.name) LIKE ?', ['%'.strtolower($s).'%'])->orWhereRaw('LOWER(i.problem_type) LIKE ?', ['%'.strtolower($s).'%'])))->orderBy('i.created_at', ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc');
            $rows = $query->paginate($perPage, ['*'], 'page', $page);
        } elseif ($category === 'machines') {
            $rows = (clone $machineBase)->paginate($perPage, ['*'], 'page', $page);
        } elseif ($category === 'spare-parts') {
            $query = (clone $partBase)->select('s.id', 's.name', 's.material_code', 's.quantity', 's.created_at', 's.remark', 't.ticket_number', 'm.code as machine_code', 'm.name as machine', 'p.name as plant')->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->whereRaw('LOWER(s.name) LIKE ?', ['%'.strtolower($s).'%'])->orWhereRaw('LOWER(s.material_code) LIKE ?', ['%'.strtolower($s).'%'])))->orderBy('s.created_at', ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc');
            $rows = $query->paginate($perPage, ['*'], 'page', $page);
        } elseif ($category === 'downtime') {
            $query = $this->downtimeWork($filters, true)->select('t.id', 't.ticket_number', 't.actual_downtime_started_at', 't.actual_downtime_ended_at', 't.closed_at', 'p.name as plant', 'm.name as machine', 't.reason as cause', 't.action_taken as action', 'u.name as executor', 't.duration_hours', 't.solution')->selectRaw('CASE WHEN t.actual_downtime_started_at IS NOT NULL AND t.actual_downtime_ended_at IS NOT NULL THEN \'AKTUAL\' WHEN t.actual_downtime_started_at IS NULL AND t.actual_downtime_ended_at IS NULL THEN \'PERKIRAAN\' ELSE \'BELUM LENGKAP\' END as work_time_source')->orderByRaw('COALESCE(t.actual_downtime_ended_at, t.closed_at) desc')->orderBy('t.id');
            $rows = $query->paginate($perPage, ['*'], 'page', $page);
            $rows->getCollection()->transform(function ($row) {
                $incomplete = $row->work_time_source === 'BELUM LENGKAP';
                $workEnd = $row->actual_downtime_ended_at ?? ($incomplete ? null : $row->closed_at);
                $workStart = $row->actual_downtime_started_at;
                if (! $workStart && ! $incomplete && $workEnd) {
                    $workStart = Carbon::parse($workEnd, 'UTC')->subSeconds((int) round((float) $row->duration_hours * 3600));
                }
                $row->work_start = $workStart ? Carbon::parse($workStart, 'UTC')->toISOString() : null;
                $row->work_end = $workEnd ? Carbon::parse($workEnd, 'UTC')->toISOString() : null;
                if ($incomplete) {
                    $row->duration_hours = null;
                }

                return $row;
            });
        }

        $downtime = ['available' => true, 'total_hours' => 0, 'work_count' => 0, 'average_hours' => 0, 'top_machine' => null, 'by_machine' => [], 'by_plant' => [], 'trend' => []];
        if ($category === 'downtime') {
            $downtimeBase = $this->downtimeWork($filters);
            $aggregate = (clone $downtimeBase)->selectRaw('COALESCE(SUM(t.duration_hours), 0) as total_hours, COUNT(*) as work_count, COALESCE(AVG(t.duration_hours), 0) as average_hours')->first();
            $downtime['total_hours'] = (float) $aggregate->total_hours;
            $downtime['work_count'] = (int) $aggregate->work_count;
            $downtime['average_hours'] = (float) $aggregate->average_hours;
            $downtime['by_machine'] = (clone $downtimeBase)->select('t.machine_id', 'm.code', 'm.name')->selectRaw('SUM(t.duration_hours) as total_hours, COUNT(*) as work_count')->groupBy('t.machine_id', 'm.code', 'm.name')->orderByDesc('total_hours')->get();
            $downtime['top_machine'] = $downtime['by_machine']->first();
            $downtime['by_plant'] = (clone $downtimeBase)->select('m.plant_id', 'p.code', 'p.name')->selectRaw('SUM(t.duration_hours) as total_hours, COUNT(*) as work_count')->groupBy('m.plant_id', 'p.code', 'p.name')->orderByDesc('total_hours')->get();

            $monthlyDowntime = Carbon::parse($filters['start_date'])->diffInDays(Carbon::parse($filters['end_date'])) > 60;
            $driver = DB::connection()->getDriverName();
            $downtimeEnd = 'COALESCE(t.actual_downtime_ended_at, t.closed_at)';
            $periodKey = $monthlyDowntime
                ? ($driver === 'sqlite' ? "strftime('%Y-%m', datetime({$downtimeEnd}, '+7 hours'))" : "TO_CHAR(DATE_TRUNC('month', {$downtimeEnd} + INTERVAL '7 hours'), 'YYYY-MM')")
                : ($driver === 'sqlite' ? "DATE(datetime({$downtimeEnd}, '+7 hours'))" : "CAST(({$downtimeEnd} + INTERVAL '7 hours') AS DATE)");
            $periodTotals = (clone $downtimeBase)->selectRaw("{$periodKey} as period, SUM(t.duration_hours) as total_hours")->groupByRaw($periodKey)->orderBy('period')->get()->keyBy('period');
            $cursor = $monthlyDowntime ? Carbon::parse($filters['start_date'])->startOfMonth() : Carbon::parse($filters['start_date'])->startOfDay();
            $last = $monthlyDowntime ? Carbon::parse($filters['end_date'])->startOfMonth() : Carbon::parse($filters['end_date'])->startOfDay();
            while ($cursor <= $last) {
                $period = $monthlyDowntime ? $cursor->format('Y-m') : $cursor->toDateString();
                $downtime['trend'][] = ['period' => $period, 'total_hours' => (float) ($periodTotals->get($period)?->total_hours ?? 0)];
                $cursor->add($monthlyDowntime ? 'month' : 'day', 1);
            }
            $downtime['trend_granularity'] = $monthlyDowntime ? 'month' : 'day';
        }

        return response()->json([
            'filters' => ['start_date' => $filters['start_date'], 'end_date' => $filters['end_date'], 'plant_id' => $filters['plant_id'] ?? null, 'machine_id' => $filters['machine_id'] ?? null],
            'summary' => $summary,
            'trend' => $trend,
            'incident_trend' => $incidentTrend,
            'trend_granularity' => $monthly ? 'month' : 'day',
            'ticket_statuses' => $statusDistribution,
            'incident_types' => $topProblemTypes,
            'top_incident_machines' => $topMachines,
            'incidents_by_plant' => $incidentsByPlant,
            'machine_count_by_plant' => DB::table('machines as m')->leftJoin('plants as p', 'p.id', '=', 'm.plant_id')->select('m.plant_id', 'p.name as plant', 'p.code as plant_code')->selectRaw('COUNT(m.id) as total')->where('m.is_active', true)->when($filters['plant_id'] ?? null, fn ($q, $id) => $q->where('m.plant_id', $id))->when($filters['machine_id'] ?? null, fn ($q, $id) => $q->where('m.id', $id))->groupBy('m.plant_id', 'p.name', 'p.code')->orderBy('p.name')->get(),
            'machines' => $category === 'machines' ? $rows : null,
            'spare_parts' => ['transactions' => (clone $partBase)->count(), 'quantity' => (int) (clone $partBase)->sum('s.quantity'), 'top' => $partsTop],
            'rows' => $rows,
            'downtime' => $downtime,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $category = $filters['category'] ?? 'tickets';
        abort_unless(in_array($category, ['tickets', 'incidents', 'machines', 'spare-parts', 'downtime'], true), 422, 'Pilih kategori tabel yang akan diekspor.');
        $ticketRows = $this->tickets($filters)->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->whereRaw('LOWER(t.ticket_number) LIKE ?', ['%'.strtolower($s).'%'])->orWhereRaw('LOWER(m.name) LIKE ?', ['%'.strtolower($s).'%'])->orWhereRaw('LOWER(t.problem_type) LIKE ?', ['%'.strtolower($s).'%'])))->select('t.ticket_number', 't.created_at', 'p.name as plant', 'm.code as machine_code', 'm.name as machine', 't.description', 't.reason', 't.action_taken', 'u.name as executor', 't.status', 't.duration_hours', 't.closed_at', 't.solution')->orderBy('t.created_at');
        $incidentRows = $this->incidents($filters)->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->whereRaw('LOWER(m.name) LIKE ?', ['%'.strtolower($s).'%'])->orWhereRaw('LOWER(i.problem_type) LIKE ?', ['%'.strtolower($s).'%'])))->select('i.id', 'i.created_at', 'p.name as plant', 'm.code as machine_code', 'm.name as machine', 'i.problem_type', 'i.description', 'i.status', 't.ticket_number')->orderBy('i.created_at');
        $machineRows = DB::table('machines as m')->leftJoin('plants as p', 'p.id', '=', 'm.plant_id')->when($filters['plant_id'] ?? null, fn ($q, $id) => $q->where('m.plant_id', $id))->when($filters['machine_id'] ?? null, fn ($q, $id) => $q->where('m.id', $id))->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->whereRaw('LOWER(m.code) LIKE ?', ['%'.strtolower($s).'%'])->orWhereRaw('LOWER(m.name) LIKE ?', ['%'.strtolower($s).'%'])))->select('m.id', 'p.name as plant', 'm.code as machine_code', 'm.name as machine', 'm.is_active')->selectSub(fn ($q) => $this->withinPeriod($q->from('incidents as ix')->whereColumn('ix.machine_id', 'm.id'), 'ix.created_at', $filters)->selectRaw('COUNT(*)'), 'incident_count')->selectSub(function ($q) use ($filters): void {
            $q->from('maintenance_tickets as dx')->whereColumn('dx.machine_id', 'm.id')->where('dx.status', 'CLOSED')->whereNotNull('dx.duration_hours')->where('dx.duration_hours', '>', 0)
                ->where(fn ($valid) => $valid
                    ->where(fn ($legacy) => $legacy->whereNull('dx.actual_downtime_started_at')->whereNull('dx.actual_downtime_ended_at'))
                    ->orWhere(fn ($actual) => $actual->whereNotNull('dx.actual_downtime_started_at')->whereNotNull('dx.actual_downtime_ended_at')))
                ->whereRaw('COALESCE(dx.actual_downtime_ended_at, dx.closed_at) >= ?', [Carbon::parse($filters['start_date'], 'Asia/Jakarta')->startOfDay()->utc()])
                ->whereRaw('COALESCE(dx.actual_downtime_ended_at, dx.closed_at) < ?', [Carbon::parse($filters['end_date'], 'Asia/Jakarta')->startOfDay()->addDay()->utc()])
                ->selectRaw('COALESCE(SUM(dx.duration_hours), 0)');
        }, 'duration_hours')->orderBy('p.name')->orderBy('m.name');
        $spareRows = $this->withinPeriod(DB::table('maintenance_ticket_spare_parts as s')->join('maintenance_tickets as t', 't.id', '=', 's.maintenance_ticket_id')->leftJoin('machines as m', 'm.id', '=', 't.machine_id')->leftJoin('plants as p', 'p.id', '=', 'm.plant_id')->when($filters['plant_id'] ?? null, fn ($q, $id) => $q->where('m.plant_id', $id))->when($filters['machine_id'] ?? null, fn ($q, $id) => $q->where('t.machine_id', $id))->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->whereRaw('LOWER(s.name) LIKE ?', ['%'.strtolower($s).'%'])->orWhereRaw('LOWER(s.material_code) LIKE ?', ['%'.strtolower($s).'%']))), 's.created_at', $filters)->select('s.created_at', 't.ticket_number', 'p.name as plant', 'm.name as machine', 's.name as spare_part', 's.material_code', 's.quantity', 's.remark')->orderBy('s.created_at');
        $downtimeRows = $this->downtimeWork($filters, true)->select('t.ticket_number', 't.actual_downtime_started_at', 't.actual_downtime_ended_at', 't.closed_at', 't.duration_hours', 'p.name as plant', 'm.name as machine', 't.reason as cause', 't.action_taken as action', 'u.name as executor', 't.solution')->selectRaw('CASE WHEN t.actual_downtime_started_at IS NOT NULL AND t.actual_downtime_ended_at IS NOT NULL THEN \'AKTUAL\' WHEN t.actual_downtime_started_at IS NULL AND t.actual_downtime_ended_at IS NULL THEN \'PERKIRAAN\' ELSE \'BELUM LENGKAP\' END as work_time_source')->orderByRaw('COALESCE(t.actual_downtime_ended_at, t.closed_at)');
        $ticketBase = $this->tickets($filters);
        $incidentBase = $this->incidents($filters);
        $closedPeriod = $this->withinPeriod($this->tickets($filters, false)->where('t.status', 'CLOSED')->whereNotNull('t.closed_at'), 't.closed_at', $filters);
        $downtimeBase = $this->downtimeWork($filters);
        $downtimeStats = (clone $downtimeBase)->selectRaw('COALESCE(SUM(t.duration_hours), 0) as total_hours, COUNT(*) as work_count, COALESCE(AVG(t.duration_hours), 0) as average_hours')->first();
        $plantName = ($filters['plant_id'] ?? null) ? DB::table('plants')->where('id', $filters['plant_id'])->value('name') : 'Semua';
        $machineName = ($filters['machine_id'] ?? null) ? DB::table('machines')->where('id', $filters['machine_id'])->value('name') : 'Semua';
        $monthly = Carbon::parse($filters['start_date'])->diffInDays(Carbon::parse($filters['end_date'])) > 60;
        $ticketTrend = $this->series(clone $ticketBase, 't.created_at', 'created', $filters, $monthly);
        $closedTrend = collect($this->series(clone $closedPeriod, 't.closed_at', 'closed', $filters, $monthly))->keyBy('period');
        $ticketTrend = collect($ticketTrend)->map(fn ($row) => [...$row, 'closed' => $closedTrend->get($row['period'])['closed'] ?? 0])->values();
        $ticketStatuses = (clone $ticketBase)->select('t.status')->selectRaw('COUNT(*) as total')->groupBy('t.status')->orderBy('t.status')->get();
        $incidentsByPlant = (clone $incidentBase)->select('i.plant_id', 'p.name')->selectRaw('COUNT(i.id) as total')->groupBy('i.plant_id', 'p.name')->orderByDesc('total')->get();
        $incidentsByMachine = (clone $incidentBase)->select('i.machine_id', 'm.code', 'm.name')->selectRaw('COUNT(i.id) as total')->groupBy('i.machine_id', 'm.code', 'm.name')->orderByDesc('total')->limit(10)->get();
        $downtimeByMachine = (clone $downtimeBase)->select('t.machine_id', 'm.code', 'm.name')->selectRaw('SUM(t.duration_hours) as total_hours')->groupBy('t.machine_id', 'm.code', 'm.name')->orderByDesc('total_hours')->get();
        $downtimeByPlant = (clone $downtimeBase)->select('m.plant_id', 'p.name')->selectRaw('SUM(t.duration_hours) as total_hours, COUNT(*) as work_count')->groupBy('m.plant_id', 'p.name')->orderByDesc('total_hours')->get();
        $sparePartTotals = $this->withinPeriod(DB::table('maintenance_ticket_spare_parts as s')->join('maintenance_tickets as t', 't.id', '=', 's.maintenance_ticket_id')->leftJoin('machines as m', 'm.id', '=', 't.machine_id')->when($filters['plant_id'] ?? null, fn ($q, $id) => $q->where('m.plant_id', $id))->when($filters['machine_id'] ?? null, fn ($q, $id) => $q->where('t.machine_id', $id))->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->whereRaw('LOWER(s.name) LIKE ?', ['%'.strtolower($s).'%'])->orWhereRaw('LOWER(s.material_code) LIKE ?', ['%'.strtolower($s).'%']))), 's.created_at', $filters)->select('s.name', 's.material_code')->selectRaw('SUM(s.quantity) as total_quantity, COUNT(*) as transactions')->groupBy('s.name', 's.material_code')->orderByDesc('total_quantity')->get();
        $summaryHeaderRows = [1, 8];
        $summaryRows = [
            ['Laporan Maintenance System', ''],
            ['Periode', $filters['start_date'].' sampai '.$filters['end_date']],
            ['Plant', $plantName ?: 'Tidak diketahui'],
            ['Mesin', $machineName ?: 'Tidak diketahui'],
            ['Pencarian nomor/kata', $filters['search'] ?? 'Tidak ada'],
            ['Dibuat pada', now('Asia/Jakarta')->format('Y-m-d H:i:s').' WIB'],
            ['', ''],
            ['Indikator', 'Nilai', 'Definisi'],
            ['Total tiket dalam periode', (clone $ticketBase)->count(), 'Tiket berdasarkan tanggal dibuat (created_at).'],
            ['Tiket OPEN saat ini', $this->tickets($filters, false)->where('t.status', 'OPEN')->count(), 'Semua tiket berstatus OPEN saat ekspor, di plant/mesin terpilih.'],
            ['Tiket selesai dalam periode', (clone $closedPeriod)->count(), 'Tiket CLOSED berdasarkan closed_at.'],
            ['Total insiden dalam periode', (clone $incidentBase)->count(), 'Insiden berdasarkan created_at.'],
            ['Total durasi perbaikan', (float) $downtimeStats->total_hours, 'Jumlah maintenance_tickets.duration_hours (jam), CLOSED dan lebih dari nol.'],
            ['Jumlah pekerjaan perbaikan', (int) $downtimeStats->work_count, 'Jumlah tiket Work Detail yang memenuhi kriteria durasi.'],
            ['Rata-rata durasi perbaikan', (float) $downtimeStats->average_hours, 'Total durasi dibagi jumlah pekerjaan (jam).'],
            ['', '', ''],
            ['Total durasi per plant (jam)', 'Jumlah pekerjaan'],
        ];
        foreach ($downtimeByPlant as $plantTotal) {
            $summaryRows[] = [$plantTotal->name ?? 'Plant tidak diketahui', (float) $plantTotal->total_hours, (int) $plantTotal->work_count];
        }
        if ($downtimeByPlant->isEmpty()) {
            $summaryRows[] = ['Tidak ada data downtime per plant', '', ''];
        }
        $summaryRows[] = ['', '', ''];
        $summaryRows[] = ['Total durasi per mesin (jam)', 'Jumlah pekerjaan'];
        $summaryHeaderRows[] = count($summaryRows);
        foreach ($downtimeByMachine as $machineTotal) {
            $summaryRows[] = [$machineTotal->code.' · '.$machineTotal->name, (float) $machineTotal->total_hours];
        }
        if ($downtimeByMachine->isEmpty()) {
            $summaryRows[] = ['Tidak ada data downtime per mesin', ''];
        }
        $summaryRows[] = ['', '', ''];
        $summaryRows[] = ['Penggunaan spare part', 'Total kuantitas', 'Jumlah transaksi'];
        $summaryHeaderRows[] = count($summaryRows);
        foreach ($sparePartTotals as $partTotal) {
            $summaryRows[] = [$partTotal->name.' · '.$partTotal->material_code, (int) $partTotal->total_quantity, (int) $partTotal->transactions];
        }
        if ($sparePartTotals->isEmpty()) {
            $summaryRows[] = ['Tidak ada penggunaan spare part', '', ''];
        }
        $summaryHeaderRows[] = 17;
        $chartSources = [];
        $addChart = static function (string $title, string $label, string $type, array $categories, array $series) use (&$chartSources): void {
            if ($categories !== [] && collect($series)->contains(fn ($item) => collect($item['values'])->sum() > 0)) {
                $chartSources[] = compact('title', 'label', 'type', 'categories', 'series');
            }
        };
        $addChart('Tiket dibuat dan diselesaikan', 'Periode', 'line', $ticketTrend->pluck('period')->all(), [
            ['label' => 'Tiket dibuat', 'values' => $ticketTrend->pluck('created')->all()],
            ['label' => 'Tiket selesai', 'values' => $ticketTrend->pluck('closed')->all()],
        ]);
        $addChart('Distribusi tiket berdasarkan status', 'Status', 'bar', $ticketStatuses->pluck('status')->all(), [['label' => 'Jumlah tiket', 'values' => $ticketStatuses->pluck('total')->map(fn ($value) => (int) $value)->all()]]);
        $addChart('Insiden per plant', 'Plant', 'bar', $incidentsByPlant->map(fn ($row) => $row->name ?? 'Plant tidak diketahui')->all(), [['label' => 'Jumlah insiden', 'values' => $incidentsByPlant->pluck('total')->map(fn ($value) => (int) $value)->all()]]);
        $addChart('10 mesin dengan insiden terbanyak', 'Mesin', 'bar', $incidentsByMachine->map(fn ($row) => $row->code.' · '.$row->name)->all(), [['label' => 'Jumlah insiden', 'values' => $incidentsByMachine->pluck('total')->map(fn ($value) => (int) $value)->all()]]);
        $addChart('Durasi perbaikan per mesin (jam)', 'Mesin', 'bar', $downtimeByMachine->map(fn ($row) => $row->code.' · '.$row->name)->all(), [['label' => 'Duration (jam)', 'values' => $downtimeByMachine->pluck('total_hours')->map(fn ($value) => (float) $value)->all()]]);
        $addChart('Durasi perbaikan per plant (jam)', 'Plant', 'bar', $downtimeByPlant->map(fn ($row) => $row->name ?? 'Plant tidak diketahui')->all(), [['label' => 'Duration (jam)', 'values' => $downtimeByPlant->pluck('total_hours')->map(fn ($value) => (float) $value)->all()]]);
        $downtimeTrend = $this->downtimeTrend($filters, $monthly);
        $addChart('Tren durasi perbaikan (jam)', 'Periode', 'line', array_column($downtimeTrend, 'period'), [['label' => 'Duration (jam)', 'values' => array_column($downtimeTrend, 'total_hours')]]);

        $rows = fn ($query, array $headers, array $keys) => $this->exportRows($query, $headers, $keys);
        $sheets = [
            ['name' => 'Ringkasan', 'headers' => ['Indikator', 'Nilai', 'Definisi'], 'rows' => $summaryRows, 'header_rows' => $summaryHeaderRows, 'widths' => [42, 30, 88]],
            ['name' => 'Tiket Maintenance', 'headers' => ['Nomor tiket', 'Tanggal tiket', 'Plant', 'Kode mesin', 'Nama mesin', 'Deskripsi pekerjaan', 'Cause', 'Action', 'Teknisi / Executor', 'Status', 'Duration (jam)', 'Tanggal selesai', 'Solution'], 'rows' => $rows($ticketRows, ['Nomor tiket', 'Tanggal tiket', 'Plant', 'Kode mesin', 'Nama mesin', 'Deskripsi pekerjaan', 'Cause', 'Action', 'Teknisi / Executor', 'Status', 'Duration (jam)', 'Tanggal selesai', 'Solution'], ['ticket_number', 'created_at', 'plant', 'machine_code', 'machine', 'description', 'reason', 'action_taken', 'executor', 'status', 'duration_hours', 'closed_at', 'solution']), 'filter' => true, 'widths' => [20, 22, 24, 16, 26, 48, 36, 42, 25, 14, 18, 22, 38], 'date_columns' => [1 => true, 11 => true]],
            ['name' => 'Insiden', 'headers' => ['ID insiden', 'Tanggal insiden', 'Plant', 'Kode mesin', 'Nama mesin', 'Jenis masalah', 'Deskripsi insiden', 'Status', 'Nomor tiket terkait'], 'rows' => $rows($incidentRows, ['ID insiden', 'Tanggal insiden', 'Plant', 'Kode mesin', 'Nama mesin', 'Jenis masalah', 'Deskripsi insiden', 'Status', 'Nomor tiket terkait'], ['id', 'created_at', 'plant', 'machine_code', 'machine', 'problem_type', 'description', 'status', 'ticket_number']), 'filter' => true, 'widths' => [14, 22, 24, 16, 26, 24, 48, 14, 22], 'date_columns' => [1 => true]],
            ['name' => 'Mesin & Plant', 'headers' => ['Plant', 'Kode mesin', 'Nama mesin', 'Aktif', 'Jumlah insiden periode', 'Total durasi perbaikan (jam)'], 'rows' => $rows($machineRows, ['Plant', 'Kode mesin', 'Nama mesin', 'Aktif', 'Jumlah insiden periode', 'Total durasi perbaikan (jam)'], ['plant', 'machine_code', 'machine', 'is_active', 'incident_count', 'duration_hours']), 'filter' => true, 'widths' => [26, 18, 30, 14, 26, 34]],
            ['name' => 'Spare Part', 'headers' => ['Tanggal penggunaan', 'Nomor tiket', 'Plant', 'Mesin', 'Nama spare part', 'Kode material', 'Kuantitas', 'Keterangan'], 'rows' => $rows($spareRows, ['Tanggal penggunaan', 'Nomor tiket', 'Plant', 'Mesin', 'Nama spare part', 'Kode material', 'Kuantitas', 'Keterangan'], ['created_at', 'ticket_number', 'plant', 'machine', 'spare_part', 'material_code', 'quantity', 'remark']), 'filter' => true, 'widths' => [22, 20, 24, 28, 34, 22, 16, 42], 'date_columns' => [0 => true]],
            ['name' => 'Downtime', 'headers' => ['Nomor tiket', 'Mulai downtime', 'Selesai downtime', 'Sumber waktu', 'Plant', 'Nama mesin', 'Cause', 'Action', 'Teknisi / Executor', 'Duration (jam)', 'Solution'], 'rows' => $this->downtimeExportRows($downtimeRows), 'filter' => true, 'widths' => [20, 26, 26, 18, 24, 28, 38, 44, 25, 18, 40], 'date_columns' => [1 => true, 2 => true]],
        ];

        return response()->streamDownload(function () use ($sheets, $chartSources): void {
            $path = tempnam(sys_get_temp_dir(), 'maintenance-report-');
            abort_if($path === false, 500, 'Tidak dapat menyiapkan berkas Excel.');
            try {
                app(ReportsExcelWorkbook::class)->write($path, $sheets, $chartSources);
                readfile($path);
            } finally {
                @unlink($path);
            }
        }, "laporan-maintenance-{$filters['start_date']}-{$filters['end_date']}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function exportRows(Builder $query, array $headers, array $keys): \Generator
    {
        yield $headers;
        foreach ($query->cursor() as $row) {
            $values = (array) $row;
            yield array_map(fn ($key) => $values[$key] ?? null, $keys);
        }
    }

    private function downtimeExportRows(Builder $query): \Generator
    {
        yield ['Nomor tiket', 'Mulai downtime', 'Selesai downtime', 'Sumber waktu', 'Plant', 'Nama mesin', 'Cause', 'Action', 'Teknisi / Executor', 'Duration (jam)', 'Solution'];
        foreach ($query->cursor() as $row) {
            $incomplete = $row->work_time_source === 'BELUM LENGKAP';
            $workEnd = $row->actual_downtime_ended_at ?? ($incomplete ? null : $row->closed_at);
            $workStart = $row->actual_downtime_started_at;
            if (! $workStart && ! $incomplete && $workEnd) {
                $workStart = Carbon::parse($workEnd, 'UTC')->subSeconds((int) round((float) $row->duration_hours * 3600));
            }
            yield [$row->ticket_number, $workStart ? Carbon::parse($workStart, 'UTC')->toISOString() : null, $workEnd ? Carbon::parse($workEnd, 'UTC')->toISOString() : null, $row->work_time_source, $row->plant, $row->machine, $row->cause, $row->action, $row->executor, $incomplete ? null : (float) $row->duration_hours, $row->solution];
        }
    }

    private function downtimeTrend(array $filters, bool $monthly): array
    {
        $driver = DB::connection()->getDriverName();
        $downtimeEnd = 'COALESCE(t.actual_downtime_ended_at, t.closed_at)';
        $periodKey = $monthly
            ? ($driver === 'sqlite' ? "strftime('%Y-%m', datetime({$downtimeEnd}, '+7 hours'))" : "TO_CHAR(DATE_TRUNC('month', {$downtimeEnd} + INTERVAL '7 hours'), 'YYYY-MM')")
            : ($driver === 'sqlite' ? "DATE(datetime({$downtimeEnd}, '+7 hours'))" : "CAST(({$downtimeEnd} + INTERVAL '7 hours') AS DATE)");
        $totals = $this->downtimeWork($filters)->selectRaw("{$periodKey} as period, SUM(t.duration_hours) as total_hours")->groupByRaw($periodKey)->orderBy('period')->get()->keyBy('period');
        $cursor = $monthly ? Carbon::parse($filters['start_date'])->startOfMonth() : Carbon::parse($filters['start_date'])->startOfDay();
        $last = $monthly ? Carbon::parse($filters['end_date'])->startOfMonth() : Carbon::parse($filters['end_date'])->startOfDay();
        $trend = [];
        while ($cursor <= $last) {
            $period = $monthly ? $cursor->format('Y-m') : $cursor->toDateString();
            $trend[] = ['period' => $period, 'total_hours' => (float) ($totals->get($period)?->total_hours ?? 0)];
            $cursor->add($monthly ? 'month' : 'day', 1);
        }

        return $trend;
    }
}
