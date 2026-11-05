<?php

namespace App\Http\Controllers;

use App\Models\Plant;
use App\Support\PlantAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Plant::query()->latest();
        if ($request->user()) {
            PlantAccess::scope($query, $request->user(), 'plants.id');
        }
        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(fn (Builder $builder) => $builder
                ->where('code', 'ilike', "%{$search}%")
                ->orWhere('name', 'ilike', "%{$search}%"));
        }
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $plants = $query->paginate(min($request->integer('per_page', 100), 100));
        $plants->getCollection()->transform(fn (Plant $plant): array => $this->resource($plant));
        return response()->json($plants);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->role === 'admin', 403);
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:100', 'unique:plants,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $userId = $request->user()->id;
        $plant = Plant::create([...$validated, 'created_by' => $userId, 'updated_by' => $userId]);
        return response()->json($this->resource($plant), 201);
    }

    public function update(Request $request, Plant $plant): JsonResponse
    {
        abort_unless($request->user()?->role === 'admin', 403);
        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:100', Rule::unique('plants', 'code')->ignore($plant->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $validated['updated_by'] = $request->user()->id;
        $plant->update($validated);
        return response()->json($this->resource($plant->fresh()));
    }

    public function destroy(Request $request, Plant $plant): JsonResponse
    {
        abort_unless($request->user()?->role === 'admin', 403);
        if ($plant->machines()->exists()) {
            $plant->update(['is_active' => false, 'updated_by' => $request->user()->id]);
            return response()->json(['message' => 'Plant deactivated to preserve machine and history data.']);
        }

        $plant->update(['is_active' => false, 'updated_by' => $request->user()->id]);
        return response()->json(['message' => 'Plant deactivated.']);
    }

    private function resource(Plant $plant): array
    {
        return [
            'id' => $plant->id,
            'code' => $plant->code,
            'name' => $plant->name,
            'description' => $plant->description,
            'is_active' => $plant->is_active,
            'created_by' => $plant->created_by,
            'updated_by' => $plant->updated_by,
            'created_at' => $plant->created_at,
            'updated_at' => $plant->updated_at,
        ];
    }
}
