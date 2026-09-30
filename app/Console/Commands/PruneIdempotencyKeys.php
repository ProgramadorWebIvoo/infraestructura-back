<?php

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use Illuminate\Console\Command;

class PruneIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:prune';
    protected $description = 'Elimina las claves de idempotencia vencidas (TTL) y los marcadores huérfanos de peticiones que nunca terminaron';

    private const CHUNK = 1000;

    public function handle(): int
    {
        $ttlHours = (int) config('idempotency.ttl_hours', 72);

        $completed = $this->deleteInChunks(
            IdempotencyKey::where('status', IdempotencyKey::STATUS_COMPLETED)
                ->where('completed_at', '<', now()->subHours($ttlHours))
        );

        // `processing` con el bloqueo vencido hace más de un día: el proceso murió y nadie reintentó.
        $orphans = $this->deleteInChunks(
            IdempotencyKey::where('status', IdempotencyKey::STATUS_PROCESSING)
                ->where('locked_until', '<', now()->subDay())
        );

        $this->info("Eliminadas {$completed} clave(s) completada(s) con más de {$ttlHours} h y {$orphans} marcador(es) huérfano(s).");

        return self::SUCCESS;
    }

    /** Borra por lotes para no mantener un DELETE masivo bloqueando la tabla caliente. */
    private function deleteInChunks($query): int
    {
        $total = 0;

        do {
            $deleted = (clone $query)->limit(self::CHUNK)->delete();
            $total += $deleted;
        } while ($deleted === self::CHUNK);

        return $total;
    }
}
