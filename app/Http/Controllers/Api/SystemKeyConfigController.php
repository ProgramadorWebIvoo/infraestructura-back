<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConfigAuditLog;
use App\Services\SystemKeyConfigService;
use Illuminate\Http\Request;

/**
 * Configuración de Keys — SMTP y Pusher editables desde el panel de
 * administración sin tocar .env. Solo SUPERADMIN (ver routes/api.php y
 * config-keys en view_definitions): expone credenciales de infraestructura,
 * más sensible que el resto de CONFIG APP.
 */
class SystemKeyConfigController extends Controller
{
    private const GROUPS = ['smtp', 'pusher'];

    public function __construct(private SystemKeyConfigService $service)
    {
    }

    /** GET /api/system-keys — payload masked de los dos grupos. */
    public function index()
    {
        $payload = [];
        foreach (self::GROUPS as $group) {
            $payload[] = $this->service->toApiPayload($group);
        }

        return response()->json($payload);
    }

    /** PATCH /api/system-keys/{group} */
    public function update(Request $request, string $group)
    {
        if (!in_array($group, self::GROUPS, true)) {
            return response()->json(['message' => 'Grupo de configuración inválido.'], 404);
        }

        $schema = SystemKeyConfigService::SCHEMAS[$group];
        $secretFields = SystemKeyConfigService::SECRET_FIELDS[$group];

        $rules = ['isActive' => 'sometimes|boolean'];
        foreach ($schema as $field) {
            $rules[$field] = 'sometimes|nullable|string|max:255';
        }
        $validated = $request->validate($rules);

        $data = [];
        foreach ($schema as $field) {
            if (!array_key_exists($field, $validated)) continue;
            // Campo secreto vacío = "conservar el actual" (mismo criterio que
            // AiConfigController::update con apiKey) — nunca se borra un
            // secreto guardado enviando el input vacío por accidente.
            if (in_array($field, $secretFields, true) && $validated[$field] === '') continue;
            $data[$field] = $validated[$field];
        }

        $isActive = $validated['isActive'] ?? $this->service->getRaw($group)?->is_active ?? false;

        $this->service->upsert($group, $data, $isActive);

        $auditLog = ConfigAuditLog::recordAdminAction(
            'system_key_config',
            "Modificacion de configuracion de {$group}",
            null,
            null,
            "Grupo: {$group}",
        );

        return response()->json([
            ...$this->service->toApiPayload($group),
            'auditLog' => $auditLog->toApiPayload(),
        ]);
    }

    /** POST /api/system-keys/{group}/test */
    public function test(Request $request, string $group)
    {
        if (!in_array($group, self::GROUPS, true)) {
            return response()->json(['success' => false, 'message' => 'Grupo de configuración inválido.'], 404);
        }

        if ($group === 'smtp') {
            $validated = $request->validate(['toEmail' => 'required|email']);
            $result = $this->service->testSmtp($validated['toEmail']);
        } else {
            $result = $this->service->testPusher();
        }

        return response()->json($result, $result['success'] ? 200 : 400);
    }
}
