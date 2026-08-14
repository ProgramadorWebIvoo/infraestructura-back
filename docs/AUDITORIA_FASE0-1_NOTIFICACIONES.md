# Auditoría Fase 0→1 — Sistema de Notificaciones

> Auditoría de código real (no revisión de diseño) contra los criterios de aceptación de 1.1 (Sistema unificado de notificaciones) y 1.2 (ToastAlertAction) del Plan Maestro 90 Días. Fecha: 2026-08-14. Rama: `FIXES`.
>
> **Estado: los 5 hallazgos fueron corregidos el mismo día.** Ver sección "Resolución aplicada" al final de cada hallazgo. Verificado con suite completa: backend 312/312, frontend 762/762, ambos typecheck limpios.

## Resumen ejecutivo

El sistema tiene una arquitectura sólida (punto de entrada único real, matriz configurable rol×acción×canal, caché con invalidación explícita, colas para mail/push, buena cobertura de tests) pero **tres brechas concretas rompen la promesa de "punto único" y "todos los tipos pedidos"**:

1. **3 controladores nuevos (`CurrencyController`, `AppSettingController`, `NotificationRuleController`) registran auditoría pero nunca notifican** — inconsistente con los otros 4 que sí llaman `notify()`.
2. **Mail y push nunca se envían para ninguna acción administrativa sin proyecto** (~15 de 35 acciones del catálogo: usuarios, proveedores, materiales, IA) — un `if ($project !== null)` en el dispatcher lo bloquea estructuralmente, incluso si la matriz tiene roles configurados para esas acciones en canal `mail`.
3. **No existe la taxonomía de 6 tipos pedida** (información, éxito, advertencia, error, acción requerida, prioritario) — ni en backend (`AppNotification` no tiene columna de tipo) ni en frontend (`Toast` solo tiene 4 tipos + 2 dimensiones ortogonales que no son tipos).

Push real es un canal Expo (apps móviles), no web push — puede ser una brecha de nomenclatura frente a lo que el plan describía, a validar contigo si el foco era navegador.

---

## Hallazgo 1 — Punto único de entrada roto por 3 controladores nuevos (ALTO)

**Criterio violado**: "Un solo punto de entrada (`notify()` o similar) usado por todos los módulos."

`NotificationDispatcher::notify()` (`app/Services/NotificationDispatcher.php:46`) es el punto único real, y `AuditLog::record()` (flujo de proyectos) lo invoca automáticamente en cada auditoría (`app/Models/AuditLog.php:66`). Pero `ConfigAuditLog::recordAdminAction()`/`recordSettingChange()` (auditoría administrativa, agregada en trabajo reciente de CONFIG APP) **no lo invoca nunca** — cada controlador que la usa debe llamar `NotificationDispatcher::notify()` por su cuenta, a mano, después.

De los 7 controladores que usan `ConfigAuditLog`, solo 4 recuerdan hacerlo:

| Controlador | Llama `NotificationDispatcher::notify()` |
|---|---|
| `AiConfigController.php` | ✅ (líneas 92, 145, 163) |
| `ContractorController.php` | ✅ (líneas 48, 71, 90, 154) |
| `MaterialController.php` | ✅ (líneas 49, 93, 105) |
| `UserController.php` | ✅ (líneas 56, 80, 88, 106) |
| **`AppSettingController.php`** | ❌ — nunca |
| **`CurrencyController.php`** | ❌ — nunca |
| **`NotificationRuleController.php`** | ❌ — nunca |

**Consecuencia real**: cambiar un `AppSetting`, dar de alta/editar/desactivar/eliminar una moneda, o editar la propia matriz de notificaciones (irónicamente) **queda auditado pero nadie se entera** — ni en bandeja interna, ni por mail, aunque el catálogo (`NotificationCatalog`) o la matriz (`notification_rules`) tuvieran destinatarios configurados para esas acciones. El usuario SUPERADMIN que configuró CONFIG APP no ve reflejado ese cambio en la campana de notificaciones de otros administradores.

**Causa raíz**: `recordAdminAction()`/`recordSettingChange()` se diseñaron como "solo auditoría" (ver docblock de `ConfigAuditLog`, línea 8-14: "deliberadamente separada de `AuditLog`"), pero esa separación se volvió, en la práctica, una fuga silenciosa del punto único — no fue una decisión explícita de "estas acciones no notifican", fue simplemente que 3 de 7 controladores olvidaron el segundo paso manual.

