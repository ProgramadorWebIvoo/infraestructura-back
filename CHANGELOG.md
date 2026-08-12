# CHANGELOG — infraestructura-back

## Sinopsis del proyecto

- **Stack:** Laravel (PHP 8) + MariaDB/MySQL + Sanctum (API REST). Frontend SPA en repositorio hermano `infraestructura` (React + Vite + TypeScript, monorepo con `packages/shared` y `mobile/`).
- **Propósito:** API backend de gestión de infraestructura/obras — ciclo de vida de proyectos (9 estados), propuestas de contratistas, evaluación IA de ofertas (ChatGPT→Gemini→Claude con failover), portal público de proveedores, dashboard ejecutivo de Presidencia y auditoría.
- **Estructura clave:**
  - `app/Http/Controllers/Api/` — controllers por dominio (`ProjectController`, `SupportController`, `UserController`, `AuthController`, `DashboardSummaryController`, `AIEvaluationController`, `ContractorController`, `AiConfigController`, `ProjectDocumentController`).
  - `app/Services/AI/` — `AIEvaluationService` (orquestador con failover) + `BaseAIProvider` (abstracta) y providers `OpenAI/Gemini/Anthropic`.
  - `routes/api.php` — todas las rutas, protegidas con `auth:sanctum` + middleware `role:` + `refresh.token` (rotación de tokens Sanctum).
  - `config/permissions.php` — matriz de permisos SPA (fuente de verdad servidor). Config IA 100% por BD (`ai_configurations`); catálogo de modelos seleccionables como constante en `AiConfigController`.
  - `docs/CHANGELOG.md` — historial detallado de cambios (este archivo es solo la sinopsis).
- **Decisiones de arquitectura relevantes:**
  - Sanctum SPA nativo (sesión + CSRF) para web; Bearer token para mobile.
  - `GET /api/dashboard/summary` = fuente de verdad de agregados de Presidencia; el frontend mantiene espejo cliente como fallback offline.
  - Notificaciones push encoladas (`QUEUE_CONNECTION=database`) con worker por scheduler.
  - Config IA por constructor (sin mutar estado global) y API keys encriptadas solo en BD (nunca en cache).

## Historial

- Historial completo de cambios en `docs/CHANGELOG.md` (raíz del historial detallado).

## [2026-08-11] — Diagrama: eliminadas flechas de rechazo en DIAGRAM_APP.md
- Tipo: docs
- Qué: se quitaron del diagrama Mermaid las aristas `COMPARATIVA_ENVIADA → CONFIRMADO_PROCURA` (Procura rechaza propuestas) y `VERIFICANDO_FINALIZACION → EN_EJECUCION` (Calidad rechazada), junto con el estilo `backStep` que las resaltaba.
- Por qué / causa raíz: solicitud del usuario de simplificar el diagrama (solo `docs/DIAGRAM_APP.md`; `docs/FLUJO_INFRAESTRUCTURA.md` queda intacto).
- Archivos: `docs/DIAGRAM_APP.md`.

## [2026-07-31] — Limpieza: eliminado config/ai.php (config IA 100% por BD) + código muerto
- Tipo: refactor
- Qué: se eliminó `config/ai.php` (env vars AI_PROVIDER_ORDER/AI_TIMEOUT/OPENAI_*/GEMINI_*/ANTHROPIC_* sin efecto) y sus 3 referencias; `available_models` pasó a constante `AiConfigController::AVAILABLE_MODELS`; timeout IA fijo como constante; se quitaron imports muertos, `AI_TIMEOUT` de `.env`/`.env.example` y el script temporal `test-fixes.sh`.
- Por qué / causa raíz: la configuración IA es administrada en runtime desde la tabla `ai_configurations` (BD); el archivo de config quedó obsoleto y sus variables no tenían efecto.
- Archivos: `config/ai.php` [ELIMINADO], `test-fixes.sh` [ELIMINADO], `app/Http/Controllers/Api/AiConfigController.php`, `app/Services/AI/AIEvaluationService.php`, `.env.example`, `.env`.
- Verificación: suite completa 186/186 tests pasando.
