<?php

namespace Tests\Unit;

use App\Support\NotificationCatalog;
use App\Support\NotificationType;
use PHPUnit\Framework\TestCase;

class NotificationCatalogTest extends TestCase
{
    public function test_every_catalog_action_resolves_to_a_valid_notification_type(): void
    {
        foreach (NotificationCatalog::keys() as $action) {
            $this->assertTrue(
                NotificationType::isValid(NotificationCatalog::type($action)),
                "La acción \"{$action}\" resuelve a un NotificationType inválido.",
            );
        }
    }

    public function test_type_override_takes_precedence_over_critical_default(): void
    {
        $this->assertSame(NotificationType::ACCION_REQUERIDA, NotificationCatalog::type('Rechazo de cuadro comparativo'));
        $this->assertTrue(NotificationCatalog::isCritical('Rechazo de cuadro comparativo'));
    }

    public function test_critical_action_without_override_defaults_to_prioritario(): void
    {
        $this->assertSame(NotificationType::PRIORITARIO, NotificationCatalog::type('Liberacion total de fondos'));
    }

    public function test_non_critical_action_defaults_to_informacion(): void
    {
        $this->assertSame(NotificationType::INFORMACION, NotificationCatalog::type('Creacion de peticion de obra'));
    }

    /**
     * Regresión: ProjectDocumentController::upload() arma la acción de
     * auditoría interpolando un label por tipo de documento ($type: CALC,
     * PLANO, FOTO, CORRECCION — ver App\Models\ProjectDocument). Si alguno
     * de los 4 tipos no tiene su "Carga de ..." correspondiente en el
     * catálogo, esa acción cae silenciosamente al fallback de
     * NotificationRuleResolver (solo SUPERADMIN/ADMIN) en vez de respetar la
     * configuración real de CONFIG APP — exactamente lo que pasó con FOTO
     * ("Carga de fotografias del sitio de obra" faltaba por completo).
     */
    public function test_every_document_upload_action_is_catalogued(): void
    {
        $labelsByType = [
            'CALC' => 'hojas de calculo/cubicaciones',
            'PLANO' => 'planos de ingenieria',
            'FOTO' => 'fotografias del sitio de obra',
            'CORRECCION' => 'correcciones de peticion rechazada',
        ];

        foreach ($labelsByType as $type => $label) {
            $action = "Carga de {$label}";
            $this->assertTrue(
                NotificationCatalog::exists($action),
                "El tipo de documento \"{$type}\" produce la acción \"{$action}\", ausente del catálogo.",
            );
        }

        // La versión de reemplazo de un documento existente usa una acción
        // fija, sin importar el tipo original.
        $this->assertTrue(NotificationCatalog::exists('Carga de nueva version de documento'));
    }
}
