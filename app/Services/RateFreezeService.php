<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\PaymentOrder;
use App\Models\Project;
use App\Models\ProjectRateFreeze;
use Illuminate\Support\Facades\DB;

/**
 * Única fuente de escritura de ProjectRateFreeze: congela los BOLÍVARES de un
 * monto en el momento de un trigger de negocio (CONTRATADO = Procura solicita el
 * anticipo a Finanzas tras la aprobación de Presidencia; pago de anticipo; pago
 * de finiquito), para que ese monto en Bs. deje de
 * recalcularse con la tasa del día.
 *
 * Se congela en la moneda del propio monto (la de cotización: USD, EUR,
 * USDT...): moneda + monto + tasa de ESA moneda (la BCV para USD/EUR, la
 * propia para USDT) + Bs. resultantes. Nunca depende del switch BCV/USDT.
 *
 * La configuración es UNA sola opción (`congelar_tasa_momento`): el momento
 * del flujo en que se congela. Siempre se congela en uno; los demás triggers no.
 *
 * Snapshot inmutable (write-once): freezeForTrigger() es idempotente (un
 * trigger AUTO nunca duplica un freeze ya vigente para el mismo proyecto);
 * freezeManually() nunca edita una fila existente, crea una nueva que la
 * "supersede" — el historial completo queda disponible para auditoría fiscal.
 */
class RateFreezeService
{
    public const MOMENT_SETTING = 'congelar_tasa_momento';

    /** Momento por defecto si el setting no existe todavía: el primero del flujo. */
    public const DEFAULT_MOMENT = ProjectRateFreeze::TRIGGER_CONTRATADO;

    /** Valores válidos del setting: los tres triggers. */
    public static function moments(): array
    {
        return ProjectRateFreeze::TRIGGERS;
    }

    /**
     * Llamado desde los triggers automáticos de negocio (adjudicación y
     * pagos). No hace nada si el momento configurado no es este trigger, o si
     * ya existe un freeze vigente para este proyecto+trigger.
     *
     * Monto y moneda se toman del contexto del proyecto (propuesta adjudicada
     * u orden de pago vigente); `$amountBase` es el respaldo cuando no hay
     * contexto (se congela como monto en la moneda base).
     */
    public function freezeForTrigger(Project $project, string $trigger, ?float $amountBase = null): ?ProjectRateFreeze
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

            $existing = ProjectRateFreeze::where('project_id', $project->id)
                ->where('trigger', $trigger)
                ->active()
                ->first();
            if ($existing && $existing->frozen_rate !== null) {
                return null;
            }

            $new = $this->snapshot($project, $trigger, ProjectRateFreeze::SOURCE_AUTO, null, $amountBase);

            // Había un congelado "vacío" (sin tasa al momento): el nuevo lo reemplaza
            // (supersede) solo si ya pudo fijar los Bs.; si sigue sin tasa, no se acumulan filas vacías.
            if ($existing) {
                if ($new->frozen_rate === null) {
                    $new->delete();

                    return null;
                }
                $existing->update(['superseded_by_id' => $new->id]);
            }

            return $new;
        });
    }

    /**
     * Override manual — el caller (controller) es responsable de verificar
     * que el usuario autenticado tiene permiso (SOLO SUPERADMIN). Ignora el
     * momento configurado: es una corrección explícita y con motivo.
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

        [$amount, $currency, $amountBase] = $this->resolveAmount($project, $trigger, $baseCurrency, $amountBase);

        // Una sola consulta para rate + id: separarlas (bcvRateFor() +
        // una query aparte por el id) permitía que un sync de tasas corriera
        // justo en el medio y dejara exchange_rate_id apuntando a una fila
        // distinta de la que realmente originó frozen_rate — rompiendo la
        // trazabilidad fiscal exacta que es la razón de ser de esta tabla.
        // Sin tasa cargada todavía para la moneda, se registra igual la
        // intención de congelar (queda en auditoría), sin Bs. hasta que haya
        // una tasa disponible.
        $exchangeRate = ExchangeRate::where('currency_code', $currency)
            ->where('effective_at', '<=', $now)
            ->orderByDesc('effective_at')
            ->first();
        $rate = $exchangeRate?->rate_to_usd;

        return ProjectRateFreeze::create([
            'project_id' => $project->id,
            'trigger' => $trigger,
            'base_currency' => $baseCurrency,
            'frozen_currency' => $currency,
            'frozen_rate' => $rate,
            'frozen_amount_base' => $amountBase,
            'frozen_amount' => $amount,
            'frozen_amount_bs' => ($rate !== null && $amount !== null) ? round($amount * (float) $rate, 2) : null,
            'exchange_rate_id' => $exchangeRate?->id,
            'source' => $source,
            'reason' => $reason,
            'frozen_at' => $now,
            'frozen_by' => auth()->id(),
        ]);
    }

    /**
     * Monto a congelar, en su moneda: el de la propuesta adjudicada (CONTRATADO)
     * o el de la orden de pago vigente (anticipo/finiquito), expresado en la
     * moneda de cotización. Sin contexto (o sin monto), cae al `$amountBase`
     * recibido, congelado como monto en la moneda base.
     *
     * @return array{0: ?float, 1: string, 2: ?float} [monto, moneda, equivalente en base]
     */
    private function resolveAmount(Project $project, string $trigger, string $baseCurrency, ?float $amountBase): array
    {
        if ($trigger === ProjectRateFreeze::TRIGGER_CONTRATADO) {
            $proposal = $project->selected_proposal_id
                ? $project->proposals()->whereKey($project->selected_proposal_id)->first()
                : null;

            if ($proposal) {
                $converted = $proposal->total_cost_original !== null && $proposal->quote_currency !== null;

                return [
                    (float) ($converted ? $proposal->total_cost_original : $proposal->total_cost),
                    strtoupper($converted ? $proposal->quote_currency : $baseCurrency),
                    (float) $proposal->total_cost,
                ];
            }
        } else {
            $type = $trigger === ProjectRateFreeze::TRIGGER_PAGO_ANTICIPO ? PaymentOrder::TYPE_ADVANCE : PaymentOrder::TYPE_FINAL;
            $order = PaymentOrder::where('project_id', $project->id)
                ->where('current_key', PaymentOrder::currentKeyFor($project->id, $type))
                ->first();

            if ($order) {
                return [(float) $order->amount, strtoupper($order->currency), (float) $order->amount_base];
            }
        }

        return [$amountBase, $baseCurrency, $amountBase];
    }

    private function isEnabledFor(string $trigger): bool
    {
        return SettingsService::get(self::MOMENT_SETTING, self::DEFAULT_MOMENT) === $trigger;
    }
}
