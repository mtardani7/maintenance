<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MaintenanceDashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'period_days' => ['sometimes', 'integer', Rule::in([7, 30, 90])],
            'plant_id' => ['sometimes', 'nullable', 'integer', 'exists:plants,id'],
        ]);

        $periodDays = (int) ($filters['period_days'] ?? 30);
        $plantId = $filters['plant_id'] ?? null;
        $now = now();
        $from = $now->copy()->subDays($periodDays - 1)->startOfDay();
        $todayStart = $now->copy()->startOfDay();
        $tomorrowStart = $todayStart->copy()->addDay();

        $machineCounts = DB::table('incidents as i')
            ->join('machines as m', 'm.id', '=', 'i.machine_id')
            ->leftJoin('plants as p', 'p.id', '=', 'm.plant_id')
            ->whereBetween('i.created_at', [$from, $now])
            ->whereIn('i.status', ['OPEN', 'RESOLVED'])
            ->when($plantId, fn ($query) => $query->where('m.plant_id', $plantId))
            ->select([
                'm.id as machine_id', 'm.code as machine_code', 'm.name as machine_name',
                'm.plant_id', 'p.code as plant_code', 'p.name as plant_name',
            ])
            ->selectRaw('COUNT(i.id) as incident_count, MAX(i.created_at) as last_occurred_at')
            ->groupBy('m.id', 'm.code', 'm.name', 'm.plant_id', 'p.code', 'p.name')
            ->orderByRaw('COUNT(i.id) DESC')
            ->orderBy('m.code')
            ->get();

        $problemCounts = DB::table('incidents as i')
            ->join('machines as m', 'm.id', '=', 'i.machine_id')
            ->whereBetween('i.created_at', [$from, $now])
            ->whereIn('i.status', ['OPEN', 'RESOLVED'])
            ->when($plantId, fn ($query) => $query->where('m.plant_id', $plantId))
            ->select(['m.id as machine_id', 'i.problem_type'])
            ->selectRaw('COUNT(i.id) as incident_count, MAX(i.created_at) as last_occurred_at')
            ->groupBy('m.id', 'i.problem_type')
            ->orderByRaw('COUNT(i.id) DESC')
            ->get()
            ->groupBy('machine_id');

        $machines = $machineCounts->map(function (object $machine) use ($problemCounts): array {
            $problems = collect($problemCounts->get($machine->machine_id, []))
                ->map(fn (object $problem): array => [
                    'problem_type' => $problem->problem_type,
                    'count' => (int) $problem->incident_count,
                    'last_occurred_at' => $problem->last_occurred_at,
                ])
                ->values();

            return [
                'machine_id' => (int) $machine->machine_id,
                'machine_code' => $machine->machine_code,
                'machine_name' => $machine->machine_name,
                'plant_id' => $machine->plant_id === null ? null : (int) $machine->plant_id,
                'plant_code' => $machine->plant_code,
                'plant_name' => $machine->plant_name,
                'incident_count' => (int) $machine->incident_count,
                'last_occurred_at' => $machine->last_occurred_at,
                'problems' => $problems,
            ];
        })->values();

        $tickets = MaintenanceTicket::query()->when($plantId, fn ($query) => $query->where('plant_id', $plantId));
        $attention = (clone $tickets)->where('status', 'OPEN')
            ->with(['machine:id,code,name', 'plant:id,code,name'])
            ->orderBy('created_at')
            ->limit(8)
            ->get(['id', 'ticket_number', 'plant_id', 'machine_id', 'problem_type', 'description', 'status', 'priority', 'created_at'])
            ->map(fn (MaintenanceTicket $ticket): array => [
                'ticket_number' => $ticket->ticket_number,
                'problem_type' => $ticket->problem_type,
                'description' => $ticket->description,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'created_at' => $ticket->created_at,
                'machine_code' => $ticket->machine?->code,
                'machine_name' => $ticket->machine?->name,
                'plant_code' => $ticket->plant?->code,
                'plant_name' => $ticket->plant?->name,
            ])
            ->values();

        $todayIncidentCount = DB::table('incidents as i')
            ->join('machines as m', 'm.id', '=', 'i.machine_id')
            ->where('i.created_at', '>=', $todayStart)
            ->where('i.created_at', '<', $tomorrowStart)
            ->whereIn('i.status', ['OPEN', 'RESOLVED'])
            ->when($plantId, fn ($query) => $query->where('m.plant_id', $plantId))
            ->count();

        $activities = DB::table('incidents as i')
            ->join('machines as m', 'm.id', '=', 'i.machine_id')
            ->leftJoin('maintenance_tickets as t', 't.id', '=', 'i.maintenance_ticket_id')
            ->whereIn('i.status', ['OPEN', 'RESOLVED'])
            ->when($plantId, fn ($query) => $query->where('m.plant_id', $plantId))
            ->orderByDesc('i.created_at')
            ->limit(8)
            ->get([
                'i.id', 'i.problem_type', 'i.status', 'i.created_at',
                'm.code as machine_code', 'm.name as machine_name', 't.ticket_number',
            ])
            ->map(fn (object $incident): array => [
                'id' => (int) $incident->id,
                'problem_type' => $incident->problem_type,
                'status' => $incident->status,
                'created_at' => $incident->created_at,
                'machine_code' => $incident->machine_code,
                'machine_name' => $incident->machine_name,
                'ticket_number' => $incident->ticket_number,
            ]);

        return response()->json([
            'period_days' => $periodDays,
            'starts_at' => $from,
            'summary' => [
                'open_tickets' => (clone $tickets)->where('status', 'OPEN')->count(),
                'closed_today' => (clone $tickets)->where('status', 'CLOSED')->where('closed_at', '>=', $todayStart)->where('closed_at', '<', $tomorrowStart)->count(),
                'overdue_tickets' => (clone $tickets)->where('status', 'OPEN')->where('created_at', '<=', $now->copy()->subDay())->count(),
                'incidents_today' => $todayIncidentCount,
            ],
            'needs_attention' => $attention,
            'trend_machines' => $machines,
            'repeated_problems' => $machines->flatMap(fn (array $machine): array => $machine['problems']
                ->filter(fn (array $problem): bool => $problem['count'] > 1)
                ->map(fn (array $problem): array => [
                    'machine_id' => $machine['machine_id'],
                    'machine_code' => $machine['machine_code'],
                    'machine_name' => $machine['machine_name'],
                    'problem_type' => $problem['problem_type'],
                    'count' => $problem['count'],
                    'last_occurred_at' => $problem['last_occurred_at'],
                ])->all())->sortByDesc('count')->values(),
            'recent_activity' => $activities,
        ]);
    }
}
