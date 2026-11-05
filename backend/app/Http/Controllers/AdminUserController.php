<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    private const ROLES = ['operator', 'technician', 'supervisor', 'qa', 'admin'];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $query = User::query();
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('nik', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }
        if ($request->filled('role') && in_array($request->query('role'), self::ROLES, true)) {
            $query->where('role', $request->query('role'));
        }
        if (in_array($request->query('status'), ['active', 'inactive'], true)) {
            $query->where('is_active', $request->query('status') === 'active');
        }

        $users = $query->orderBy('name')->paginate(min(max((int) $request->query('per_page', 10), 1), 100));
        $users->getCollection()->transform(fn (User $user): array => $this->userData($user));

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        if ($request->filled('username')) {
            $request->merge(['username' => mb_strtolower(trim((string) $request->input('username')))]);
        }
        $validated = $request->validate($this->rules(null, (string) $request->input('role')));
        $user = User::create([
            'name' => $validated['name'],
            'username' => isset($validated['username']) ? mb_strtolower($validated['username']) : null,
            'email' => $validated['email'],
            'plant_id' => $validated['role'] === 'admin' ? null : $validated['plant_id'],
            'phone' => $validated['phone'] ?? null,
            'nik' => $validated['nik'] ?? null,
            'role' => $validated['role'],
            'is_active' => $validated['is_active'] ?? true,
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json($this->userData($user), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);
        if ($request->filled('username')) {
            $request->merge(['username' => mb_strtolower(trim((string) $request->input('username')))]);
        }
        $validated = $request->validate($this->rules($user, (string) $request->input('role', $user->role)));

        DB::transaction(function () use ($request, $user, $validated): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $willRemoveAdmin = $lockedUser->role === 'admin'
                && $lockedUser->is_active
                && (($validated['role'] ?? $lockedUser->role) !== 'admin' || ! ($validated['is_active'] ?? $lockedUser->is_active));

            if ($willRemoveAdmin) {
                $otherActiveAdmins = User::query()->where('role', 'admin')->where('is_active', true)
                    ->where('id', '<>', $lockedUser->id)->lockForUpdate()->count();
                abort_if($otherActiveAdmins === 0, 422, 'Admin aktif terakhir tidak dapat dinonaktifkan atau diturunkan perannya.');
            }

            $profile = array_intersect_key($validated, array_flip(['name', 'email', 'phone', 'nik', 'role', 'is_active']));
            if (array_key_exists('plant_id', $validated) || array_key_exists('role', $validated)) {
                $profile['plant_id'] = ($validated['role'] ?? $lockedUser->role) === 'admin' ? null : $validated['plant_id'];
            }
            if (array_key_exists('username', $validated)) {
                $profile['username'] = $validated['username'] === null ? null : mb_strtolower($validated['username']);
            }
            $lockedUser->fill($profile);
            if (! empty($validated['password'])) {
                $lockedUser->password = Hash::make($validated['password']);
            }
            $lockedUser->save();

            if (! $lockedUser->is_active) {
                $lockedUser->tokens()->delete();
            }
        });

        return response()->json($this->userData($user->fresh()));
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin', 403);
    }

    private function rules(?User $user = null, ?string $role = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => [$user ? 'nullable' : 'required', 'string', 'min:3', 'max:50', 'regex:/^[a-zA-Z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user?->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'nik' => ['nullable', 'string', 'max:100'],
            'role' => ['required', Rule::in(self::ROLES)],
            'plant_id' => [($role ?? $user?->role) === 'admin' ? 'nullable' : 'required', 'nullable', 'integer', Rule::exists('plants', 'id')->where(fn ($query) => $query->where('is_active', true))],
            'is_active' => ['sometimes', 'boolean'],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
        ];
    }

    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'plant_id' => $user->plant_id,
            'phone' => $user->phone,
            'nik' => $user->nik,
            'role' => $user->role,
            'is_active' => $user->is_active,
        ];
    }
}
