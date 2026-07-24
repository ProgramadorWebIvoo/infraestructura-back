# Pruebas de Funcionamiento — Auditorías Tipo A (Severidad Crítica y Alta)

**Proyecto:** Infraestructura Backend (Laravel 9.x)  
**Fecha:** 24 Julio 2026  
**Propósito:** Validar la correcta implementación de los 7 fixes de severidad crítica y alta del reporte de auditoría.

---

## Índice

| ID | Fix | Tipo |
|:--:|-----|:----:|
| C-01 | `estimateCost()` con keys incorrectas | 🔴 Crítico |
| A-01 | Queue para notificaciones push | 🟠 Alto |
| A-01b | Filtro de notificaciones por rol en ProjectObserver | 🟠 Alto |
| A-02 | ExpoPushService procesa respuesta y limpia tokens zombies | 🟠 Alto |
| A-03 | Validación SSRF en baseUrl de IA | 🟠 Alto |
| A-04 | API keys no se cachean en texto plano | 🟠 Alto |
| A-05 | Providers IA reciben config por constructor (sin mutar estado global) | 🟠 Alto |

---

## Requisitos previos

- Entorno de desarrollo con `php artisan serve` corriendo
- Base de datos MySQL con migrations aplicadas
- Queue configurada (`QUEUE_CONNECTION=database`)
- Tabla `jobs` creada (`php artisan migrate`)
- Tener instalado **Git Bash** o terminal bash para ejecutar los tests automatizados
- Para pruebas manuales: **Postman**, **curl**, o el frontend corriendo

---

## 🔴 C-01: estimateCost() con keys incorrectas

### Problema original

El array `$pricing` en `estimateCost()` usaba las keys `chatgpt`/`claude` pero el parámetro `$provider` recibía `openai`/`anthropic`. Nunca había match → costos siempre $0.

### Prueba 1 — Verificar que el fix está aplicado

**Archivo:** `app/Services/AI/AIEvaluationService.php` (línea ~228)

```php
$pricing = [
    'openai'    => ['input' => 2.50, 'output' => 10.00],
    'gemini'    => ['input' => 0.35, 'output' => 1.05],
    'anthropic' => ['input' => 3.00, 'output' => 15.00],
];
```

✅ **Criterio de aceptación:** Las keys deben ser `openai`, `gemini`, `anthropic` — **no** `chatgpt`, `claude`.

### Prueba 2 — Verificar cálculo con datos reales (opcional, requiere AI configurado)

Ejecutar una evaluación vía `POST /api/ai/evaluate-proposals` con un proyecto que tenga propuestas.

Verificar en la tabla `ai_usage_logs` que `cost_estimate` ya no sea `0`:

```sql
SELECT id, provider, model, prompt_tokens, completion_tokens, cost_estimate 
FROM ai_usage_logs 
ORDER BY id DESC 
LIMIT 5;
```

✅ **Criterio de aceptación:** `cost_estimate` debe ser > 0 cuando hay tokens consumidos.

### Prueba 3 — Test unitario del método estimateCost (mock)

```php
// Usar tinker para verificar el cálculo
php artisan tinker
```

```php
$service = app(\App\Services\AI\AIEvaluationService::class);
$reflection = new ReflectionMethod($service, 'estimateCost');
$reflection->setAccessible(true);

// OpenAI: 1000 prompt + 500 completion tokens
$cost = $reflection->invoke($service, 'openai', [
    'prompt_tokens' => 1000,
    'completion_tokens' => 500,
]);
// expected: (1000/1_000_000)*2.50 + (500/1_000_000)*10.00 = 0.0025 + 0.005 = 0.0075
echo "Costo OpenAI: $cost\n";

// Anthropic: 1000 prompt + 500 completion
$cost = $reflection->invoke($service, 'anthropic', [
    'prompt_tokens' => 1000,
    'completion_tokens' => 500,
]);
// expected: (1000/1_000_000)*3.00 + (500/1_000_000)*15.00 = 0.003 + 0.0075 = 0.0105
echo "Costo Anthropic: $cost\n";

// Gemini: fallback test
$cost = $reflection->invoke($service, 'gemini', [
    'prompt_tokens' => 2000,
    'completion_tokens' => 1000,
]);
echo "Costo Gemini: $cost\n";
```

