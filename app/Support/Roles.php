<?php

namespace App\Support;

/**
 * Catálogo único de roles válidos — antes vivía como `private const` dentro
 * de UserController, invisible para cualquier otro consumidor (el seeder de
 * `notification_rules`, el validador de `NotificationRuleController`, y el
 * futuro selector de roles en la matriz de notificaciones necesitan la misma
 * lista). No existe tabla `roles` en BD — `users.role` es un string libre
 * (sin ENUM), validado en la capa de aplicación contra esta constante.
 */
class Roles
{
    public const VALID = [
        'SUPERADMIN', 'ADMIN', 'PRESIDENCIA', 'INFRAESTRUCTURA',
        'CIERRE_DE_OBRA', 'PROCURA', 'ANALISTA', 'FINANZAS', 'CATALOGOS',
        'MARKETING',
    ];
}
