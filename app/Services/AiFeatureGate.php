<?php

namespace App\Services;

use App\Models\AiFeatureToggle;
use App\Support\AiFeatureCatalog;
use Illuminate\Support\Facades\Cache;

/**
 * Resuelve si una función de IA está habilitada para un departamento/acción
 * — mismo patrón de caché que NotificationRuleResolver: una sola clave con
 * toda la tabla, invalidada explícitamente en cada escritura (no por TTL
 * corto, para que el toggle se sienta inmediato en toda la sesión).
 *
 * Sin fila configurada = habilitado (fail-open a favor de las features que ya
 * existían antes de este sistema de toggles — Procura/Auditoría no
 * deben apagarse solas al desplegar esto).
 */
class AiFeatureGate
{
    private const CACHE_KEY = 'ai_feature_toggles.all';
    private const CACHE_TTL_SECONDS = 300;

    /** @return array<string, array{master: bool, actions: array<string, bool>}> */
    public static function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            $map = [];
            foreach (AiFeatureToggle::all() as $row) {
                if ($row->action === null) {
                    $map[$row->department]['master'] = $row->enabled;
                } else {
                    $map[$row->department]['actions'][$row->action] = $row->enabled;
                }
            }
            return $map;
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public static function isDepartmentEnabled(string $department): bool
    {
        return self::all()[$department]['master'] ?? true;
    }

    public static function isActionEnabled(string $department, string $action): bool
    {
        return self::all()[$department]['actions'][$action] ?? true;
    }

    /** Habilitado en efecto = interruptor maestro Y específico, ambos en true. */
    public static function isEnabled(string $department, string $action): bool
    {
        return self::isDepartmentEnabled($department) && self::isActionEnabled($department, $action);
    }

    public static function setDepartmentEnabled(string $department, bool $enabled): void
    {
        AiFeatureToggle::updateOrCreate(
            ['department' => $department, 'action' => null],
            ['enabled' => $enabled],
        );
        self::forget();
    }

    public static function setActionEnabled(string $department, string $action, bool $enabled): void
    {
        AiFeatureToggle::updateOrCreate(
            ['department' => $department, 'action' => $action],
            ['enabled' => $enabled],
        );
        self::forget();
    }

    /**
     * Matriz completa para la UI de Config IA: departamento => {master, actions: {accion: bool}}
     * — incluye todas las acciones del catálogo aunque no tengan fila (default true),
     * igual criterio que NotificationRuleResolver::matrix().
     */
    public static function matrix(): array
    {
        $matrix = [];
        foreach (AiFeatureCatalog::departments() as $department) {
            $actions = [];
            foreach (AiFeatureCatalog::keysForDepartment($department) as $action) {
                $actions[$action] = self::isActionEnabled($department, $action);
            }
            $matrix[$department] = [
                'master' => self::isDepartmentEnabled($department),
                'actions' => $actions,
            ];
        }
        return $matrix;
    }
}
