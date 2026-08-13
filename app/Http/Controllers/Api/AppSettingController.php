<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\ConfigAuditLog;
use App\Services\SettingsService;
use App\Support\NotificationCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppSettingController extends Controller
{
    /**
     * Lista todos los settings agrupados por `group`, con el valor crudo
     * (string) tal como vive en BD — el panel de administración necesita el
     * string editable, no el valor casteado que expone SettingsService (ese
     * es para consumo interno del backend).
     */
    public function index(): JsonResponse
    {
        $settings = AppSetting::orderBy('group')->orderBy('key')->get();

        return response()->json(['data' => $settings->groupBy('group')]);
    }

    /**
     * Catálogo real de acciones auditadas disponibles para los selectores de
     * `acciones_con_correo` / `acciones_con_notificacion_app` en CONFIG
     * APP — misma fuente que usa NotificationCatalog/NotificationDispatcher
     * al filtrar, así el frontend nunca ofrece una acción que la app no
     * dispara de verdad.
     *
     * `value` es el string técnico que realmente se guarda en el setting
     * (debe coincidir exactamente con el `$action` que se audita); `label`
     * es el texto legible a mostrar.
     */
    public function notificationActions(): JsonResponse
    {
        return response()->json(['data' => NotificationCatalog::toOptions()]);
    }

    public function update(Request $request, AppSetting $setting): JsonResponse
    {
        $data = $request->validate([
            'value' => ['nullable', 'string', 'max:5000'],
        ]);

        if ($setting->type === 'boolean' && $data['value'] !== null) {
            abort_unless(in_array($data['value'], ['true', 'false'], true), 422, 'Valor booleano inválido.');
        }

        if ($setting->type === 'integer' && $data['value'] !== null) {
            abort_unless(is_numeric($data['value']) && (int) $data['value'] == $data['value'], 422, 'Valor entero inválido.');
        }

        if ($setting->type === 'float' && $data['value'] !== null) {
            abort_unless(is_numeric($data['value']), 422, 'Valor numérico inválido.');
        }

        if (in_array($setting->type, ['integer', 'float'], true) && $data['value'] !== null) {
            if ($setting->min_value !== null) {
                abort_unless((float) $data['value'] >= (float) $setting->min_value, 422, "El valor no puede ser menor a {$setting->min_value}.");
            }
            if ($setting->max_value !== null) {
                abort_unless((float) $data['value'] <= (float) $setting->max_value, 422, "El valor no puede ser mayor a {$setting->max_value}.");
            }
        }

        if ($setting->type === 'json' && $data['value'] !== null) {
            json_decode($data['value']);
            abort_unless(json_last_error() === JSON_ERROR_NONE, 422, 'JSON inválido.');
        }

        $oldValue = $setting->value;
        $setting->update(['value' => $data['value']]);
        SettingsService::forget();

        $auditLog = ConfigAuditLog::recordSettingChange($setting, $oldValue, $data['value']);

        // `auditLog` va anidado dentro de `data` (no como hermano) porque
        // apiFetch (frontend) desenvuelve automáticamente `json.data` —
        // cualquier clave hermana a `data` se perdería. El frontend inserta
        // esta entrada directamente en el panel de auditoría sin re-consultar
        // /config-audit-logs — evita un polling recurrente para un dato que
        // solo cambia por acción del propio usuario en la misma vista.
        $settingPayload = $setting->toArray();
        $settingPayload['auditLog'] = [
            'id' => $auditLog->id,
            'settingKey' => $auditLog->setting_key,
            'oldValue' => $auditLog->old_value,
            'newValue' => $auditLog->new_value,
            'userName' => $auditLog->user_name_snapshot,
            'changedAt' => $auditLog->changed_at->format('Y-m-d H:i'),
        ];

        return response()->json(['data' => $settingPayload]);
    }
}