✅ **Criterio de aceptación:** Todos los costos deben dar valores > 0 y correctos según la fórmula.

---

## 🟠 A-01: Queue para notificaciones push

### Problema original

`QUEUE_CONNECTION=sync` → las notificaciones se enviaban sincrónicamente durante el request HTTP, bloqueando la respuesta.

### Prueba 1 — Verificar configuración de cola

```bash
grep QUEUE_CONNECTION .env
```

✅ **Criterio de aceptación:** `QUEUE_CONNECTION=database`

### Prueba 2 — Verificar que la tabla `jobs` existe

```sql
DESCRIBE jobs;
```

✅ **Criterio de aceptación:** La tabla debe existir con las columnas: `id`, `queue`, `payload`, `attempts`, `reserved_at`, `available_at`, `created_at`.

### Prueba 3 — Verificar que ProjectStatusChanged implementa ShouldQueue

**Archivo:** `app/Notifications/ProjectStatusChanged.php`

```php
class ProjectStatusChanged extends Notification implements ShouldQueue
```

✅ **Criterio de aceptación:** La clase debe implementar `ShouldQueue`.

### Prueba 4 — Verificar encolamiento real

1. Asegurar que el scheduler NO esté corriendo (para que los jobs se acumulen)
2. Hacer un cambio de estado en un proyecto (ej: `POST /api/projects/{id}/review`)
3. Verificar que se creó un registro en la tabla `jobs`:

```sql
SELECT * FROM jobs ORDER BY id DESC;
```

4. Iniciar el scheduler:
```bash
bash start.sh
```
o
```bash
php artisan queue:work --stop-when-empty
```

5. Verificar que el job se procesó y desapareció de la tabla `jobs`

✅ **Criterio de aceptación:** Los jobs se encolan y se procesan en background. La respuesta HTTP es inmediata (< 1s) independientemente de la cantidad de notificaciones.

### Prueba 5 — Verificar reintentos automáticos

Simular un error en ExpoPushService (mock). El queue debe reintentar el job hasta 3 veces antes de marcarlo como fallido.

```sql
-- Verificar jobs fallidos (si los hay)
SELECT * FROM failed_jobs ORDER BY id DESC;
```

---

## 🟠 A-01b: Filtro de notificaciones por rol en ProjectObserver

### Problema original

`ProjectObserver::updated()` iteraba `User::all()` → notificaba a TODOS los usuarios sin filtrar.

### Prueba 1 — Verificar matriz de roles en el código

**Archivo:** `app/Observers/ProjectObserver.php` (línea ~32)

Revisar que `getRolesForStatus()` contenga la matriz:

| Estado | Roles notificados |
|--------|-------------------|
| CREADO | CIERRE_DE_OBRA, SUPERADMIN, ADMIN |
| REVISADO_CIERRE | PROCURA, SUPERADMIN, ADMIN |
| CONFIRMADO_PROCURA | ANALISTA, SUPERADMIN, ADMIN |
| COMPARATIVA_ENVIADA | PROCURA, SUPERADMIN, ADMIN |
| CONTRATADO | FINANZAS, CIERRE_DE_OBRA, INFRAESTRUCTURA, PRESIDENCIA, SUPERADMIN, ADMIN |
| EN_EJECUCION | CIERRE_DE_OBRA, INFRAESTRUCTURA, PRESIDENCIA, SUPERADMIN, ADMIN |
| VERIFICANDO_FINALIZACION | SUPERADMIN, ADMIN |
| LISTO_PAGO_FINAL | *(ninguno)* |
| COMPLETADO_PAGADO | CIERRE_DE_OBRA, INFRAESTRUCTURA, PRESIDENCIA, SUPERADMIN, ADMIN |

### Prueba 2 — Test de integración: solo roles específicos reciben notificación

Usar PHPUnit para verificar que `User::whereIn('role', $roles)` se ejecuta correctamente:

```bash
php artisan test --filter ProjectObserverTest --testsuite=Feature
```

Si no existe el test, se puede verificar manualmente:

1. Crear usuarios de distintos roles (FINANZAS, ANALISTA, CATALOGOS, etc.)
2. Cada usuario debe tener un push token registrado
3. Cambiar estado de un proyecto a `CONTRATADO`
4. Verificar que solo FINANZAS, CIERRE_DE_OBRA, INFRAESTRUCTURA, PRESIDENCIA, SUPERADMIN Y ADMIN recibieron la notificación
5. CATALOGOS **no** debe recibir notificación

