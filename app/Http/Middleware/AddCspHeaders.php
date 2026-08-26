<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AddCspHeaders
{
    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        // El WebSocket de Reverb corre en un host/puerto propio, distinto de
        // la API REST — sin sumarlo a connect-src, el navegador bloquea la
        // conexión del cliente Echo por CSP (no por un problema de Reverb en
        // sí). ws/wss según REVERB_SCHEME, coincidiendo con lo que expone
        // config/reverb.php al frontend.
        $reverbHost = env('REVERB_HOST');
        $reverbPort = env('REVERB_PORT', 443);
        $reverbWsScheme = env('REVERB_SCHEME', 'https') === 'https' ? 'wss' : 'ws';
        $reverbConnectSrc = $reverbHost
            ? " {$reverbWsScheme}://{$reverbHost}:{$reverbPort}"
            : '';

        $csp = "default-src 'self'; " .
               "script-src 'self'; " .
               "style-src 'self' 'unsafe-inline'; " .
               "img-src 'self' data:; " .
               "font-src 'self'; " .
               "connect-src 'self'{$reverbConnectSrc}; " .
               "frame-ancestors 'none'; " .
               "base-uri 'self'; " .
               "form-action 'self'; " .
               "upgrade-insecure-requests";

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // HSTS: le dice al navegador que recuerde usar siempre HTTPS con este
        // dominio, incluso si un enlace/bookmark antiguo apunta a http://.
        // Enviarlo sobre HTTP no tiene efecto (RFC 6797) — seguro incluirlo siempre.
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        return $response;
    }
}
