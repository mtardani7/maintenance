<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Http\Resources\IncidentResource;
use App\Services\MaintenanceTicketCreator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IncidentController extends Controller
{
    private function ensureOwnerOrAdmin(Request $request, Incident $incident): void
    {
        $user = $request->user();
        abort_unless(
            $user?->role === 'admin' || (string) $incident->reported_by === (string) $user?->id,
            403,
            'You are not authorized to access this incident.',
        );
    }

    public function index(Request $request)
    {
        $incidents = Incident::query()
            ->with('maintenanceTicket')
            ->when($request->user()?->role !== 'admin', fn ($query) => $query->where('reported_by', $request->user()->id))
            ->when($request->filled('plant_id'), fn ($query) => $query->where('plant_id', $request->integer('plant_id')))
            ->when($request->filled('machine_id'), fn ($query) => $query->where('machine_id', $request->integer('machine_id')))
            ->latest()
            ->paginate(min($request->integer('per_page', 20), 100));

        return IncidentResource::collection($incidents);
    }

    public function store(Request $request, MaintenanceTicketCreator $ticketCreator): JsonResponse
    {
        $data = $request->validate([
            'plant_id' => ['required', 'integer'],
            'machine_id' => ['required', 'integer'],
            'problem_type' => ['required', 'string', 'max:100'],
            'description' => [
                'nullable',
                'string',
                Rule::requiredIf(fn (): bool => in_array(strtolower($request->string('problem_type')->toString()), ['other', 'lainnya'], true)),
                'min:10',
            ],
            'action_taken' => ['nullable', 'required_if:status,RESOLVED', 'string', 'min:5'],
            'result' => ['nullable', 'required_if:status,RESOLVED', 'string', 'min:5'],
            'status' => ['required', 'in:OPEN,RESOLVED'],
        ]);
        $data['reported_by'] = $request->user()->id;

        $incident = DB::transaction(function () use ($data, $ticketCreator): Incident {
            $incident = Incident::create($data);

            if ($incident->status === 'OPEN') {
                $ticket = $ticketCreator->create([
                    'plant_id' => $incident->plant_id,
                    'machine_id' => $incident->machine_id,
                    'problem_type' => $incident->problem_type,
                    'description' => $incident->description ?: $incident->problem_type,
                    'source' => 'OPERATOR',
                    'reported_by' => $incident->reported_by,
                ]);
                $incident->maintenanceTicket()->associate($ticket);
                $incident->save();
            }

            return $incident;
        });

        return response()->json(new IncidentResource($incident->load('maintenanceTicket')), 201);
    }

    public function show(Request $request, Incident $incident): IncidentResource
    {
        $this->ensureOwnerOrAdmin($request, $incident);

        return new IncidentResource($incident->load('maintenanceTicket'));
    }

    public function update(Request $request, Incident $incident): IncidentResource
    {
        $this->ensureOwnerOrAdmin($request, $incident);

        $data = $request->validate([
            'plant_id' => ['sometimes', 'integer'],
            'machine_id' => ['sometimes', 'integer'],
            'problem_type' => ['sometimes', 'string', 'max:100'],
            'description' => ['sometimes', 'string', 'min:10'],
            'action_taken' => ['sometimes', 'string', 'min:5'],
            'result' => ['sometimes', 'string', 'min:5'],
            'status' => ['sometimes', 'in:OPEN,RESOLVED'],
        ]);
        $incident->update($data);

        return new IncidentResource($incident->fresh('maintenanceTicket'));
    }

    public function destroy(Request $request, Incident $incident): JsonResponse
    {
        $this->ensureOwnerOrAdmin($request, $incident);

        $incident->delete();
        return response()->json(null, 204);
    }
}