✅ **Criterio de aceptación:** Usuarios con roles no incluidos en la matriz no reciben notificaciones.

### Prueba 3 — Estado LISTO_PAGO_FINAL no genera notificaciones

1. Crear un proyecto en estado `VERIFICANDO_FINALIZACION`
2. Ejecutar verifyCompletion con `qualityVerified = true`
3. El proyecto pasa a `LISTO_PAGO_FINAL`
4. Verificar que **no** se insertaron jobs en la tabla `jobs` para este cambio

✅ **Criterio de aceptación:** `LISTO_PAGO_FINAL` no dispara notificaciones (es estado técnico puente).

---

## 🟠 A-02: ExpoPushService procesa respuesta y limpia tokens zombies

### Problema original

`ExpoPushService::sendToUser()` ignoraba la respuesta de Expo Push API. Los tokens de dispositivos que desinstalaron la app nunca se limpiaban.

### Prueba 1 — Verificar que el método processResponse existe

**Archivo:** `app/Services/ExpoPushService.php`

Deben existir los métodos:
- `sendBatch(?int $userId, $messages)` 
- `processResponse(?int $userId, $messages, $response)`

### Prueba 2 — Simular token zombie (test funcional con mock)

Ejecutar en tinker para verificar la lógica de limpieza:

```bash
php artisan tinker
```

```php
// Simular inserción de un token
$token = \App\Models\PushToken::create([
    'user_id' => 1,
    'token' => 'ExponentPushToken[test-zombie-token-999]',
    'device' => 'android',
]);

echo "Token creado: {$token->id}\n";

// Verificar que existe
echo "Existen: " . \App\Models\PushToken::count() . " tokens\n";
```

### Prueba 3 — Verificar que sendToUser captura la respuesta

Revisar el código:

```php
private function sendBatch(?int $userId, $messages): void
{
    $response = Http::post(self::EXPO_API, $messages->values()->toArray());
    // ...
    $this->processResponse($userId, $messages, $response);
}
```

✅ **Criterio de aceptación:** `Http::post()` se asigna a `$response` y se pasa a `processResponse()`.

### Prueba 4 — Verificar eliminación de DeviceNotRegistered

Revisar `processResponse()`:

```php
$error = $item['details']['error'] ?? '';
if ($error !== 'DeviceNotRegistered' && $error !== 'ExponentNotRegistered') {
    continue;
}
$failedToken = $messages[$i]['to'] ?? null;
// ... delete
```

✅ **Criterio de aceptación:** Cuando Expo devuelve `DeviceNotRegistered` o `ExponentNotRegistered`, el token se elimina de la BD y se registra un log `info`.

### Prueba 5 — Verificar logs de limpieza

Buscar en los logs:
```bash
grep "Push token eliminado" storage/logs/laravel.log
```

---

## 🟠 A-03: Validación SSRF en baseUrl de IA

### Problema original

El campo `base_url` aceptaba cualquier string, permitiendo potencialmente redirigir peticiones HTTP a servicios internos.

### Prueba 1 — Validación de HTTPS requerido

```bash
curl -X POST http://localhost:8000/api/ai/config \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "provider": "openai",
    "model": "gpt-4o",
    "apiKey": "sk-test12345678",
    "baseUrl": "http://api.openai.com/v1"
  }'
```

✅ **Criterio de aceptación:** Debe responder `422` con error: *"La URL debe usar HTTPS (conexión segura)."`

### Prueba 2 — Validación de localhost rechazado

```bash
curl -X POST http://localhost:8000/api/ai/config \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "provider": "openai",
    "model": "gpt-4o",
    "apiKey": "sk-test12345678",
    "baseUrl": "https://localhost:3306"
  }'
```

✅ **Criterio de aceptación:** Debe responder `422` con error: *"No se permite usar direcciones locales (localhost/127.0.0.1)."*

### Prueba 3 — Validación de IPs privadas

Probar con `https://192.168.1.1`, `https://10.0.0.1`, `https://172.16.0.1`:

