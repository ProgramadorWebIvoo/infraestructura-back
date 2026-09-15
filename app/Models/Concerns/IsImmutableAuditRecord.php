<?php

namespace App\Models\Concerns;

/**
 * Extraído de AuditLog/ConfigAuditLog (Fase 1 del plan de refuerzo de
 * auditorías) — ambos modelos bloqueaban update/delete con el mismo
 * `booted()` duplicado carácter por carácter. Convención Laravel
 * `boot{TraitName}` para que el modelo conserve su propio `booted()` si lo
 * necesita, sin pisar este.
 */
trait IsImmutableAuditRecord
{
    protected static function bootIsImmutableAuditRecord(): void
    {
        static::updating(function () {
            throw new \RuntimeException('Los registros de auditoría son inmutables y no pueden modificarse.');
        });

        static::deleting(function () {
            throw new \RuntimeException('Los registros de auditoría no pueden eliminarse.');
        });
    }
}
