# CHANGELOG

## Proyecto: Infraestructura (Laravel + MySQL)

Stack: Laravel (PHP), MariaDB/MySQL, Sanctum.

Estructura clave:
- `ivoo_gestion_infraestructura.sql` — dump de BD exportado desde phpMyAdmin.
- Login via Sanctum -> tabla `personal_access_tokens`.

## [2026-07-16]
- Cambio: Fix error 1364 "Field 'id' doesn't have a default value" en `personal_access_tokens`.
- Causa raíz: El dump SQL definía `id` sin `AUTO_INCREMENT` en la creación de la tabla, y el ALTER TABLE correspondiente (al final del dump) no se ejecutaba porque la importación fallaba antes debido a un error de collation en la vista `vw_project_summary`.
  - El error de collation (#1267) ocurría porque la tabla `contractors` no tenía `COLLATE` explícito, heredando `utf8mb4_general_ci` de la BD, mientras `projects` tenía `utf8mb4_unicode_ci`. El JOIN en la vista fallaba, deteniendo la importación antes de los `ALTER TABLE ... AUTO_INCREMENT`.
- Fix aplicados al dump SQL:
  1. Tabla `contractors`: agregado `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`.
  2. Tabla `personal_access_tokens`: se movieron PRIMARY KEY, UNIQUE KEY y KEY directo al CREATE TABLE, y se eliminaron los ALTER TABLE redundantes al final del dump (evita error #1075 por AUTO_INCREMENT sin KEY).
  3. Vista `vw_project_summary`: forzado `collate utf8mb4_unicode_ci` en la condición `c.code = p.selected_contractor_code`.
- Archivos: `ivoo_gestion_infraestructura.sql`

## [2026-07-16] — Implementación Backend Evaluación Inteligente IA

### Cambio: Sistema completo de Evaluación Inteligente de Ofertas (Procura)

Se implementó el backend Laravel que orquesta llamadas a OpenAI (ChatGPT), Google Gemini y Anthropic (Claude) con failover automático.

**Arquitectura:**
- `POST /api/ai/evaluate-proposals` — Endpoint protegido con Sanctum
- `AIEvaluationController` — Valida request, llama al servicio, registra auditoría
- `AIEvaluationService` — Orquestador: prueba proveedores en orden configurado, failover en rate-limit/timeout/error
- `AIProviderInterface` — Contrato que todos los providers implementan
- `OpenAIProvider` — Llama a `chat/completions` con GPT-4o
- `GeminiProvider` — Llama a `models/{model}:generateContent` con Gemini 1.5 Pro
- `AnthropicProvider` — Llama a `/messages` con Claude 3 Opus

**Failover:**
1. OpenAI (ChatGPT) — 1er intento
2. Gemini (Google) — 2do intento si falla
3. Anthropic (Claude) — 3er intento si fallan los anteriores
4. Todos fallan → `AllProvidersExhaustedException` → 503 con mensaje claro

**Prompt Engineering:**
- System prompt con rol de "Ingeniero en Infraestructura con 15 años de experiencia en finanzas y contratación"
- Evalúa: costo total, relación costo-beneficio, plazo, %anticipo, capacidad del contratista, observaciones (tasa USD, garantías, disponibilidad)
- Respuesta estrictamente JSON con: winner, score, summary, strengths, weaknesses, risks, recommendation

**Configuración (`config/ai.php`):**
- Variables de entorno para API keys y modelos
- Orden de proveedores configurable via `AI_PROVIDER_ORDER`
- Timeout configurable por llamada

**Adicional:**
- Migration: columna `observations` en `project_proposals`
- Modelo `ProjectProposal`: `observations` en fillable
- Resource `ProjectResource`: `observations` en serialización
- `ProjectController@addProposal`: acepta campo `observations`
- Auditoría: cada evaluación se registra en `audit_logs` con score, ganador, proveedor usado

**Archivos creados/modificados:**
- `app/Services/AI/Providers/AIProviderInterface.php` — [NUEVO]
- `app/Services/AI/Providers/OpenAIProvider.php` — [NUEVO]
- `app/Services/AI/Providers/GeminiProvider.php` — [NUEVO]
- `app/Services/AI/Providers/AnthropicProvider.php` — [NUEVO]
- `app/Services/AI/AIEvaluationService.php` — [NUEVO]
- `app/Http/Controllers/Api/AIEvaluationController.php` — [NUEVO]
- `config/ai.php` — [NUEVO]
- `database/migrations/2026_07_16_150117_add_observations_to_project_proposals.php` — [NUEVO]
- `routes/api.php` — + ruta POST /api/ai/evaluate-proposals
- `app/Models/ProjectProposal.php` — + observations en fillable/casts
- `app/Http/Resources/ProjectResource.php` — + observations en serialización
- `app/Http/Controllers/Api/ProjectController.php` — + observations en addProposal
- `.env` — + variables OPENAI_API_KEY, GEMINI_API_KEY, ANTHROPIC_API_KEY
