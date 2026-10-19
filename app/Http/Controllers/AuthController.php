<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9._-]+$/'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);
        $user = User::where('username', $data['username'])->first();
        $valid = Hash::check($data['password'], $user?->password ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        abort_unless($valid && $user?->is_active, 401, 'Nieprawidłowy login lub hasło.');
        $expiresAt = now()->addDays(30);
        $token = $user->createToken($data['device_name'], ['admin'], $expiresAt);

        return response()->json(['token' => $token->plainTextToken, 'token_type' => 'Bearer', 'expires_at' => $expiresAt->toISOString()])->header('Cache-Control', 'no-store');
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => ['id' => $request->user()->id, 'username' => $request->user()->username]])->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
