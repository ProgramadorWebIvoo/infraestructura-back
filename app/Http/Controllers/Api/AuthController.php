<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no coinciden con nuestros registros.'],
            ]);
        }

        if ($user->isInactive()) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no coinciden con nuestros registros.'],
            ]);
        }

        $userPayload = [
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
            'role'  => $user->role,
        ];

        // Peticiones desde el dominio SPA configurado en SANCTUM_STATEFUL_DOMAINS
        // (EnsureFrontendRequestsAreStateful ya inició la sesión en este punto):
        // autenticación por cookie httpOnly de sesión, sin token expuesto a JS.
        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            return response()->json(['user' => $userPayload]);
        }

        // Clientes no-SPA (mobile): autenticación por token Bearer, sin cambios.
        if ($user->tokens()->count() >= 2) {
            $user->tokens()->oldest('created_at')->first()->delete();
        }

        $tokenName = $credentials['device_name'] ?? 'ivoo-infraestructura';
        $expiration = config('sanctum.expiration');
        $expiresAt = $expiration ? now()->addMinutes($expiration) : null;

        return response()->json([
            'token' => $user->createToken($tokenName, ['*'], $expiresAt)->plainTextToken,
            'user' => $userPayload,
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }
}
