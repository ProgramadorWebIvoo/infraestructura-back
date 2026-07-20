<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RefreshSanctumToken
{
    private const REFRESH_BEFORE_MINUTES = 60;

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        $user = $request->user();
        if (! $user) {
            return $response;
        }

        $token = $user->currentAccessToken();
        if (! $token) {
            return $response;
        }

        $expiration = config('sanctum.expiration');
        if (! $expiration) {
            return $response;
        }

        // Check if token is within REFRESH_BEFORE_MINUTES of its config-based expiration
        $createdAt = $token->created_at;
        $tokenExpiresAt = $createdAt->copy()->addMinutes($expiration);
        $threshold = now()->addMinutes(self::REFRESH_BEFORE_MINUTES);

        if ($tokenExpiresAt->lte($threshold)) {
            // Grace period: keep old token valid for 60s for concurrent in-flight requests
            $token->expires_at = now()->addSeconds(60);
            $token->save();

            // Rotate: create new token preserving name and abilities
            $newToken = $user->createToken(
                $token->name,
                $token->abilities ?? ['*'],
                now()->addMinutes($expiration)
            );

            $response->headers->set('X-Refresh-Token', $newToken->plainTextToken);
        }

        return $response;
    }
}
