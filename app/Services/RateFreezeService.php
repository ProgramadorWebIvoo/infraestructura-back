<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Project;
use App\Models\ProjectRateFreeze;
use Illuminate\Support\Facades\DB;

/**
 * Única fuente de escritura de ProjectRateFreeze: congela la tasa BCV
 * vigente de la moneda base en el momento de un trigger de negocio
 * (adjudicación, pago de anticipo, pago de finiquito), para que los montos
 * en Bs. ya contratados/pagados dejen de recalcularse con la tasa del día.
 *
 * Snapshot inmutable (write-once): freezeForTrigger() es idempotente (un
 * trigger AUTO nunca duplica un freeze ya vigente para el mismo proyecto);
 * freezeManually() nunca edita una fila existente, crea una nueva que la
 * "supersede" — el historial completo queda disponible para auditoría fiscal.
 */
class RateFreezeService
{
    /**
     * Llamado desde los 3 triggers automáticos de negocio (ProjectController).
     * No hace nada si el trigger está deshabilitado en CONFIG APP, o si ya
     * existe un freeze vigente para este proyecto+trigger.
     */
    public function freezeForTrigger(Project $project, string $trigger, ?float $amountBase): ?ProjectRateFreeze
    {
        if (!$this->isEnabledFor($trigger)) {
            return null;
        }

        return DB::transaction(function () use ($project, $trigger, $amountBase) {
            // Lockea el proyecto para serializar llamadas concurrentes al
            // mismo trigger (ej. doble clic en "Adjudicar contratista") —
            // sin esto, dos transacciones podían leer "no hay freeze
            // todavía" al mismo tiempo y crear dos filas AUTO activas para
            // el mismo proyecto+trigger. Ya corre dentro de la transacción
            // del caller (ProjectController::selectContractor()/pay()), así
            // que esto solo agrega el lock, no anida una transacción real.
            Project::whereKey($project->id)->lockForUpdate()->first();

            $alreadyFrozen = ProjectRateFreeze::where('project_id', $project->id)
                ->where('trigger', $trigger)
                ->active()
                ->exists();
            if ($alreadyFrozen) {
                return null;
            }

            return $this->snapshot($project, $trigger, ProjectRateFreeze::SOURCE_AUTO, null, $amountBase);
        });
    }

    /**
     * Override manual — el caller (controller) es responsable de verificar
     * que el usuario autenticado tiene permiso (SOLO SUPERADMIN).
     */
    public function freezeManually(Project $project, string $trigger, string $reason, ?float $amountBase): ProjectRateFreeze
    {
        return DB::transaction(function () use ($project, $trigger, $reason, $amountBase) {
            // Lockea el proyecto (no solo $previous): si no hay ningún
            // freeze activo todavía, lockForUpdate() sobre una query vacía
            // no bloquea nada, y dos overrides manuales concurrentes podían
            // crear dos filas MANUAL activas para el mismo trigger.
            Project::whereKey($project->id)->lockForUpdate()->first();

            $previous = ProjectRateFreeze::where('project_id', $project->id)
                ->where('trigger', $trigger)
                ->active()
                ->first();

            $new = $this->snapshot($project, $trigger, ProjectRateFreeze::SOURCE_MANUAL, $reason, $amountBase);

            $previous?->update(['superseded_by_id' => $new->id]);

            return $new;
        });
    }

    private function snapshot(Project $project, string $trigger, string $source, ?string $reason, ?float $amountBase): ProjectRateFreeze
    {
        $baseCurrency = Currency::where('is_base', true)->value('code') ?? 'USD';
        $now = now();

        // Una sola consulta para rate + id: separarlas (bcvRateFor() +
        // una query aparte por el id) permitía que un sync de tasas corriera
        // justo en el medio y dejara exchange_rate_id apuntando a una fila
        // distinta de la que realmente originó frozen_rate — rompiendo la
        // trazabilidad fiscal exacta que es la razón de ser de esta tabla.
        // Sin tasa BCV cargada todavía para la moneda base, se registra
        // igual la intención de congelar (queda en auditoría), sin monto
        // en Bs. hasta que haya una tasa disponible.
        $exchangeRate = ExchangeRate::where('currency_code', $baseCurrency)
            ->where('effective_at', '<=', $now)
            ->orderByDesc('effective_at')
            ->first();

        return ProjectRateFreeze::create([
            'project_id' => $project->id,
            'trigger' => $trigger,
            'base_currency' => $baseCurrency,
            'frozen_rate' => $exchangeRate?->rate_to_usd,
            'frozen_amount_base' => $amountBase,
            'exchange_rate_id' => $exchangeRate?->id,
            'source' => $source,
            'reason' => $reason,
            'frozen_at' => $now,
            'frozen_by' => auth()->id(),
        ]);
    }

    private function isEnabledFor(string $trigger): bool
    {
        $key = match ($trigger) {
            ProjectRateFreeze::TRIGGER_CONTRATADO => 'congelar_tasa_en_contratacion',
            ProjectRateFreeze::TRIGGER_PAGO_ANTICIPO => 'congelar_tasa_en_pago_anticipo',
            ProjectRateFreeze::TRIGGER_PAGO_FINIQUITO => 'congelar_tasa_en_pago_finiquito',
            default => null,
        };

        return $key !== null && (bool) SettingsService::get($key, true);
    }
}
