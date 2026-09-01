<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'currency_code',
        'rate_to_usd',
        'source',
        'effective_at',
    ];

    protected $casts = [
        'rate_to_usd' => 'float',
        'effective_at' => 'datetime',
    ];

    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    /**
     * Tasa BCV cruda (bolívares por unidad de moneda) vigente más reciente a
     * una fecha dada. `rate_to_usd` es el nombre histórico de la columna,
     * pero el dato real que guarda (ver DolarVzlaApiFetcher/BcvScraperFetcher)
     * es la tasa BCV a bolívares — igual para USD que para EUR u otra moneda
     * que se agregue. No confundir con "tasa a dólares": para eso ver
     * `rateBetween()`.
     */
    public static function bcvRateFor(string $currencyCode, \DateTimeInterface $at): float
    {
        $rate = static::query()
            ->where('currency_code', $currencyCode)
            ->where('effective_at', '<=', $at)
            ->orderByDesc('effective_at')
            ->first();

        if (!$rate) {
            throw new \RuntimeException("No hay tasa de cambio registrada para {$currencyCode} antes de {$at->format('Y-m-d H:i:s')}");
        }

        return (float) $rate->rate_to_usd;
    }

    /**
     * Tasa de conversión entre dos monedas usando bolívares como pivote.
     *
     * Fórmula: bcvRateFor($from) / bcvRateFor($to)
     *
     * Ejemplo: EUR → BRL
     *   = bcvRateFor('EUR', $at) / bcvRateFor('BRL', $at)
     *   = 862.15 Bs./EUR / 210.43 Bs./BRL
     *   = 4.1 BRL/EUR (1 EUR = 4.1 BRL)
     *
     * Nota: Usa bolívares como pivote neutral para evitar inconsistencias
     * si la "moneda base" del sistema cambia en el futuro. La tasa resultante
     * es independiente de USD o cualquier otra moneda elegida como "base".
     *
     * @param string $from Código de moneda origen (EUR, BRL, USD)
     * @param string $to Código de moneda destino
     * @param DateTimeInterface $at Fecha de cotización (obtiene tasa vigente a esa fecha)
     * @return float Tasa cruzada (multiplicador: monto_from × tasa = monto_to)
     */
    public static function rateBetween(string $from, string $to, \DateTimeInterface $at): float
    {
        if ($from === $to) {
            return 1.0;
        }

        return static::bcvRateFor($from, $at) / static::bcvRateFor($to, $at);
    }
}
