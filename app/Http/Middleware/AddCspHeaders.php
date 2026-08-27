<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AddCspHeaders
{
    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        // Pusher Channels (WebSocket) corre en su propio dominio, distinto
        // de la API REST — sin sumarlo a connect-src, el navegador bloquea
        // la conexión del cliente Echo por CSP (no por un problema de Pusher
        // en sí). Migrado de Reverb (self-hosted) a Pusher (SaaS) porque el
        // hosting de producción es cPanel compartido, que no soporta
        // procesos persistentes de larga duración (artisan reverb:start).
        // PUSHER_APP_CLUSTER determina el subdominio real (ej. mt1) — debe
        // coincidir con VITE_PUSHER_APP_CLUSTER del frontend.
        $pusherCluster = env('PUSHER_APP_CLUSTER');
        $pusherConnectSrc = $pusherCluster
            ? " https://sockjs-{$pusherCluster}.pusher.com wss://ws-{$pusherCluster}.pusher.com"
            : '';

        $csp = "default-src 'self'; " .
               "script-src 'self'; " .
               "style-src 'self' 'unsafe-inline'; " .
               "img-src 'self' data:; " .
               "font-src 'self'; " .
               "connect-src 'self'{$pusherConnectSrc}; " .
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
