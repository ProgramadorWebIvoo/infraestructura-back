<?php

namespace App\Support;

/**
 * Taxonomía de 6 tipos de notificación pedida por el plan de 90 días (1.1):
 * información, éxito, advertencia, error, acción_requerida, prioritario.
 * Fuente única — backend (columna `app_notifications.type`) y frontend
 * (`AlertType` en Toast.tsx) deben usar los mismos 6 valores string.
 */
final class NotificationType
{
    public const INFORMACION = 'informacion';
    public const EXITO = 'exito';
    public const ADVERTENCIA = 'advertencia';
    public const ERROR = 'error';
    public const ACCION_REQUERIDA = 'accion_requerida';
    public const PRIORITARIO = 'prioritario';

    public const ALL = [
        self::INFORMACION,
        self::EXITO,
        self::ADVERTENCIA,
        self::ERROR,
        self::ACCION_REQUERIDA,
        self::PRIORITARIO,
    ];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }
}
