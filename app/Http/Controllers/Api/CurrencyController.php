<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConfigAuditLog;
use App\Models\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Catálogo de monedas aceptadas — exclusivo SUPERADMIN, mismo nivel de
 * restricción que /notification-rules. `moneda_base` ya no es un string
 * libre en app_settings: es la fila con is_base=true de esta tabla.
 */
class CurrencyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Currency::orderByDesc('is_base')->orderBy('code')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['code' => strtoupper((string) $request->input('code'))]);

        $data = $request->validate([
            // 'alpha' acepta cualquier letra Unicode (acentos, cirílico,
            // etc.), no solo A-Z — regex explícita ASCII para que
            // strtoupper() (byte-based, no normaliza multibyte) sea una
            // transformación segura y el código quede consistente con el
            // estándar ISO 4217 real.
            'code' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/', 'unique:currencies,code'],
            'name' => ['required', 'string', 'max:80'],
            'symbol' => ['required', 'string', 'max:8'],
        ]);

        $currency = Currency::create([
            ...$data,
            'is_base' => false,
            'is_active' => true,
        ]);

        $auditLog = ConfigAuditLog::recordAdminAction('currency', 'Alta de moneda', null, null, "Moneda \"{$currency->name}\" ({$currency->code}) agregada.");

        return response()->json(['data' => [...$currency->toArray(), 'auditLog' => $auditLog->toApiPayload()]], 201);
    }

    public function update(Request $request, Currency $currency): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'symbol' => ['sometimes', 'string', 'max:8'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (($data['is_active'] ?? true) === false && $currency->is_base) {
            abort(422, 'No se puede desactivar la moneda base.');
        }

        $before = ['code' => $currency->code, ...$currency->only(['name', 'symbol', 'is_active'])];
        $currency->update($data);
        $after = ['code' => $currency->code, ...$currency->only(['name', 'symbol', 'is_active'])];

        $auditLog = ConfigAuditLog::recordAdminAction('currency', 'Modificación de moneda', json_encode($before), json_encode($after));

        return response()->json(['data' => [...$currency->toArray(), 'auditLog' => $auditLog->toApiPayload()]]);
    }

    public function setBase(Currency $currency): JsonResponse
    {
        abort_unless($currency->is_active, 422, 'No se puede establecer como base una moneda inactiva.');

        // $previousBase se lee DENTRO de la transacción con lockForUpdate():
        // leerla afuera permitía que dos POST /set-base concurrentes (para
        // monedas distintas) leyeran el mismo "anterior" antes de que
        // cualquiera confirmara, dejando la auditoría con una transición
        // incorrecta (ej. "USD → GBP" cuando en realidad pasó por EUR). El
        // índice único de esquema (uq_currencies_single_base) sigue siendo
        // la garantía de integridad; el lock es solo para que la lectura
        // del audit log refleje el estado real en el momento del cambio.
        $previousCode = DB::transaction(function () use ($currency) {
            $previousBase = Currency::where('is_base', true)->lockForUpdate()->first();
            Currency::where('is_base', true)->update(['is_base' => false]);
            $currency->update(['is_base' => true]);
            return $previousBase?->code;
        });

        $auditLog = ConfigAuditLog::recordAdminAction('currency', 'Cambio de moneda base', $previousCode, null, "Moneda base cambiada a \"{$currency->code}\".");

        return response()->json(['data' => [...$currency->fresh()->toArray(), 'auditLog' => $auditLog->toApiPayload()]]);
    }

    public function destroy(Currency $currency): JsonResponse
    {
        abort_if($currency->is_base, 422, 'No se puede eliminar la moneda base.');

        $currency->delete();

        ConfigAuditLog::recordAdminAction('currency', 'Eliminación de moneda', $currency->code, null, "Moneda \"{$currency->code}\" eliminada.");

        return response()->json(['data' => null], 204);
    }
}
