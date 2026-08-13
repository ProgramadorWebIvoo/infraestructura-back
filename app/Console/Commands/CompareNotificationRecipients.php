<?php

namespace App\Console\Commands;

use App\Services\NotificationDispatcher;
use App\Services\NotificationRuleResolver;
use Illuminate\Console\Command;

/**
 * Gate de verificación antes de activar `usar_matriz_notificaciones`: para
 * cada acción preexistente (con equivalente legacy por status), compara los
 * ROLES que devuelven ambos caminos — legacy (recipientsFor por status) y la
 * nueva matriz (por acción). Compara roles configurados, no usuarios
 * concretos: si un rol no tiene ningún usuario activo en esta BD, ambos
 * caminos coinciden en "cero destinatarios reales" igual, así que basta con
 * verificar que el conjunto de roles habilitados sea idéntico. Debe salir
 * sin diferencias antes de avanzar el rollout (ver Fase D del plan de
 * notificaciones configurables). Solo lectura, no muta nada.
 */
class CompareNotificationRecipients extends Command
{
    protected $signature = 'notifications:compare-recipients';
    protected $description = 'Compara roles destinatarios legacy (por status) vs. la matriz configurable (por acción) para las acciones preexistentes';

    /** Acción => status del proyecto donde ocurre — el mismo mapeo usado al sembrar notification_rules. */
    private const ACTION_STATUS_MAP = [
        'Creacion de peticion de obra' => 'CREADO',
        'Revision tecnica de calculos y planos' => 'REVISADO_CIERRE',
        'Confirmacion de presupuesto y envio a licitacion' => 'CONFIRMADO_PROCURA',
        'Carga de propuesta' => 'COMPARATIVA_ENVIADA',
        'Carga de cuadro comparativo' => 'COMPARATIVA_ENVIADA',
        'Importación automática de propuestas de proveedores' => 'COMPARATIVA_ENVIADA',
        'Eliminacion de propuesta' => 'COMPARATIVA_ENVIADA',
        'Rechazo de cuadro comparativo' => 'COMPARATIVA_ENVIADA',
        'Confirmacion de contratacion' => 'CONTRATADO',
        'Liberacion de anticipo' => 'EN_EJECUCION',
        'Reporte de obra finalizada' => 'VERIFICANDO_FINALIZACION',
        'Verificacion de finalizacion y calidad de obra' => 'LISTO_PAGO_FINAL',
        'Liberacion total de fondos' => 'COMPLETADO_PAGADO',
    ];

    public function handle(): int
    {
        $mismatches = 0;

        foreach (self::ACTION_STATUS_MAP as $action => $status) {
            $legacyRoles = collect(NotificationDispatcher::rolesForStatus($status))
                ->unique()->sort()->values();
            $matrixRoles = collect(NotificationRuleResolver::rolesFor($action, 'app'))
                ->unique()->sort()->values();

            if ($legacyRoles->toArray() !== $matrixRoles->toArray()) {
                $mismatches++;
                $this->error("MISMATCH [{$action}] (status: {$status})");
                $this->line('  legacy: ' . $legacyRoles->implode(', '));
                $this->line('  matrix: ' . $matrixRoles->implode(', '));
            } else {
                $this->info("OK [{$action}]");
            }
        }

        if ($mismatches > 0) {
            $this->newLine();
            $this->error("{$mismatches} acción(es) con diferencias — NO activar usar_matriz_notificaciones todavía.");
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Sin diferencias — la matriz reproduce exactamente el comportamiento legacy para las acciones preexistentes.');
        return self::SUCCESS;
    }
}
