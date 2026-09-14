<?php

namespace App\Support;

/**
 * Catálogo único de acciones de IA integradas en la app, por departamento —
 * fuente de verdad tanto para la matriz de "Control por Departamento" en
 * Config IA como para el gate server-side (App\Services\AiFeatureGate) que
 * cada endpoint de evaluación IA consulta antes de invocar
 * AIEvaluationService. Mismo criterio que App\Support\NotificationCatalog:
 * "qué acciones existen" (dato, en código) separado de "cómo se resuelve el
 * estado" (comportamiento, en AiFeatureGate).
 *
 * Departamento = App\Support\Roles::VALID (no se inventa una taxonomía
 * nueva). Marketing e Infraestructura no tienen entradas — quedan fuera del
 * alcance de IA por decisión de negocio; agregarlos después es solo sumar
 * entradas acá, sin tocar el motor de toggles.
 */
class AiFeatureCatalog
{
    /**
     * @var array<string, array{label: string, department: string, description: string}>
     */
    private const ACTIONS = [
        'ia.procura.evaluacion_propuestas' => [
            'label' => 'Evaluación inteligente de propuestas',
            'department' => 'PROCURA',
            'description' => 'Analiza el cuadro comparativo de ofertas y sugiere el contratista ganador con justificación.',
        ],
        'ia.cierre_obra.evaluacion_expediente' => [
            'label' => 'Evaluación de expediente',
            'department' => 'CIERRE_DE_OBRA',
            'description' => 'Evalúa completitud y riesgo del expediente técnico al auditar una petición de obra.',
        ],
        'ia.analistas.evaluacion_propuestas' => [
            'label' => 'Vista previa de evaluación de propuestas',
            'department' => 'ANALISTA',
            'description' => 'Misma evaluación IA de Procura, disponible como vista previa antes de enviar el cuadro comparativo.',
        ],
        'ia.proveedores.sugerencia_rating' => [
            'label' => 'Sugerencia de rating de proveedor',
            'department' => 'CATALOGOS',
            'description' => 'Sugiere un ajuste de rating basado en el historial de cotizaciones y adjudicaciones del proveedor (no autoritativa: el rating sigue siendo manual).',
        ],
    ];

    /** Departamentos con al menos una acción de IA en el catálogo, en orden estable. */
    public static function departments(): array
    {
        $departments = [];
        foreach (self::ACTIONS as $entry) {
            $departments[$entry['department']] = true;
        }
        return array_keys($departments);
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::ACTIONS);
    }

    /** @return string[] Acciones del catálogo que pertenecen a un departamento dado. */
    public static function keysForDepartment(string $department): array
    {
        return array_values(array_filter(
            self::keys(),
            fn (string $action) => self::ACTIONS[$action]['department'] === $department,
        ));
    }

    public static function label(string $action): string
    {
        return self::ACTIONS[$action]['label'] ?? $action;
    }

    public static function department(string $action): ?string
    {
        return self::ACTIONS[$action]['department'] ?? null;
    }

    public static function exists(string $action): bool
    {
        return array_key_exists($action, self::ACTIONS);
    }

    /** @return array{value: string, label: string, department: string, description: string}[] */
    public static function toOptions(): array
    {
        return array_map(
            fn (string $action) => [
                'value' => $action,
                'label' => self::label($action),
                'department' => self::ACTIONS[$action]['department'],
                'description' => self::ACTIONS[$action]['description'],
            ],
            self::keys(),
        );
    }
}
