<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * Configurable vía TRUSTED_PROXIES (ej. "*" si hay un reverse proxy /
     * load balancer / CDN propio delante de la app, o una lista de IPs
     * separadas por coma). Sin configurar, no se confía en ningún proxy:
     * `$request->isSecure()` reflejaría el esquema real de la conexión TCP,
     * lo cual rompe la detección de HTTPS (y por lo tanto cookies Secure y
     * HSTS) si la app está detrás de un proxy que termina TLS.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies;

    public function __construct()
    {
        $configured = config('app.trusted_proxies');
        $this->proxies = $configured === '*' || $configured === null
            ? $configured
            : array_map('trim', explode(',', $configured));
    }

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
