<?php

namespace App\Services;

use App\Models\IdempotencyKey;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lógica de las claves de idempotencia (PLAN-Idempotencia). El middleware solo
 * orquesta; todo lo demás vive aquí para poder probarse y reutilizarse.
 *
 * Garantías:
 *  - "El primero gana": el INSERT del marcador `processing` choca con
 *    UNIQUE(user_id, key) para la segunda petición simultánea.
 *  - Una clave reutilizada con otro payload se detecta por `request_hash`.
 *  - Un `processing` huérfano (proceso caído) se retoma al vencer `locked_until`
 *    con un UPDATE condicional (compare-and-swap): solo una petición lo gana.
 *  - Solo se conservan respuestas JSON 2xx y 204; cualquier otra libera la clave
 *    para que el reintento se evalúe de nuevo.
 *
 * El marcador se escribe con autocommit: begin() debe ejecutarse ANTES de que el
 * controller abra su transacción, o un rollback borraría el marcador.
 */
class IdempotencyService
{
    public const MODE_OFF = 'off';
    public const MODE_LOG = 'log';
    public const MODE_ENFORCE = 'enforce';

    public const OUTCOME_PROCEED = 'proceed';
    public const OUTCOME_REPLAY = 'replay';
    public const OUTCOME_IN_PROGRESS = 'in_progress';
    public const OUTCOME_MISMATCH = 'mismatch';

    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public const MODE_SETTING = 'idempotencia_modo';


    /** @return array<int, string> */
    public static function modes(): array
    {
        return [self::MODE_OFF, self::MODE_LOG, self::MODE_ENFORCE];
    }

    /** CONFIG APP manda (cambia sin redeploy); config/env es el valor de respaldo si la fila no existe. */
    public function mode(): string
    {
        $mode = (string) SettingsService::get(self::MODE_SETTING, config('idempotency.mode', self::MODE_OFF));

        return in_array($mode, self::modes(), true) ? $mode : self::MODE_OFF;
    }

    /** ¿Esta petición entra al circuito de idempotencia? */
    public function applies(Request $request): bool
    {
        return $this->mode() !== self::MODE_OFF
            && $request->user() !== null
            && in_array($request->method(), self::MUTATING_METHODS, true)
            && ! $request->is(...(array) config('idempotency.exempt', []));
    }

