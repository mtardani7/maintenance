<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'identifier' => ['nullable', 'string', 'required_without:email', 'max:255'],
            'email' => ['nullable', 'string', 'required_without:identifier', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $identifier = mb_strtolower(trim((string) ($credentials['identifier'] ?? $credentials['email'] ?? '')));
        $userQuery = User::query();
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $userQuery->whereRaw('LOWER(email) = ?', [$identifier]);
        } else {
            $userQuery->whereRaw('LOWER(username) = ?', [$identifier]);
        }

        $user = $userQuery->first();
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Username/email atau kata sandi salah.'], 422);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Username/email atau kata sandi salah.'], 422);
        }

        return response()->json([
            'token' => $user->createToken($request->input('device_name', 'maintenance-web'))->plainTextToken,
            'user' => $user,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(null, 204);
    }
}