```bash
curl -X POST http://localhost:8000/api/ai/config \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "provider": "openai",
    "model": "gpt-4o",
    "apiKey": "sk-test12345678",
    "baseUrl": "https://192.168.1.1/admin"
  }'
```

✅ **Criterio de aceptación:** Debe responder `422` con error: *"No se permite usar IPs privadas o de rangos reservados."*

### Prueba 4 — Validación de URL correcta permitida

```bash
curl -X POST http://localhost:8000/api/ai/config \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "provider": "openai",
    "model": "gpt-4o",
    "apiKey": "sk-test12345678",
    "baseUrl": "https://api.openai.com/v1"
  }'
```

✅ **Criterio de aceptación:** Debe responder `201` (creación exitosa).

### Prueba 5 — Update también valida

Repetir las pruebas 1-4 con `PATCH /api/ai/config/{id}`.

✅ **Criterio de aceptación:** Mismas validaciones aplican en update.

---

## 🟠 A-04: API keys no se cachean en texto plano

### Problema original

`toServiceConfig()` incluía `api_key` en el array que se persistía en `Cache::forever()`. Ahora la key se obtiene directamente de BD.

### Prueba 1 — Verificar que toServiceConfig no incluye api_key

**Archivo:** `app/Services/AI/AiConfigurationService.php` (línea ~131)

```php
private function toServiceConfig(AiConfiguration $record): array
{
    return [
        'enabled'    => $record->is_active,
        // api_key NO ESTÁ aquí
        'model'      => $record->model,
        'max_tokens' => $record->max_tokens ?? 4096,
        'base_url'   => $record->base_url ?? $this->defaultBaseUrl($record->provider),
    ];
}
```

✅ **Criterio de aceptación:** `api_key` no debe estar en el array retornado.

### Prueba 2 — Verificar que getApiKey existe

Revisar el método `getApiKey(string $provider): ?string` en `AiConfigurationService.php`:

```php
public function getApiKey(string $provider): ?string
{
    $record = AiConfiguration::where('provider', $provider)
        ->where('is_active', true)
        ->orderBy('sort_order')
        ->orderBy('id')
        ->first();

    return $record?->api_key;
}
```

✅ **Criterio de aceptación:** El método debe consultar la BD directamente y devolver la key desencriptada por el accessor de Eloquent.

### Prueba 3 — Verificar que el cache no contiene api_key

```bash
php artisan tinker
```

```php
$cached = Cache::get('ai_db_config');
if ($cached) {
    foreach ($cached as $provider => $config) {
        echo "Provider: $provider\n";
        echo "  api_key existe?: " . (isset($config['api_key']) ? 'SI (⚠️)' : 'NO (✅)') . "\n";
        echo "  model: " . ($config['model'] ?? 'N/A') . "\n";
    }
} else {
    echo "No hay caché. Forzar sync: php artisan tinker\n";
    echo "app(AiConfigurationService::class)->syncToCache();\n";
}
```

✅ **Criterio de aceptación:** `api_key` no debe aparecer en ningún provider dentro del cache.

### Prueba 4 — Verificar que registerProviders obtiene key de BD

**Archivo:** `app/Services/AI/AIEvaluationService.php` (línea ~67)

```php
$apiKey = $this->configService->getApiKey($key);
if (empty($apiKey)) {
    continue;
}
$config['api_key'] = $apiKey;
```

✅ **Criterio de aceptación:** `registerProviders()` debe llamar a `getApiKey()` que va a BD, no leer del cache.

---

## 🟠 A-05: Providers IA reciben config por constructor (sin mutar estado global)

### Problema original

`registerProviders()` ejecutaba `config(["ai.{$key}" => $config])` mutando el estado global de Laravel.

### Prueba 1 — Verificar que registerProviders ya no muta config global

**Archivo:** `app/Services/AI/AIEvaluationService.php`

Hacer un grep para confirmar que no existe `config(["ai.` en el archivo:

```bash
grep -n 'config(\["ai\.' app/Services/AI/AIEvaluationService.php
```

✅ **Criterio de aceptación:** No debe haber resultados. El grep debe devolver vacío.

### Prueba 2 — Verificar que providers ya no leen de config()

Para cada provider:

```bash
grep -n 'config(' app/Services/AI/Providers/OpenAIProvider.php
grep -n 'config(' app/Services/AI/Providers/GeminiProvider.php
grep -n 'config(' app/Services/AI/Providers/AnthropicProvider.php
```

