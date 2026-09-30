<?php

namespace App\Http\Middleware;

use App\Services\IdempotencyService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Idempotencia de mutaciones autenticadas vía header `Idempotency-Key`.
 * Debe ir al FINAL del grupo auth:sanctum / refresh.token / project.access, para
 * que un replay nunca salte la autorización. Toda la lógica vive en
 * IdempotencyService; este middleware solo decide qué responder.
 */
class Idempotency
{
    public function __construct(private readonly IdempotencyService $idempotency)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        if (! $this->idempotency->applies($request)) {
            return $next($request);
        }

        $key = $this->idempotency->keyFrom($request);

        if ($key === null) {
            if ($this->idempotency->mode() === IdempotencyService::MODE_ENFORCE) {
                return $this->idempotency->errorResponse(
                    'IDEMPOTENCY_KEY_REQUIRED',
                    'Falta el encabezado Idempotency-Key en esta operación.',
                    428,
                );
            }

            // Modo `log`: mide qué mutaciones aún no mandan clave antes de pasar a enforce (request_id va en el Context).
            Log::info('idempotency.key_missing', [
                'method' => $request->method(),
                'path' => $request->path(),
                'user_id' => $request->user()->getKey(),
            ]);

            return $next($request);
        }

        if (! $this->idempotency->isValidKey($key)) {
            return $this->idempotency->errorResponse(
                'IDEMPOTENCY_KEY_INVALID',
                'El encabezado Idempotency-Key debe ser un UUID.',
                422,
            );
        }

        [$outcome, $record] = $this->idempotency->begin($request, $key);

        switch ($outcome) {
            case IdempotencyService::OUTCOME_REPLAY:
                return $this->idempotency->replayResponse($record);

            case IdempotencyService::OUTCOME_IN_PROGRESS:
                $retryAfter = (int) config('idempotency.retry_after_seconds', 2);

                return $this->idempotency->errorResponse(
                    'IDEMPOTENCY_IN_PROGRESS',
                    'La misma operación sigue en proceso. Espera un momento.',
                    409,
                    ['Retry-After' => $retryAfter],
                );

            case IdempotencyService::OUTCOME_MISMATCH:
                return $this->idempotency->errorResponse(
                    'IDEMPOTENCY_KEY_REUSED',
                    'Esta operación ya se envió con datos distintos. Vuelve a intentarlo.',
                    422,
                );
        }

        // begin() agotó sus intentos sin marcador: se ejecuta sin protección.
        if ($record === null) {
            return $next($request);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->idempotency->release($record);

            throw $e;
        }

        $this->idempotency->complete($record, $response);

        return $response;
    }
}
