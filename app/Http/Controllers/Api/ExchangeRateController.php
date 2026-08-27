<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConfigAuditLog;
use App\Models\Currency;
use App\Models\ExchangeRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Histórico de tasas de cambio a USD — exclusivo SUPERADMIN, mismo nivel que
 * /currencies. Distinto de `currencies` (catálogo estático de monedas
 * habilitadas): cada fila acá es un snapshot inmutable en el tiempo, nunca
 * se actualiza ni se borra — es lo que consumen las líneas de propuesta de
 * proveedor para fijar `fx_rate_to_usd` en el momento de la cotización.
 */
class ExchangeRateController extends Controller
{
    /** Últimas tasas por moneda (no el histórico completo) — vista de resumen para el panel. */
    public function index(): JsonResponse
    {
        $latestIds = ExchangeRate::selectRaw('MAX(id) as id')
            ->groupBy('currency_code');

        $rates = ExchangeRate::whereIn('id', $latestIds)
            ->orderBy('currency_code')
            ->get();

        return response()->json(['data' => $rates]);
    }

    public function history(string $currencyCode): JsonResponse
    {
        $rates = ExchangeRate::where('currency_code', strtoupper($currencyCode))
            ->orderByDesc('effective_at')
            ->limit(200)
            ->get();

        return response()->json(['data' => $rates]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['currency_code' => strtoupper((string) $request->input('currency_code'))]);

        $data = $request->validate([
            'currency_code' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/', 'exists:currencies,code'],
            'rate_to_usd' => ['required', 'numeric', 'gt:0'],
            'source' => ['required', 'string', 'max:50'],
            'effective_at' => ['sometimes', 'date'],
        ]);

        if ($data['currency_code'] === 'USD') {
            abort(422, 'USD es la moneda de referencia; su tasa es siempre 1.0 y no requiere carga manual.');
        }

        $rate = ExchangeRate::create([
            ...$data,
            'effective_at' => $data['effective_at'] ?? now(),
        ]);

        $currency = Currency::where('code', $rate->currency_code)->first();
        $auditLog = ConfigAuditLog::recordAdminAction(
            'exchange_rate',
            'Carga de tasa de cambio',
            null,
            (string) $rate->rate_to_usd,
            "Tasa {$rate->currency_code} → USD: {$rate->rate_to_usd} (fuente: {$rate->source})."
        );

        return response()->json(['data' => [...$rate->toArray(), 'currencyName' => $currency?->name, 'auditLog' => $auditLog->toApiPayload()]], 201);
    }
}
