<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Models\Plant;

class QaMachineController extends Controller
{
    private function client(bool $forceLogin = false)
    {
        $token = $forceLogin ? null : config('services.qa_system.token');
        $token ??= Cache::store('file')->get('qa-system-api-token');
        $token ??= $this->login();
        abort_unless($token, 503, 'QA System credentials are not configured.');

        return Http::baseUrl(rtrim(config('services.qa_system.url'), '/'))
            ->withToken($token)
            ->acceptJson()
            ->throw();
    }

    private function login(): ?string
    {
        $email = config('services.qa_system.email');
        $password = config('services.qa_system.password');
        if (! $email || ! $password) return null;

        $payload = Http::baseUrl(rtrim(config('services.qa_system.url'), '/'))
            ->acceptJson()
            ->post('/auth/login', [
                'email' => $email,
                'password' => $password,
                'device_name' => config('services.qa_system.device_name'),
            ])
            ->throw()
            ->json();
        $token = data_get($payload, 'data.token', data_get($payload, 'token'));
        if ($token) Cache::store('file')->put('qa-system-api-token', $token, now()->addSeconds(config('services.qa_system.token_ttl')));
        return $token;
    }

    private function get(string $path, array $query = [])
    {
        try {
            return $this->client()->get($path, $query);
        } catch (RequestException $exception) {
            if ($exception->response?->status() !== 401) throw $exception;
            Cache::store('file')->forget('qa-system-api-token');
            return $this->client(true)->get($path, $query);
        }
    }

    private function assignedQaPlant(Request $request): ?array
    {
        $user = $request->user();
        if (! $user || $user->hasAllPlantAccess()) return null;
        abort_if($user->plant_id === null, 403, 'Plant belum ditetapkan untuk akun ini.');
        $localPlant = Plant::query()->findOrFail($user->plant_id);
        $payload = $this->get('/plants', ['per_page' => 100, 'is_active' => 'true'])->json();
        $plants = data_get($payload, 'data', []);
        $plant = collect(is_array($plants) ? $plants : [])->first(fn (array $item): bool => strcasecmp((string) ($item['code'] ?? ''), $localPlant->code) === 0);
        abort_unless($plant, 403, 'Plant akun ini tidak tersedia di QA System.');
        return $plant;
    }

    public function index(Request $request): JsonResponse
    {
        $query = $request->query();
        $assignedPlant = $this->assignedQaPlant($request);
        if ($assignedPlant) {
            $requestedPlant = $query['plant_id'] ?? $query['plant'] ?? null;
            abort_if($requestedPlant !== null && (string) $requestedPlant !== (string) $assignedPlant['id'], 403, 'Akun ini tidak memiliki akses ke Plant tersebut.');
            $query['plant_id'] = $assignedPlant['id'];
        }
        $query['per_page'] = min((int) ($query['per_page'] ?? 20), 100);
        if (! empty($query['plant'])) $query['plant_id'] = $query['plant'];
        if (isset($query['status']) && $query['status'] !== '') $query['is_active'] = $query['status'] === 'running' ? 'true' : 'false';
        unset($query['plant'], $query['status']);
        $response = $this->get('/machines', $query);
        $payload = $response->json();
        $items = data_get($payload, 'data', []);

        $machines = array_map(static function (array $machine): array {
            return [
                'id' => $machine['id'],
                'code' => $machine['code'],
                'name' => $machine['name'],
                'machine_number' => $machine['machine_number'] ?? '',
                'plant_id' => data_get($machine, 'plant.id'),
                'line_id' => data_get($machine, 'line.id'),
                'plant' => data_get($machine, 'plant.name', data_get($machine, 'plant.code', '')),
                'line' => data_get($machine, 'line.name', data_get($machine, 'line.code', '')),
                'location' => $machine['description'] ?? '',
                'status' => !empty($machine['is_active']) ? 'running' : 'offline',
                'is_active' => (bool) ($machine['is_active'] ?? false),
                'source' => 'qa-system',
            ];
        }, is_array($items) ? $items : []);
        return response()->json([
            'data' => $machines,
            'current_page' => data_get($payload, 'current_page', data_get($payload, 'meta.current_page', 1)),
            'last_page' => data_get($payload, 'last_page', data_get($payload, 'meta.last_page', 1)),
            'per_page' => data_get($payload, 'per_page', data_get($payload, 'meta.per_page', count($machines))),
            'total' => data_get($payload, 'total', data_get($payload, 'meta.total', count($machines))),
        ]);
    }

    public function plants(Request $request): JsonResponse
    {
        $payload = $this->get('/plants', ['per_page' => 100, 'is_active' => 'true'])->json();
        $items = data_get($payload, 'data', []);
        $assignedPlant = $this->assignedQaPlant($request);
        if ($assignedPlant) {
            $items = [$assignedPlant];
        }

        return response()->json(['data' => array_map(static fn (array $plant): array => [
            'id' => $plant['id'],
            'code' => $plant['code'],
            'name' => $plant['name'],
        ], is_array($items) ? $items : [])]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $filters = $request->only(['date', 'period', 'plant_id', 'line_id', 'machine_id', 'shift_id', 'product_id', 'checker_id']);
        $assignedPlant = $this->assignedQaPlant($request);
        if ($assignedPlant) {
            abort_if(isset($filters['plant_id']) && (string) $filters['plant_id'] !== (string) $assignedPlant['id'], 403, 'Akun ini tidak memiliki akses ke Plant tersebut.');
            $filters['plant_id'] = $assignedPlant['id'];
        }
        $payload = $this->get('/dashboard', $filters)->json();
        return response()->json(data_get($payload, 'data', $payload));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->client()->post('/machines', $request->all())->json(), 201);
    }

    public function update(Request $request, int $machine): JsonResponse
    {
        return response()->json($this->client()->put("/machines/{$machine}", $request->all())->json());
    }

    public function destroy(int $machine): JsonResponse
    {
        $this->client()->delete("/machines/{$machine}");
        return response()->json(null, 204);
    }
}
