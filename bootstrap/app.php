<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // Sin `channels:` acá a propósito: withRouting() registraría
        // /broadcasting/auth automáticamente bajo el grupo 'web' (sin
        // Sanctum stateful), compitiendo con el registro real que hace
        // BroadcastServiceProvider::boot() vía Broadcast::routes(['middleware'
        // => ['api']]) — con ambos activos, la ruta bajo 'web' gana (se
        // registra primero) y /broadcasting/auth queda sin
        // EnsureFrontendRequestsAreStateful, así que la sesión del SPA nunca
        // autentica ahí aunque el resto de /api/* funcione normal. Sin este
        // parámetro, BroadcastServiceProvider es la única fuente de verdad.
    )
    ->withMiddleware(function (Middleware $middleware) {
        // API-only SPA: no existe ninguna ruta web 'login'. El default de
        // Laravel 12 (redirectGuestsTo(fn () => route('login'))) revienta con
        // RouteNotFoundException -> 500 en vez de 401 cuando un guest sin
        // Accept: application/json pega a una ruta auth:sanctum (confirmado
        // en prueba de estrés 2026-09-16). Sin redirect: siempre JSON 401.
        $middleware->redirectGuestsTo(fn () => null);

        // Replica exactamente el stack global de app/Http/Kernel.php (L9) —
        // TrustHosts queda deshabilitado (estaba comentado en el Kernel
        // original) y TrustProxies mantiene su clase custom (resuelve
        // TRUSTED_PROXIES desde config/env dinámicamente, algo que el helper
        // nativo trustProxies() no soporta con un valor no estático).
        $middleware->use([
            \App\Http\Middleware\AttachRequestId::class,
            \App\Http\Middleware\TrustProxies::class,
            \Illuminate\Http\Middleware\HandleCors::class,
            \App\Http\Middleware\PreventRequestsDuringMaintenance::class,
            \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
            \App\Http\Middleware\TrimStrings::class,
            \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
        ]);

        $middleware->group('web', [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        $middleware->group('api', [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\AddCspHeaders::class,
        ]);

        $middleware->alias([
            'auth' => \App\Http\Middleware\Authenticate::class,
            'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
            'auth.session' => \Illuminate\Session\Middleware\AuthenticateSession::class,
            'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
            'can' => \Illuminate\Auth\Middleware\Authorize::class,
            'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
            'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
            'signed' => \App\Http\Middleware\ValidateSignature::class,
            'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
            'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
            'role' => \App\Http\Middleware\CheckRole::class,
            'refresh.token' => \App\Http\Middleware\RefreshSanctumToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // API-only backend: toda request a /api/* debe recibir JSON en sus
        // errores, sin importar el header Accept del cliente (bots, health
        // checks, curl sin -H). Sin esto, un guest sin Accept: application/
        // json cae al branch de redirect y explota con RouteNotFoundException
        // porque no existe ninguna ruta web 'login' (confirmado en prueba de
        // estrés 2026-09-16).
        $exceptions->shouldRenderJsonWhen(fn ($request, $e) => $request->is('api/*') || $request->expectsJson());

        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
        ]);

        $exceptions->render(function (\App\Exceptions\FileRejectedException $e, \Illuminate\Http\Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        });
    })
    ->create();
