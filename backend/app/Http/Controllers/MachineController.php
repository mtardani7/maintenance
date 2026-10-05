<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Models\Incident;
use App\Models\MaintenanceTicket;
use App\Services\MachineQrPayload;

class MachineController extends Controller
{
    public function resolveQr(Request $request): JsonResponse
    {
        $validated = $request->validate(['payload' => ['required', 'string', 'max:255']]);
        $identifier = MachineQrPayload::parse($validated['payload']);
        if (! $identifier) return response()->json(['message' => 'QR Mesin tidak valid.'], 422);

        $machine = Machine::query()->with('plant')
            ->where('code', $identifier['machine_code'])
            ->whereHas('plant', fn ($query) => $query->where('code', $identifier['plant_code']))
            ->first();

        if (! $machine) return response()->json(['message' => 'Mesin tidak ditemukan.'], 404);
        if (! $machine->is_active) return response()->json(['message' => 'Mesin sedang tidak aktif dan tidak dapat digunakan untuk laporan.'], 422);

        return response()->json([
            'machine' => ['id' => $machine->id, 'code' => $machine->code, 'name' => $machine->name, 'section' => $machine->section],
            'plant' => ['id' => $machine->plant->id, 'code' => $machine->plant->code, 'name' => $machine->plant->name],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Machine::query()->with('plant')->latest();

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($builder) use ($search): void {
                $builder->where('code', 'ilike', "%{$search}%")
                    ->orWhere('name', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('plant')) $query->where('plant_id', $request->integer('plant'));
        if ($request->filled('plant_id')) $query->where('plant_id', $request->integer('plant_id'));
        if ($request->filled('status')) $query->where('is_active', $request->string('status')->toString() === 'running');
        if ($request->has('is_active')) $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));

        $machines = $query->paginate(min($request->integer('per_page', 20), 100));
        $machines->getCollection()->transform(fn (Machine $machine): array => $this->resource($machine));
        return response()->json($machines);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plant_id' => ['required', 'integer', Rule::exists('plants', 'id')->where(fn ($query) => $query->where('is_active', true))],
            'code' => ['required', 'string', 'max:100', Rule::unique('machines')->where(fn ($query) => $query->where('plant_id', $request->integer('plant_id')))],
            'name' => ['required', 'string', 'max:255'],
            'section' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $machine = Machine::create([...$validated, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);

        return response()->json($this->resource($machine), 201);
    }

    public function update(Request $request, Machine $machine): JsonResponse
    {
        $validated = $request->validate([
            'plant_id' => ['sometimes', 'integer', Rule::exists('plants', 'id')->where(fn ($query) => $query->where('is_active', true))],
            'code' => ['sometimes', 'string', 'max:100', Rule::unique('machines')->where(fn ($query) => $query->where('plant_id', $request->integer('plant_id', $machine->plant_id)))->ignore($machine->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'section' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $validated['updated_by'] = $request->user()->id;
        $machine->update($validated);

        return response()->json($this->resource($machine->fresh()));
    }

    public function destroy(Request $request, Machine $machine): JsonResponse
    {
        $hasHistory = Incident::where('machine_id', $machine->id)->exists()
            || MaintenanceTicket::where('machine_id', $machine->id)->exists();
        $machine->update(['is_active' => false, 'updated_by' => $request->user()->id]);

        return response()->json(['message' => $hasHistory ? 'Machine deactivated to preserve history.' : 'Machine deactivated.']);
    }

    private function resource(Machine $machine): array
    {
        return [
            'id' => $machine->id,
            'plant_id' => $machine->plant_id,
            'plant' => $machine->plant ? ['id' => $machine->plant->id, 'code' => $machine->plant->code, 'name' => $machine->plant->name] : null,
            'code' => $machine->code,
            'name' => $machine->name,
            'section' => $machine->section,
            'is_active' => $machine->is_active,
            'created_by' => $machine->created_by,
            'updated_by' => $machine->updated_by,
            'created_at' => $machine->created_at,
            'updated_at' => $machine->updated_at,
        ];
    }
}
