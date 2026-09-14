<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\ConfigAuditLog;
use App\Services\SettingsService;
use App\Support\AppSettingCatalog;
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
     *
     * `missing` expone las keys documentadas en AppSettingCatalog que no
     * tienen fila en app_settings (una migración de seed que no corrió, un
     * rollback parcial, una fila borrada a mano) — sin esto, un setting
     * ausente simplemente no aparece en el panel, sin ningún rastro de que
     * debería existir. Solo lo consume la UI de SUPERADMIN (ver
     * ConfigAppPanel), pero se calcula para cualquier lector: es información
     * de estado, no un dato sensible.
     *
     * `missing` va como clave hermana a los grupos (no anidada bajo una
     * sub-clave "groups") para no romper a los consumidores existentes que
     * leen `data.presupuesto`, `data.notificaciones`, etc. directamente —
     * apiFetch ya desenvuelve `json.data` una sola vez. Esto asume que
     * ningún `group` real se llama "missing"; AppSettingCatalogTest lo
     * verifica explícitamente para que un futuro seed con ese nombre de
     * grupo falle en CI en vez de corromper esta respuesta en silencio.
     */
    public function index(): JsonResponse
    {
        $settings = AppSetting::orderBy('group')->orderBy('key')->get();

        return response()->json(['data' => [
            ...$settings->groupBy('group')->all(),
            'missing' => AppSettingCatalog::missingFrom($settings->pluck('key')->all()),
        ]]);
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

        $this->assertSemaphoreOrder($setting, $data['value']);
        $this->assertCronHourFormat($setting, $data['value']);

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
        $settingPayload['auditLog'] = $auditLog->toApiPayload();

        return response()->json(['data' => $settingPayload]);
    }

    /**
     * Los tres umbrales del semáforo presupuestario (verde/amarillo/naranja)
     * se guardan como settings independientes, cada uno con su propio
     * min/max 0-100 — nada impide guardar amarillo < verde, lo que rompe la
     * clasificación en useBudgetSemaphore (levelOf evalúa verde primero, así
     * que un amarillo mal ubicado queda enmascarado). Se valida el trío
     * completo contra los valores ya persistidos de los otros dos.
     */
    private function assertSemaphoreOrder(AppSetting $setting, ?string $newValue): void
    {
        $semaphoreKeys = ['semaforo_umbral_verde', 'semaforo_umbral_amarillo', 'semaforo_umbral_naranja'];

        if (!in_array($setting->key, $semaphoreKeys, true) || $newValue === null) {
            return;
        }

        $current = AppSetting::whereIn('key', $semaphoreKeys)->pluck('value', 'key');
        $current[$setting->key] = $newValue;

        $verde = (float) $current['semaforo_umbral_verde'];
        $amarillo = (float) $current['semaforo_umbral_amarillo'];
        $naranja = (float) $current['semaforo_umbral_naranja'];

        abort_unless(
            $verde < $amarillo && $amarillo < $naranja,
            422,
            'Los umbrales del semáforo deben ser crecientes: verde < amarillo < naranja.'
        );
    }

    /**
     * `tasa_cambio_cron_hora` alimenta directamente `Schedule::dailyAt()`
     * (ver routes/console.php) — un formato inválido ahí rompe el scheduler
     * en silencio, no falla con un error visible. Se valida acá para que el
     * error aparezca en el panel, antes de llegar a BD.
     */
    private function assertCronHourFormat(AppSetting $setting, ?string $newValue): void
    {
        if ($setting->key !== 'tasa_cambio_cron_hora' || $newValue === null) {
            return;
        }

        abort_unless(
            preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $newValue) === 1,
            422,
            'La hora debe tener el formato HH:MM (24 horas), por ejemplo 10:00.'
        );
    }
}
