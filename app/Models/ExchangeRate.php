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
     * Tasa vigente más reciente para una moneda a una fecha dada. USD
     * siempre es 1.0 explícito (no un caso especial) para mantener uniforme
     * la fórmula price_usd = original_price * fx_rate_to_usd en el resto
     * del sistema.
     */
    public static function rateFor(string $currencyCode, \DateTimeInterface $at): float
    {
        if ($currencyCode === 'USD') {
            return 1.0;
        }

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
}