**Resolución aplicada**: se movió la llamada a `NotificationDispatcher::notify()` DENTRO de `ConfigAuditLog::recordAdminAction()`/`recordSettingChange()` (patrón estructural, no parche por controlador — igual que `AuditLog::record()` ya hacía para el flujo de proyectos). Se eliminaron las 11 llamadas manuales redundantes en `AiConfigController`, `ContractorController`, `MaterialController`, `UserController` (ahora automáticas). Se agregó el parámetro opcional `notifyAction` a `recordAdminAction()` para los casos donde la clave de auditoría no coincide 1:1 con la acción del catálogo de notificaciones (ej. `NotificationRuleController` audita `"notification_rules.{accion}"` por fila pero notifica como una sola acción, `"Modificacion de reglas de notificacion"`). Se agregaron al catálogo (`NotificationCatalog`) 6 acciones que no existían: `Modificacion de configuracion`, `Alta de moneda`, `Modificación de moneda`, `Cambio de moneda base`, `Eliminación de moneda`, `Modificacion de reglas de notificacion`. Cobertura nueva: `CurrencyTest::test_creating_a_currency_notifies_recipients`, `AppSettingTest::test_updating_a_setting_notifies_recipients`, `NotificationRuleControllerTest::test_update_notifies_recipients_as_a_single_catalog_action`.

## Hallazgo 2 — Mail/push nunca se disparan sin proyecto asociado (ALTO)

**Criterio violado**: "Servicio central: Toast + alertas internas + correo + push... usado por todos los módulos."

Dentro de `NotificationDispatcher::notify()`:

```php
foreach ($appRecipients as $user) {
    if ($project !== null) {
        $user->notify(new ProjectActionNotification($project, $action, $project->status)); // push
    }
    AppNotification::create([...]); // bandeja interna — SIEMPRE se crea
}

if (!static::isMailActionAllowed($action)) return;
$mailRecipients = NotificationRuleResolver::recipientsFor($action, 'mail');
foreach ($mailRecipients as $user) {
    if ($project !== null) {
        $user->notify(new ProjectActionMail($project, $action, $details)); // mail
    }
}
```

El `if ($project !== null)` envuelve tanto el push (Expo) como el mail. Todas las llamadas administrativas pasan `$project = null` (`NotificationDispatcher::notify(null, 'SISTEMA', ...)`). Resultado: **para ~15 de las 35 acciones del catálogo** (todo el grupo `usuarios`, `catalogos`, y buena parte de `sistema` — incluyendo acciones marcadas `critical: true` como "Cambio de rol de usuario" o "Alta de configuracion de IA") solo se llena la bandeja interna; mail y push nunca salen, sin importar qué diga la matriz `notification_rules` para esas acciones en canal `mail`.

**Esto no es un bug de los controladores** — es una limitación estructural del dispatcher. La matriz permite configurar roles de mail para esas acciones (la UI de CONFIG APP no lo impide), pero configurarlo no tiene efecto: es una promesa rota silenciosamente, sin error ni log que lo señale.

**Resolución aplicada**: se crearon `App\Notifications\AdminActionMail` y `App\Notifications\AdminActionNotification` — equivalentes de `ProjectActionMail`/`ProjectActionNotification` sin dependencia de `Project` (mismo patrón `ShouldQueue`, mismo `toMail()`/`toExpo()`). `NotificationDispatcher::notify()` ahora despacha `ProjectAction*` cuando `$project !== null` y `AdminAction*` cuando es `null`, en vez de omitir el envío. Verificado con `AdminActionAuditTest::test_admin_action_without_project_sends_mail_when_configured` (regla `notification_rules` de canal `mail` + `acciones_con_correo` configurados → `AdminActionMail` sí se envía, cosa que antes del fix era estructuralmente imposible).

## Hallazgo 3 — No existe la taxonomía de 6 tipos pedida (MEDIO)

**Criterio pedido**: "Tipos: información, éxito, advertencia, error, acción requerida, prioritario."

**Backend**: la tabla `app_notifications` (migración `2026_08_12_000001`) no tiene columna `tipo`/`type`. El modelo `AppNotification` no define ningún enum. Lo más cercano es `critical: bool` en `NotificationCatalog::ACTIONS` — binario, no una taxonomía de 6 valores.

