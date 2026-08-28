<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Cada request recibe un ID de correlación — visible en TODOS los logs
 * generados durante su ciclo de vida (vía Context::add, que Laravel adjunta
 * automáticamente a cada línea de log) y devuelto en el header de respuesta.
 * Sin esto, correlacionar logs de una misma petición en producción requiere
 * cruzar timestamps a ojo — imposible bajo carga concurrente.
 *
 * Reusa el X-Request-ID entrante si el cliente/proxy ya lo manda (permite
 * trazar un request desde el edge hasta el log de Laravel), genera uno
 * nuevo si no.
 */
class AttachRequestId
{
    public function handle(Request $request, Closure $next): mixed
    {
        $requestId = $request->header('X-Request-ID') ?: (string) Str::uuid();

        Context::add('request_id', $requestId);

        $response = $next($request);

        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
