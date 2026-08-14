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
}