**Frontend**: `Toast.tsx` define `AlertType = "success" | "error" | "warning" | "info"` (4 valores, en `alertStyles.ts:11`), más dos dimensiones ortogonales que no son "tipos":
- `priority?: "normal" | "high"` — afecta duración/urgencia visual, no reemplaza a "acción requerida" ni "prioritario" como concepto propio.
- `variant?: "default" | "notification"` — distingue "esto es feedback de tu propia acción" vs. "esto pasó en el sistema/otro usuario".

No hay ningún tipo ni combinación que represente **"acción requerida"** de forma distinguible (un toast con un botón de acción se ve igual que cualquier otro `type`, solo cambia si tiene o no el botón — no hay un badge/color/ícono específico de "esto requiere que hagas algo").

**Impacto**: no bloquea funcionalidad (los 4 tipos existentes cubren la mayoría de casos reales de uso hoy), pero el criterio de aceptación explícito del plan no está cumplido tal como se redactó.

**Resolución aplicada**:
- Backend: nueva clase `App\Support\NotificationType` (6 constantes: `INFORMACION`, `EXITO`, `ADVERTENCIA`, `ERROR`, `ACCION_REQUERIDA`, `PRIORITARIO`), migración `2026_08_14_000014_add_type_to_app_notifications` (columna `type`, default `informacion` para backfill de filas existentes). `NotificationCatalog::type($action)` resuelve el tipo por defecto de cada acción — con overrides explícitos para los casos donde el binario `critical` no basta ("Rechazo de cuadro comparativo" → `accion_requerida`, altas/confirmaciones → `exito`, reset de password → `informacion`), y fallback `critical ? prioritario : informacion` para el resto. `NotificationDispatcher::notify()` persiste el `type` calculado en cada fila de `AppNotification`.
- Frontend: `AlertType` (en `alertStyles.ts`) pasó de 4 a 6 valores (`+ "action-required" | "urgent"`), con íconos (`Hand`, `Zap`) y paleta de color propios (violeta/naranja). Nuevo `BACKEND_NOTIFICATION_TYPE_MAP` traduce los 6 valores del backend a `AlertType`. `NotificationsProvider` ahora usa el `type` real de cada notificación al mostrar el toast (antes siempre `"info"` hardcodeado). `NotificationList` muestra un ícono de tipo junto a cada entrada de la bandeja.
- `priority`/`variant` de `Toast.tsx` **no se tocaron** — siguen siendo dimensiones ortogonales (duración/origen), no reemplazadas por la taxonomía.
- Cobertura nueva: `tests/Unit/NotificationCatalogTest.php` (backend), 3 tests de tipo en `NotificationDispatcherTest`, `alertStyles.test.ts` + tests de tipo en `Toast.test.tsx`/`NotificationsProvider.test.tsx` (frontend).

## Hallazgo 4 — `targetRole` es un campo vestigial (BAJO)

`ShowToastOptions.targetRole?: string` está declarado y documentado como *"Rol destinatario informativo (para bandeja/registro); no filtra la UI local"* — pero no hay un solo `showToast(..., { targetRole: ... })` real en todo el frontend, y aunque lo hubiera, no tiene ningún lector: no se guarda en el objeto `Toast` interno, no se compara contra el rol del usuario actual, no filtra nada. Es un campo muerto en el tipo público, sin implementación ni caso de uso activo.

**Impacto**: bajo (no rompe nada, no se usa), pero es deuda de diseño — declarar un campo "para el futuro" sin uso real invita a que otro desarrollador confíe en que hace algo.

**Resolución aplicada**: se eliminó `targetRole` de `ShowToastOptions` en `Toast.tsx`. Confirmado (grep) que no quedaba ningún consumidor en el resto del frontend antes de quitarlo.

## Hallazgo 5 — Push real es Expo (móvil), no push del navegador (INFORMATIVO)