✅ **Criterio de aceptación:** Ningún provider debe contener llamadas a `config()`. Los resultados deben estar vacíos.

### Prueba 3 — Verificar que los constructores aceptan array config

Cada provider debe tener:

```php
public function __construct(array $config = [])
{
    $this->apiKey    = $config['api_key'] ?? '';
    $this->model     = $config['model'] ?? '...';
    // ...
}
```

✅ **Criterio de aceptación:** Todos los constructores aceptan `array $config` y asignan desde ahí.

### Prueba 4 — Prueba de regresión: instanciación manual

```bash
php artisan tinker
```

```php
$config = [
    'api_key'    => 'sk-test-' . bin2hex(random_bytes(8)),
    'model'      => 'gpt-4o',
    'base_url'   => 'https://api.openai.com/v1',
    'timeout'    => 30,
    'max_tokens' => 4096,
];

$provider = new \App\Services\AI\Providers\OpenAIProvider($config);
echo "Name: " . $provider->name() . "\n";
echo "OK: provider instanciado sin tocar config() global\n";
```

✅ **Criterio de aceptación:** El provider se instancia correctamente sin depender de `config()` global.

### Prueba 5 — Prueba de regresión: AI Evaluation sigue funcionando

Ejecutar evaluación IA (si hay proveedores configurados):

```bash
curl -X POST http://localhost:8000/api/ai/evaluate-proposals \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "projectId": "PRJ-001",
    "projectTitle": "Test",
    "projectDescription": "Test description",
    "projectLocation": "Test",
    "projectType": "INFRAESTRUCTURA",
    "approvedInvestmentAmount": 50000,
    "proposals": [
      {
        "id": "PROP-001",
        "contractorCode": "CON-001",
        "contractorName": "Test Contractor",
        "materialCost": 20000,
        "laborCost": 8000,
        "totalCost": 28000,
        "deliveryWeeks": 12,
        "negotiatedAdvancePercent": 30,
        "description": "Test proposal"
      }
    ]
  }'
```

✅ **Criterio de aceptación:** La evaluación funciona igual que antes, sin errores relacionados con configuración de proveedores.

---

## Prueba Integral (End-to-End)

Para verificar que todos los fixes conviven correctamente:

```bash
#!/bin/bash
# test-fixes.sh — Prueba integral de los 7 fixes

echo "=== PRUEBA INTEGRAL FIXES AUDITORÍA TYPE-A ==="
echo ""

# 1. Verificar que el servidor responde
echo "[1/7] Verificando servidor..."
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/api/login
echo ""

# 2. Verificar projects endpoint autenticado
echo "[2/7] Verificando autenticación..."
TOKEN=$(curl -s -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@test.com","password":"password"}' | jq -r '.token')
echo "Token obtenido: ${TOKEN:0:20}..."

# 3. Verificar SSRF validation
echo "[3/7] Probando SSRF validation..."
RESULT=$(curl -s -X POST http://localhost:8000/api/ai/config \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"provider":"openai","model":"gpt-4o","apiKey":"sk-test1234","baseUrl":"http://localhost"}' | jq -r '.message')
echo "SSRF test: $RESULT"

# 4. Verificar que jobs table existe
echo "[4/7] Verificando tabla jobs..."
php artisan tinker --execute="echo Schema::hasTable('jobs') ? 'OK' : 'FALTA';"

# 5. Verificar config queue
echo "[5/7] Verificando QUEUE_CONNECTION..."
grep QUEUE_CONNECTION .env

# 6. Verificar ShouldQueue
echo "[6/7] Verificando ShouldQueue..."
grep -l 'ShouldQueue' app/Notifications/ProjectStatusChanged.php

# 7. Verificar que no hay config() en providers
echo "[7/7] Verificando providers sin config()..."
for f in app/Services/AI/Providers/*.php; do
    COUNT=$(grep -c "config(" "$f" 2>/dev/null || echo 0)
    echo "  $(basename $f): $COUNT llamadas a config()"
done

echo ""
echo "=== PRUEBA COMPLETADA ==="
```

Guardar como `test-fixes.sh` y ejecutar:
```bash
bash test-fixes.sh
```
