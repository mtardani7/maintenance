<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            return response()->json(['message' => 'Invalid email or password.'], 422);
        }

        if (! $request->user()->is_active) {
            Auth::logout();

            return response()->json(['message' => 'Invalid email or password.'], 422);
        }

        return response()->json([
            'token' => $request->user()->createToken($request->input('device_name', 'maintenance-web'))->plainTextToken,
            'user' => $request->user(),
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
