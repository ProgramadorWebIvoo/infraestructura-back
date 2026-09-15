<?php

namespace App\Models\Concerns;

/**
 * `user_id` + `user_name_snapshot` se calculaban igual (mismo `auth()->user()`,
 * mismos dos campos) en AuditLog::record() y en el `actorSnapshot()` privado
 * de ConfigAuditLog — único punto ahora. Cada modelo sigue agregando su
 * propio campo de timestamp (`logged_at` / `changed_at`), que no es parte
 * del "actor" y difiere entre ambos.
 */
trait HasAuditActorSnapshot
{
    protected static function auditActorSnapshot(): array
    {
        $user = auth()->user();

        return [
            'user_id' => $user?->id,
            'user_name_snapshot' => $user?->name,
        ];
    }
}
