<?php

namespace App\Http\Controllers;

use App\Events\TicketCreated;
use App\Http\Resources\IncidentResource;
use App\Models\Incident;
use App\Models\Machine;
use App\Services\MachineQrPayload;
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
            ->with(['maintenanceTicket', 'attachments'])
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
            'qr_payload' => ['sometimes', 'nullable', 'string', 'max:255'],
            'files' => ['sometimes', 'array', 'max:5'],
            'files.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,mp4,mov,webm', 'max:18432'],
        ]);
        $files = $request->file('files', []);
        abort_if(collect($files)->sum(fn ($file): int => $file->getSize()) > 18 * 1024 * 1024, 422, 'Total attachment size must not exceed 18 MB.');
        if (! empty($data['qr_payload'])) {
            $identifier = MachineQrPayload::parse($data['qr_payload']);
            abort_unless($identifier, 422, 'QR Mesin tidak valid.');
            $machine = Machine::query()->where('code', $identifier['machine_code'])
                ->whereHas('plant', fn ($query) => $query->where('code', $identifier['plant_code']))
                ->first();
            abort_unless($machine, 404, 'Mesin tidak ditemukan.');
            abort_if(! $machine->is_active, 422, 'Mesin sedang tidak aktif dan tidak dapat digunakan untuk laporan.');
            $data['plant_id'] = $machine->plant_id;
            $data['machine_id'] = $machine->id;
        }
        unset($data['qr_payload']);
        $data['reported_by'] = $request->user()->id;

        $incident = DB::transaction(function () use ($data, $ticketCreator, $files, $request): Incident {
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
                event(new TicketCreated($ticket->load('machine'), $incident));
            }

            foreach ($files as $file) {
                $path = $file->store('attachments/'.now()->format('Y/m'), 'local');
                $incident->attachments()->create([
                    'maintenance_ticket_id' => $incident->maintenance_ticket_id,
                    'uploaded_by' => $request->user()->id,
                    'file_name' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME).'.'.$file->guessExtension(),
                    'file_path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                ]);
            }

            return $incident;
        });

        return response()->json(new IncidentResource($incident->load(['maintenanceTicket', 'attachments'])), 201);
    }

    public function show(Request $request, Incident $incident): IncidentResource
    {
        $this->ensureOwnerOrAdmin($request, $incident);

        return new IncidentResource($incident->load(['maintenanceTicket', 'attachments']));
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