    public function keyFrom(Request $request): ?string
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        return $key === '' ? null : $key;
    }

    /** UUID en cualquier versión: es lo que genera el front con crypto.randomUUID(). */
    public function isValidKey(string $key): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key);
    }

    /**
     * Huella de la petición: método + ruta real (con los IDs, no el patrón) + entrada
     * canónica. Los archivos aportan el SHA-256 de su contenido: nombre y tamaño no
     * bastan para distinguir dos comprobantes distintos.
     */
    public function fingerprint(Request $request): string
    {
        $payload = [
            'method' => $request->method(),
            'path' => $request->path(),
            'input' => $this->canonicalize($request->input()),
            'files' => $this->fileHashes($request->allFiles()),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * @return array{0: string, 1: IdempotencyKey|null} [outcome, registro]
     */
    public function begin(Request $request, string $key): array
    {
        $userId = $request->user()->getKey();
        $hash = $this->fingerprint($request);
        $lockSeconds = $this->lockSeconds($request);

        // Dos vueltas: si la fila desaparece entre el INSERT fallido y la lectura
        // (prune/liberación concurrente), se reintenta el INSERT una vez.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $record = IdempotencyKey::create([
                    'user_id' => $userId,
                    'key' => $key,
                    'method' => $request->method(),
                    'path' => mb_substr($request->path(), 0, 255),
                    'request_hash' => $hash,
                    'status' => IdempotencyKey::STATUS_PROCESSING,
                    'locked_until' => now()->addSeconds($lockSeconds),
                ]);

                return [self::OUTCOME_PROCEED, $record];
            } catch (UniqueConstraintViolationException) {
                // Ya existe: se resuelve abajo.
            }

            $existing = IdempotencyKey::where('user_id', $userId)->where('key', $key)->first();
            if (! $existing) {
                continue;
            }

            if ($existing->request_hash !== $hash) {
                return [self::OUTCOME_MISMATCH, $existing];
            }

            if ($existing->status === IdempotencyKey::STATUS_COMPLETED) {
                if ($this->isExpired($existing)) {
                    $existing->delete();
                    continue;
                }

                return [self::OUTCOME_REPLAY, $existing];
            }

            if ($existing->locked_until && $existing->locked_until->isFuture()) {
                return [self::OUTCOME_IN_PROGRESS, $existing];
            }

            // `processing` vencido: el proceso original murió. Solo una petición gana el lock.
            $taken = IdempotencyKey::whereKey($existing->getKey())
                ->where('status', IdempotencyKey::STATUS_PROCESSING)
                ->where('locked_until', '<', now())
                ->update(['locked_until' => now()->addSeconds($lockSeconds), 'updated_at' => now()]);

            return $taken === 1
                ? [self::OUTCOME_PROCEED, $existing->fresh()]
                : [self::OUTCOME_IN_PROGRESS, $existing];
        }

        // No debería ocurrir; ante la duda se deja pasar sin proteger antes que bloquear al usuario.
        Log::warning('idempotency.begin_exhausted', ['user_id' => $userId, 'path' => $request->path()]);

        return [self::OUTCOME_PROCEED, null];
    }

    /** Guarda la respuesta si es cacheable; si no, libera la clave para que el reintento se reevalúe. */
    public function complete(IdempotencyKey $record, Response $response): void
    {
        $status = $response->getStatusCode();
        $isNoContent = $status === 204;

        if ($status < 200 || $status >= 300 || (! $isNoContent && ! $response instanceof JsonResponse)) {
            $this->release($record);

            return;
        }

        $body = $isNoContent ? null : $response->getContent();
        $omitted = false;

        if ($body !== null && strlen($body) > (int) config('idempotency.max_body_bytes')) {
            Log::notice('idempotency.response_omitted', [
                'path' => $record->path,
                'bytes' => strlen($body),
            ]);
            $body = null;
            $omitted = true;
        }

        // Solo estas cabeceras: X-Refresh-Token u otras por-petición nunca se reproducen.
        $headers = array_filter(['Location' => $response->headers->get('Location')]);

        $record->update([
            'status' => IdempotencyKey::STATUS_COMPLETED,
            'response_status' => $status,
            'response_body' => $body,
            'response_omitted' => $omitted,
            'response_headers' => $headers ?: null,
            'locked_until' => null,
            'completed_at' => now(),
        ]);
    }

    public function release(IdempotencyKey $record): void
    {
        $record->delete();
    }

    /** Respuesta de un replay: mismo status que la original, marcada con Idempotent-Replayed. */
    public function replayResponse(IdempotencyKey $record): Response
    {
        $status = (int) $record->response_status;

        if ($status === 204) {
            $response = response()->noContent();
        } elseif ($record->response_omitted) {
            $response = response()->json([
                'replayed' => true,
                'code' => 'IDEMPOTENCY_RESPONSE_OMITTED',
                'message' => 'La operación ya se había aplicado; actualiza la información para ver el resultado.',
            ], $status);
        } else {
            $response = new Response((string) $record->response_body, $status, ['Content-Type' => 'application/json']);
        }

        foreach ((array) $record->response_headers as $name => $value) {
            $response->headers->set($name, $value);
        }
        $response->headers->set('Idempotent-Replayed', 'true');

        return $response;
    }

    /** Respuesta de error con `code` estable que el front traduce a mensaje de UI. */
    public function errorResponse(string $code, string $message, int $status, array $headers = []): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status, $headers);
    }

    private function lockSeconds(Request $request): int
    {
        foreach ((array) config('idempotency.lock_seconds_by_path', []) as $pattern => $seconds) {
            if ($request->is($pattern)) {
                return (int) $seconds;
            }
        }

        return (int) config('idempotency.lock_seconds', 60);
    }

    private function isExpired(IdempotencyKey $record): bool
    {
        return $record->completed_at !== null
            && $record->completed_at->lt(now()->subHours((int) config('idempotency.ttl_hours', 72)));
    }

    /** Ordena las claves de los objetos (no el orden de las listas) para que el hash no dependa del orden del cliente. */
    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn ($item) => $this->canonicalize($item), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /** @return array<string, string> campo => sha256 del contenido */
    private function fileHashes(array $files, string $prefix = ''): array
    {
        $hashes = [];

        foreach ($files as $field => $file) {
            $name = $prefix === '' ? (string) $field : "{$prefix}.{$field}";

            if (is_array($file)) {
                $hashes += $this->fileHashes($file, $name);
                continue;
            }

            $path = $file->getRealPath();
            $hashes[$name] = $path && is_file($path)
                ? (string) hash_file('sha256', $path)
                : "{$file->getClientOriginalName()}:{$file->getSize()}";
        }

        ksort($hashes);

        return $hashes;
    }
}
