<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Sembrado por equivalencia exacta: traduce cada rama del
     * `recipientsFor($status, ...)` legacy (indexado por estado de proyecto)
     * a filas por ACCIÓN — la correspondencia acción→status es 1:1 en el
     * flujo actual (cada acción de negocio ocurre en un único estado). Las
     * acciones nuevas de la Fase A (sin equivalente legacy) se siembran
     * conservadoramente con destinatarios reales y con sentido funcional —
     * no quedan huérfanas dependiendo de que alguien las configure después.
     *
     * Idempotente vía upsert sobre el UNIQUE (action, role, channel) — puede
     * re-ejecutarse sin duplicar filas.
     */
    public function up(): void
    {
        $now = now();
        $rows = [];

        $addRule = function (string $action, array $roles, string $channel) use (&$rows, $now) {
            foreach ($roles as $role) {
                $rows[] = [
                    'action' => $action,
                    'role' => $role,
                    'channel' => $channel,
                    'enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        };

        // ── Equivalencia exacta con recipientsFor() legacy (canal app) ──
        $addRule('Creacion de peticion de obra', ['CIERRE_DE_OBRA', 'SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Revision tecnica de calculos y planos', ['PROCURA', 'SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Confirmacion de presupuesto y envio a licitacion', ['ANALISTA', 'SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Carga de propuesta', ['PROCURA', 'SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Carga de cuadro comparativo', ['PROCURA', 'SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Importación automática de propuestas de proveedores', ['PROCURA', 'SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Eliminacion de propuesta', ['PROCURA', 'SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Rechazo de cuadro comparativo', ['PROCURA', 'SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Confirmacion de contratacion', ['FINANZAS', 'CIERRE_DE_OBRA', 'INFRAESTRUCTURA', 'PRESIDENCIA', 'SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Liberacion de anticipo', ['CIERRE_DE_OBRA', 'INFRAESTRUCTURA', 'PRESIDENCIA', 'SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Reporte de obra finalizada', ['SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Verificacion de finalizacion y calidad de obra', ['FINANZAS', 'SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Liberacion total de fondos', ['CIERRE_DE_OBRA', 'INFRAESTRUCTURA', 'PRESIDENCIA', 'SUPERADMIN', 'ADMIN'], 'app');

        // Acciones que no cambian el status del proyecto en el momento en
        // que ocurren (documentos, evaluación IA, invitación) no tenían una
        // rama propia en recipientsFor() legacy — heredaban los
        // destinatarios del status vigente en ese punto del flujo. Se
        // siembran con el mismo conjunto que la acción de status más cercana
        // en el flujo (Carga de propuesta / evaluación ocurre junto a la
        // comparativa, con destinatarios de PROCURA).
        $addRule('Carga de hojas de calculo/cubicaciones', ['CIERRE_DE_OBRA', 'SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Carga de planos de ingenieria', ['CIERRE_DE_OBRA', 'SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Eliminacion de documento adjunto', ['CIERRE_DE_OBRA', 'SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Evaluacion inteligente de propuestas', ['PROCURA', 'SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Envio de invitacion a proveedor', ['PROCURA', 'SUPERADMIN', 'ADMIN'], 'app');

        // Accesos públicos — sin destinatarios de rol específico en el
        // legacy (auditaban pero nunca notificaban, ver auditoría previa);
        // se siembran a SUPERADMIN/ADMIN como visibilidad administrativa.
        $addRule('contractor.register', ['SUPERADMIN', 'ADMIN'], 'app');
        $addRule('invitation.view', ['SUPERADMIN', 'ADMIN'], 'app');
        $addRule('proposal.submit', ['SUPERADMIN', 'ADMIN'], 'app');

        // Reset de password: sin destinatarios de rol (es un correo directo
        // al propio usuario, no pasa por recipientsFor()) — sin filas app.

        // ── Canal mail: réplica exacta de DEFAULT_MAIL_ACTIONS ──
        $addRule('Rechazo de cuadro comparativo', ['PROCURA', 'SUPERADMIN', 'ADMIN'], 'mail');
        $addRule('Confirmacion de contratacion', ['FINANZAS', 'CIERRE_DE_OBRA', 'INFRAESTRUCTURA', 'PRESIDENCIA', 'SUPERADMIN', 'ADMIN'], 'mail');
        $addRule('Liberacion de anticipo', ['CIERRE_DE_OBRA', 'INFRAESTRUCTURA', 'PRESIDENCIA', 'SUPERADMIN', 'ADMIN'], 'mail');
        $addRule('Liberacion total de fondos', ['CIERRE_DE_OBRA', 'INFRAESTRUCTURA', 'PRESIDENCIA', 'SUPERADMIN', 'ADMIN'], 'mail');

        // ── Acciones administrativas nuevas de Fase A: destinatarios reales,
        // no huérfanas — solo canal app (el SUPERADMIN activa mail desde la UI si lo desea) ──
        $addRule('Creacion de usuario', ['SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Modificacion de usuario', ['SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Cambio de rol de usuario', ['SUPERADMIN'], 'app');
        $addRule('Activacion/desactivacion de usuario', ['SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Alta de configuracion de IA', ['SUPERADMIN'], 'app');
        $addRule('Modificacion de configuracion de IA', ['SUPERADMIN'], 'app');
        $addRule('Eliminacion de configuracion de IA', ['SUPERADMIN'], 'app');
        $addRule('Alta de proveedor', ['CATALOGOS', 'SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Modificacion de proveedor', ['CATALOGOS', 'SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Activacion/desactivacion de proveedor', ['CATALOGOS', 'SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Calificacion de proveedor', ['CATALOGOS', 'SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Alta de material', ['CATALOGOS', 'SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Modificacion de material', ['CATALOGOS', 'SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Activacion/desactivacion de material', ['CATALOGOS', 'SUPERADMIN', 'ADMIN'], 'app');

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('notification_rules')->upsert(
                $chunk,
                ['action', 'role', 'channel'],
                ['enabled', 'updated_at'],
            );
        }
    }

    public function down(): void
    {
        DB::table('notification_rules')->truncate();
    }
};
