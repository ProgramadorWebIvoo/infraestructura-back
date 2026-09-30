<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\PaymentOrder;
use Illuminate\Validation\ValidationException;

/**
 * Liquidación de un pago: convierte lo que Finanzas declara haber pagado
 * (moneda, monto y tasa real) en el registro auditable contra la obligación
 * de la orden de pago. No decide nada por Finanzas: la tasa aplicada la
 * digita quien paga (la real de la operación); el sistema guarda además la
 * tasa que ÉL habría sugerido, calcula el equivalente cubierto en la moneda
 * de la obligación y la diferencia, y exige motivo cuando esa diferencia
 * supera la tolerancia.
 *
 * Modos: QUOTE_CURRENCY (se pagó en la moneda de cotización), BS
 * (conversión a bolívares) y OTHER_CURRENCY (conversión a otra moneda).
 */
class PaymentSettlementService
{
    public const MODE_QUOTE_CURRENCY = 'QUOTE_CURRENCY';
    public const MODE_BS = 'BS';
    public const MODE_OTHER_CURRENCY = 'OTHER_CURRENCY';

    public const MODES = [self::MODE_QUOTE_CURRENCY, self::MODE_BS, self::MODE_OTHER_CURRENCY];

    /** Código con el que se registra el bolívar (no es una fila de `currencies`: no se cotiza en Bs.). */
    public const BS_CURRENCY = 'VES';

    public const RATE_SOURCES = ['BCV', 'USDT', 'MANUAL'];

    /** Diferencia máxima (en la moneda de la obligación) que se acepta sin motivo. */
    public const DIFFERENCE_TOLERANCE = 0.01;

    /**
     * @param array{paymentMode: string, paidAmount: float|int|string, paidCurrency?: ?string, appliedRate?: float|int|string|null, appliedRateSource?: ?string, differenceReason?: ?string} $data
     * @return array<string, mixed> columnas de `project_payments` (sin las referencias a congelados)
     * @throws ValidationException
     */
    public function build(PaymentOrder $order, array $data): array
    {
        $mode = $data['paymentMode'];
        $obligationCurrency = strtoupper($order->currency);
        $obligationAmount = (float) $order->amount;
        $paidAmount = (float) $data['paidAmount'];

        [$paidCurrency, $appliedRate, $rateSource] = $this->resolveCurrencyAndRate($mode, $obligationCurrency, $data);

        $coveredAmount = round($paidAmount / $appliedRate, 2);
        $difference = round($coveredAmount - $obligationAmount, 2);
        $reason = trim((string) ($data['differenceReason'] ?? ''));

        if (abs($difference) > self::DIFFERENCE_TOLERANCE && mb_strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'differenceReason' => 'El pago difiere de la orden (' . number_format($difference, 2, '.', '') . " {$obligationCurrency}): indica el motivo de la diferencia.",
            ]);
        }

        return [
            'obligation_amount' => $obligationAmount,
            'obligation_currency' => $obligationCurrency,
            'payment_mode' => $mode,
            'paid_currency' => $paidCurrency,
            'paid_amount' => $paidAmount,
            'applied_rate' => $appliedRate,
            'applied_rate_source' => $rateSource,
            'suggested_rate' => $this->suggestedRate($obligationCurrency, $paidCurrency, $rateSource),
            'covered_amount' => $coveredAmount,
            'difference_amount' => $difference,
            'difference_reason' => abs($difference) > self::DIFFERENCE_TOLERANCE ? $reason : null,
        ];
    }

    /**
     * Texto legible del pago para el registro de auditoría (AuditLog).
     *
     * @param array<string, mixed> $settlement resultado de build()
     */
    public function describe(array $settlement): string
    {
        $text = sprintf(
            'Pagado %s %s (modo %s)',
            number_format((float) $settlement['paid_amount'], 2, '.', ''),
            $settlement['paid_currency'],
            $settlement['payment_mode']
        );

        if ($settlement['paid_currency'] !== $settlement['obligation_currency']) {
            $text .= sprintf(
                ', tasa aplicada %s (%s)%s',
                rtrim(rtrim(number_format((float) $settlement['applied_rate'], 8, '.', ''), '0'), '.'),
                $settlement['applied_rate_source'],
                $settlement['suggested_rate'] !== null
                    ? ', sugerida ' . rtrim(rtrim(number_format((float) $settlement['suggested_rate'], 8, '.', ''), '0'), '.')
                    : ''
            );
        }

        $text .= sprintf(
            '. Obligación %s %s; cubierto %s %s; diferencia %s',
            number_format((float) $settlement['obligation_amount'], 2, '.', ''),
            $settlement['obligation_currency'],
            number_format((float) $settlement['covered_amount'], 2, '.', ''),
            $settlement['obligation_currency'],
            number_format((float) $settlement['difference_amount'], 2, '.', '')
        );

        if ($settlement['difference_reason']) {
            $text .= ". Motivo de la diferencia: {$settlement['difference_reason']}";
        }

        return $text . '.';
    }

    /** @return array{0: string, 1: float, 2: ?string} [moneda pagada, tasa aplicada, fuente de la tasa] */
    private function resolveCurrencyAndRate(string $mode, string $obligationCurrency, array $data): array
    {
        if ($mode === self::MODE_QUOTE_CURRENCY) {
            // Se pagó en la moneda pactada: no hay conversión.
            return [$obligationCurrency, 1.0, null];
        }

        $rate = (float) ($data['appliedRate'] ?? 0);
        $source = strtoupper((string) ($data['appliedRateSource'] ?? ''));

        if ($rate <= 0) {
            throw ValidationException::withMessages(['appliedRate' => 'Indica la tasa con la que se convirtió el pago.']);
        }
        if (!in_array($source, self::RATE_SOURCES, true)) {
            throw ValidationException::withMessages(['appliedRateSource' => 'Indica el origen de la tasa (BCV, USDT o manual).']);
        }

        if ($mode === self::MODE_BS) {
            return [self::BS_CURRENCY, $rate, $source];
        }

        $paidCurrency = strtoupper((string) ($data['paidCurrency'] ?? ''));
        if ($paidCurrency === '' || $paidCurrency === $obligationCurrency || $paidCurrency === self::BS_CURRENCY) {
            throw ValidationException::withMessages([
                'paidCurrency' => 'Indica una moneda de pago distinta de la moneda de la obligación y de los bolívares.',
            ]);
        }
        if (!Currency::where('code', $paidCurrency)->exists()) {
            throw ValidationException::withMessages(['paidCurrency' => 'La moneda de pago no existe en el catálogo.']);
        }

        return [$paidCurrency, $rate, $source];
    }

    /**
     * Tasa que el sistema habría propuesto (unidades de moneda pagada por 1 de
     * la obligación), o null si no hay tasas para calcularla. En bolívares usa
     * la tasa de la moneda de la obligación (BCV para USD/EUR, la propia para
     * USDT); si Finanzas indica origen USDT y la obligación es en USD, la del
     * USDT. Entre dos monedas, el cruce por el bolívar.
     */
    private function suggestedRate(string $obligationCurrency, string $paidCurrency, ?string $source): ?float
    {
        if ($paidCurrency === $obligationCurrency) {
            return 1.0;
        }

        try {
            if ($paidCurrency === self::BS_CURRENCY) {
                $basis = ($source === 'USDT' && $obligationCurrency === 'USD') ? 'USDT' : $obligationCurrency;

                return ExchangeRate::bcvRateFor($basis, now());
            }

            return ExchangeRate::rateBetween($obligationCurrency, $paidCurrency, now());
        } catch (\RuntimeException) {
            return null;
        }
    }
}
