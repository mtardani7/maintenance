<?php

namespace App\Http\Controllers;

use App\Events\TicketClosed;
use App\Models\Incident;
use App\Models\MaintenanceTicket;
use App\Models\User;
use App\Services\MaintenanceTicketCreator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MaintenanceTicketController extends Controller
{
    private function ensureMaintenance(Request $request): void
    {
        abort_unless($request->user()?->role === 'technician', 403, 'Only Maintenance users can modify tickets.');
    }

    public function index(Request $request): JsonResponse
    {
        $tickets = MaintenanceTicket::query()
            ->with(['plant', 'machine.plant', 'reporter', 'executor', 'actionBy', 'closedBy', 'spareParts', 'attachments'])
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = trim($request->string('search')->toString());
                $query->where(function ($ticketQuery) use ($search): void {
                    $ticketQuery
                        ->where('ticket_number', 'ilike', "%{$search}%")
                        ->orWhere('problem_type', 'ilike', "%{$search}%")
                        ->orWhere('description', 'ilike', "%{$search}%")
                        ->orWhereHas('machine', fn ($machineQuery) => $machineQuery
                            ->where('code', 'ilike', "%{$search}%")
                            ->orWhere('name', 'ilike', "%{$search}%"));
                });
            })
            ->when($request->filled('plant_id'), fn ($query) => $query->where('plant_id', $request->integer('plant_id')))
            ->when($request->filled('machine_id'), fn ($query) => $query->where('machine_id', $request->integer('machine_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', strtoupper($request->string('status')->toString())))
            ->when($request->filled('priority'), fn ($query) => $query->where('priority', strtoupper($request->string('priority')->toString())))
            ->orderBy('created_at', $request->string('sort')->toString() === 'oldest' ? 'asc' : 'desc')
            ->paginate(min(max($request->integer('per_page', 10), 1), 100));

        return response()->json($tickets);
    }

    public function store(Request $request, MaintenanceTicketCreator $creator): JsonResponse
    {
        $this->ensureMaintenance($request);

        $data = $request->validate([
            'plant_id' => ['required', 'integer'],
            'machine_id' => ['required', 'integer'],
            'problem_type' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'min:10'],
            'source' => ['required', 'in:OPERATOR,QA'],
            'priority' => ['sometimes', 'in:LOW,MEDIUM,HIGH,CRITICAL'],
        ]);
        $data['reported_by'] = $request->user()->id;

        $ticket = $creator->create($data);

        return response()->json($ticket, 201);
    }

    public function show(MaintenanceTicket $ticket): JsonResponse
    {
        return response()->json($ticket->load(['plant', 'machine.plant', 'reporter', 'executor', 'actionBy', 'closedBy', 'spareParts', 'attachments']));
    }

    public function maintenanceUsers(): JsonResponse
    {
        return response()->json(User::query()
            ->where('role', 'technician')
            ->orderBy('name')
            ->get(['id', 'name', 'role']));
    }

    public function action(Request $request, MaintenanceTicket $ticket, ?string $action = null): JsonResponse
    {
        $this->ensureMaintenance($request);

        if ($ticket->status !== 'OPEN') {
            return response()->json(['message' => 'Only open tickets can be closed.'], 422);
        }

        $data = $request->validate([
            'action' => ['sometimes', 'in:close'],
            'reason' => ['required', 'string', 'min:1', 'max:5000'],
            'action_taken' => ['required', 'string', 'min:1', 'max:5000'],
            'duration_hours' => ['required', 'numeric', 'min:0.25', 'max:1000'],
            'solution' => ['required', 'string', 'min:1', 'max:5000'],
        ]);
        $action = $action ?? $data['action'] ?? null;

        if ($action !== 'close') {
            return response()->json(['message' => 'The ticket action is invalid.'], 422);
        }

        DB::transaction(function () use ($data, $request, $ticket): void {
            $lockedTicket = MaintenanceTicket::query()->lockForUpdate()->findOrFail($ticket->getKey());
            if ($lockedTicket->status !== 'OPEN') {
                abort(422, 'Only open tickets can be closed.');
            }

            $lockedTicket->update([
                'status' => 'CLOSED',
                'reason' => trim($data['reason']),
                'action_taken' => trim($data['action_taken']),
                'executor_id' => auth()->id(),
                'duration_hours' => $data['duration_hours'],
                'solution' => trim($data['solution']),
                'closed_at' => now(),
                'closed_by_id' => $request->user()->id,
            ]);

            Incident::query()
                ->where('maintenance_ticket_id', $lockedTicket->getKey())
                ->where('status', 'OPEN')
                ->update(['status' => 'RESOLVED']);

            event(new TicketClosed($lockedTicket->fresh(['machine', 'closedBy'])));
        });

        return response()->json($ticket->fresh(['plant', 'machine.plant', 'reporter', 'executor', 'actionBy', 'closedBy', 'spareParts']));
    }

    public function update(Request $request, MaintenanceTicket $ticket): JsonResponse
    {
        $this->ensureMaintenance($request);

        if ($ticket->status === 'CLOSED') {
            return response()->json(['message' => 'Closed tickets are historical records and cannot be edited.'], 422);
        }

        $data = $request->validate([
            'priority' => ['sometimes', 'in:LOW,MEDIUM,HIGH,CRITICAL'],
            'description' => ['sometimes', 'string', 'min:10'],
            'root_cause_analysis' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'corrective_action_plan' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'target_at' => ['sometimes', 'nullable', 'date'],
            'action_by_id' => ['sometimes', 'nullable', Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'technician'))],
            'verification_checklist' => ['sometimes', 'nullable', 'array:machine_cleanliness,water,grease,gram,machine_function,machine_safety,tool'],
            'verification_checklist.*' => ['nullable', 'in:OK,NOK,N/A'],
        ]);
        $ticket->update($data);

        return response()->json($ticket->fresh(['plant', 'machine.plant', 'reporter', 'executor', 'actionBy', 'closedBy', 'spareParts']));
    }

    public function destroy(Request $request, MaintenanceTicket $ticket): JsonResponse
    {
        $this->ensureMaintenance($request);

        if ($ticket->status === 'CLOSED') {
            return response()->json(['message' => 'Closed tickets are historical records and cannot be deleted.'], 422);
        }

        $ticket->delete();

        return response()->json(null, 204);
    }
}