El plan describe "push (en tiempo real dentro del flujo activo, no como job en segundo plano)". Lo implementado es:
- Canal **Expo Push** (`app/Services/ExpoPushService.php`, `PushToken`) — funcional y conectado, pero es para apps React Native/Expo, no navegador web.
- **No hay** web-push/FCM/VAPID/Service Worker — confirmado por búsqueda exhaustiva (0 resultados) y por el propio comentario en `NotificationsProvider.tsx:18-23`, que admite explícitamente que no hay push real de navegador y que Laravel Reverb (que lo permitiría) requiere Laravel 10+ (el proyecto está en Laravel 9).
- Lo que hoy simula "push" en el navegador es **polling disfrazado**: intervalo configurable (`polling_notificaciones_segundos`, default 8s) que sigue corriendo con la pestaña en background, más la Notification API nativa del navegador como aviso visual — no es push real (no hay entrega server-to-client sin que el cliente pregunte primero).

**No es un defecto de implementación** — es una limitación de plataforma ya documentada en el propio código, correctamente etiquetada como pendiente de upgrade a Laravel 10+/Reverb. Se incluye aquí solo para que quede explícito frente al criterio de aceptación original, que sí pedía "push" sin matizar la plataforma.

---

## Lo que SÍ funciona bien (no tocar sin necesidad)

- **Matriz configurable rol×acción×canal** (`notification_rules` + `NotificationRuleResolver`) — resolución centralizada, caché con invalidación explícita en cada escritura, fallback seguro (`SUPERADMIN`/`ADMIN` en canal app si una acción no está configurada; nunca nadie en canal mail por defecto).
- **Filtro on/off por acción** vía `acciones_con_notificacion_app`/`acciones_con_correo` (CONFIG APP), independiente de la matriz — capa adicional correcta.
- **`useToast()` es el único punto de entrada real en frontend** para feedback tipo toast — no hay `alert()` ni implementaciones ad-hoc compitiendo. Los banners (`AlertBanner`, `InfoBanner`, `OfflineBanner`, `SyncBanner`) son un patrón complementario legítimo (persistente, no flotante), no una fuga.
- **`action`/link en el toast** — renderizado y testeado correctamente (`Toast.test.tsx`).
- **Colas** (`ShouldQueue`) en ambas notificaciones (mail y Expo push) — no bloquean el request.
- **Cobertura de tests decente**: 20 tests en `NotificationDispatcherTest`, 8 en `NotificationRuleResolverTest`, 9 en `NotificationRuleControllerTest`, 3 en `PruneOldNotificationsTest` (backend); 13 en `Toast.test.tsx`, 9 en `NotificationsProvider.test.tsx`, 9 en `browserNotifications.test.ts` (frontend).
- **Instancia única de polling** (`NotificationsProvider` centralizado, evita duplicar toasts cuando `NotificationBell` se monta 2 veces en el layout) — fix real ya resuelto.

---

## Resumen de archivos tocados en la resolución

**Backend (nuevos):** `database/migrations/2026_08_14_000014_add_type_to_app_notifications.php`, `app/Support/NotificationType.php`, `app/Notifications/AdminActionMail.php`, `app/Notifications/AdminActionNotification.php`, `tests/Unit/NotificationCatalogTest.php`.

**Backend (modificados):** `app/Models/ConfigAuditLog.php` (notify() estructural), `app/Services/NotificationDispatcher.php` (AdminAction* + type), `app/Support/NotificationCatalog.php` (6 acciones nuevas + `type()`), `app/Models/AppNotification.php` (fillable `type`), `AiConfigController.php`/`ContractorController.php`/`MaterialController.php`/`UserController.php` (llamadas manuales eliminadas), `NotificationRuleController.php` (`notifyAction`), `tests/Feature/{AdminActionAuditTest,AppSettingTest,CurrencyTest,NotificationRuleControllerTest,NotificationDispatcherTest}.php`.

**Frontend (nuevos):** `src/__tests__/components/UI/alertStyles.test.ts`.

**Frontend (modificados):** `src/components/UI/alertStyles.ts` (6 tipos + mapa backend), `src/components/UI/Toast.tsx` (nuevos estilos + `targetRole` eliminado), `src/components/UI/NotificationsProvider.tsx` (usa `type` real), `src/components/UI/NotificationList.tsx` (ícono de tipo), `src/types.ts` (`AppNotification.type`), tests asociados.

## Verificación final

- Backend: `php artisan test` → **312/312 passed**.
- Frontend: `npx tsc --noEmit -p .` limpio + `npx vitest run` → **762/762 passed** (81 archivos).
- Migración aplicada contra la BD de desarrollo real (`php artisan migrate --force`).
