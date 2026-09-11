# IVOO Gestión Infraestructura — Backend API

> API REST que potencia el sistema de gestión de obras de infraestructura y mantenimiento de IVOO. Controla el ciclo de vida completo de un proyecto: desde su creación hasta el pago final, pasando por revisión, auditoría, licitación y adjudicación.

---

## 1. Qué es IVOO

IVOO Gestión Infraestructura es el sistema corporativo que gestiona el **ciclo de vida completo** de obras de infraestructura. Este backend es la capa de datos, lógica de negocio y autenticación que sirve al frontend SPA.

### Alcance funcional

| Módulo | Qué hace |
|--------|----------|
| **Proyectos** | Creación, edición, seguimiento de obras de infraestructura |
| **Revisión** | Auditoría de expedientes técnicos por Cierre de Obra |
| **Licitación** | Evaluación comparativa de ofertas por Procura |
| **Adjudicación** | Selección de contratista y gestión de contratos |
| **Pagos** | Control de anticipos y pagos finales por Finanzas |
| **Proveedores** | Registro público, invitaciones, propuestas de materiales |
| **Catálogos** | Productos, categorías, precios históricos, monedas |
| **IA** | Evaluación inteligente de propuestas y expedientes |
| **Notificaciones** | In-app, email y push en tiempo real |

### Usuarios del sistema

10 roles organizacionales, cada uno con acceso a módulos específicos:

```
SUPERADMIN          → Acceso total + configuración + auditoría
ADMIN               → Gestión administrativa (sin config de IA)
PRESIDENCIA         → Dashboard ejecutivo, métricas, catálogos
INFRAESTRUCTURA     → Creación y gestión de proyectos
CIERRE_DE_OBRA      → Auditoría de expedientes técnicos
PROCURA             → Licitación, evaluación, adjudicación
ANALISTA            → Evaluación de ofertas, renegociación
FINANZAS            → Control financiero, pagos
CATALOGOS           → Gestión de catálogos de materiales
MARKETING           → Contenido público, portal proveedores
```

---

## 2. Arquitectura del Sistema

```
                    ┌─────────────────────────────────┐
                    │         Frontend (React)         │
                    │     SPA · TypeScript · Vite      │
                    └───────────┬─────────────────────┘
                                │ HTTP/JSON + WebSocket
                                ▼
┌───────────────────────────────────────────────────────────┐
│                    Backend (Laravel 12)                    │
│                                                           │
│  ┌──────────┐   ┌──────────────┐   ┌──────────────────┐  │
│  │  Routes   │──▶│ Controllers  │──▶│    Services      │  │
│  │ api.php   │   │  (23 APIs)   │   │   (34 dominio)   │  │
│  └──────────┘   └──────────────┘   └────────┬─────────┘  │
│                                              │            │
│  ┌──────────────────────────────────────────┐│            │
│  │              Models (25)                 ││            │
│  │  Project · Contractor · CatalogProduct   │◀┘           │
│  │  User · AiConfiguration · ...            │             │
│  └──────────────────┬───────────────────────┘             │
│                     │                                     │
│  ┌──────────────────▼───────────────────────┐             │
│  │              MySQL                       │             │
│  │  30+ tablas · UUIDs en projects          │             │
│  └──────────────────────────────────────────┘             │
│                                                           │
│  ┌────────────────┐  ┌────────────────┐                   │
│  │ Pusher (WS)    │  │ SMTP (Email)   │                   │
│  │ Notificaciones │  │ Correo         │                   │
│  └────────────────┘  └────────────────┘                   │
└───────────────────────────────────────────────────────────┘
```

### Patrones de arquitectura

| Patrón | Dónde se usa | Qué resuelve |
|--------|-------------|--------------|
| **State Machine** | `ProjectStateMachine` | Transiciones de estado del proyecto |
| **Strategy** | Evaluaciones IA | Intercambiar proveedores sin modificar código |
| **Factory** | `AIProviderFactory` | Crear el provider correcto según configuración |
| **Observer** | `PriceEstimationObserver` | Estimar precios automáticamente al cambiar propuestas |
| **Fallback** | `AIEvaluationService` | Si un provider falla, probar con el siguiente |
| **Dispatcher** | `NotificationDispatcher` | Enviar notificaciones por el canal correcto |

---

## 3. Stack Tecnológico

| Capa | Tecnología | Por qué |
|------|------------|---------|
| **Framework** | Laravel 12 | Soporte vigente, ecosistema maduro |
| **Runtime** | PHP 8.2+ | Tipado, enums, fibers |
| **Auth** | Laravel Sanctum 4 | Cookie httpOnly para SPA (no tokens) |
| **WebSocket** | Pusher 7.3 | Hosting compartido no soporta procesos persistentes |
| **DB** | MySQL | Requisito del cliente |
| **Migrations** | Doctrine DBAL 3.6 | Migraciones avanzadas (rename column, etc.) |
| **HTTP Client** | Guzzle 7.2 | Consumo de APIs externas (BCV, proveedores IA) |
| **HTML Scraping** | Symfony DomCrawler | Scraping de tasas de cambio del BCV |
| **Testing** | PHPUnit 11 | Testing de integración y unitario |

---

## 4. Estructura del Proyecto

```
infraestructura-back/
├── app/
│   ├── Console/Commands/        # 5 comandos artisan personalizados
│   ├── DTO/                     # PriceEstimate, ConversionResult
│   ├── Events/                  # NotificationCreated
│   ├── Http/
│   │   ├── Controllers/Api/     # 23 controllers API
│   │   ├── Middleware/           # CheckRole, RefreshSanctumToken, etc.
│   │   ├── Requests/            # 19 Form Requests (validación)
│   │   └── Resources/           # 8 API Resources (serialización)
│   ├── Models/                  # 25 modelos Eloquent
│   ├── Notifications/           # 5 clases + ExpoChannel
│   ├── Observers/               # PriceEstimation, ProjectProposal
│   ├── Providers/               # 5 Service Providers
│   ├── Rules/                   # SsrfSafeUrl, StrongPassword
│   ├── Services/                # 34 services de negocio
│   │   ├── AI/                  # Estrategias y evaluaciones
│   │   │   └── Providers/       # OpenAI, Anthropic, Gemini
│   │   └── ExchangeRate/        # BCV scraper y API
│   └── Support/                 # Roles, CacheVersion, NotificationCatalog
├── config/
│   ├── permissions.php          # Matriz rol × ruta (custom)
│   └── pricing.php              # Config de estimación de precios
├── database/
│   ├── factories/               # Factories para testing
│   ├── migrations/              # 89 migraciones
│   └── seeders/                 # Seeders + diagnósticos
├── docs/                        # Documentación adicional
├── graphify-out/                # Análisis de grafo de código
├── routes/
│   └── api.php                  # 274 líneas, todas las rutas
├── tests/
│   ├── Feature/                 # 51 tests de integración
│   └── Unit/                    # 6 tests unitarios
├── ARQUITECTURA_BACKEND.md      # Doc de arquitectura completa
├── CLAUDE.md                    # Reglas de desarrollo
├── composer.json
├── phpunit.xml
└── start.sh                     # Script de arranque
```

---

## 5. Requisitos

- **PHP** 8.2 o superior
- **MySQL** 5.7+ o 8.0+
- **Composer** (gestor de dependencias PHP)
- **Node.js** 20+ (solo si usas Laravel Sail o Vite)

---

## 6. Instalación

```bash
# 1. Clonar el repositorio
git clone <url-del-repositorio>
cd infraestructura-back

# 2. Instalar dependencias
composer install

# 3. Configurar variables de entorno
cp .env.example .env
php artisan key:generate

# 4. Configurar la base de datos
# Editar .env con tus credenciales de MySQL:
# DB_DATABASE=ivoo_gestion
# DB_USERNAME=root
# DB_PASSWORD=tu_password

# 5. Ejecutar migraciones
php artisan migrate

# 6. Poblar datos iniciales (opcional)
php artisan db:seed

# 7. Arrancar el servidor
bash start.sh
```

El servidor estará disponible en `http://localhost:8000`.

---

## 7. Configuración

### Variables críticas

```env
# URL del frontend (para CORS)
FRONTEND_URL=http://localhost:3000

# Dominio LAN (para desarrollo en red local)
FRONTEND_URL_LAN=http://10.20.16.247:3000

# Sanctum SPA: dominios que usan cookie de sesión
SANCTUM_STATEFUL_DOMAINS=localhost:3000,localhost:8000

# Dominio de cookie de sesión (producción)
SESSION_DOMAIN=.ivoofix.com
SESSION_SECURE_COOKIE=true

# Base de datos
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ivoo_gestion
DB_USERNAME=root
DB_PASSWORD=

# Pusher (WebSocket)
PUSHER_APP_ID=
PUSHER_APP_KEY=
PUSHER_APP_SECRET=
PUSHER_APP_CLUSTER=mt1
```

### Configuración de CORS

El CORS está configurado para permitir orígenes en `FRONTEND_URL` y `FRONTEND_URL_LAN` con credenciales habilitadas. Rutas permitidas: `api/*`, `sanctum/csrf-cookie`, `broadcasting/auth`.

---

## 8. Arrancar el Servidor

### Opción recomendada: `start.sh`

```bash
bash start.sh        # Puerto 8000 (default)
bash start.sh 8080   # Puerto personalizado
```

Este script arranca **dos procesos en paralelo**:
1. `php artisan serve` — Servidor HTTP en el puerto indicado
2. `php artisan schedule:work` — Scheduler de tareas (sync de tasas BCV, limpieza, etc.)

El servidor es accesible desde la red local vía IP del servidor.

### Opción manual

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

> **Nota:** Sin `schedule:work`, las tareas programadas (sincronización de tasas de cambio, limpieza de notificaciones) no se ejecutarán.

---

## 9. API — Contrato con el Frontend

### Endpoints públicos (sin autenticación)

| Método | Endpoint | Propósito |
|--------|----------|-----------|
| `POST` | `/api/login` | Inicio de sesión |
| `POST` | `/api/reset-password` | Solicitud de reset |
| `POST` | `/api/contractors` | Registro público de proveedor |
| `GET` | `/api/public/invitations/{token}` | Info de invitación |
| `POST` | `/api/public/invitations/{token}/proposal` | Envío de propuesta |
| `POST` | `/api/public/invitations/{token}/proposal-image` | Upload de imagen |
| `GET` | `/api/public/currencies` | Monedas activas |
| `GET` | `/api/public/catalog-categories` | Categorías |
| `GET` | `/api/public/catalog-products/search` | Búsqueda de productos |

### Endpoints autenticados (requieren `auth:sanctum`)

| Dominio | Endpoints | Roles permitidos |
|---------|-----------|------------------|
| **Projects** | CRUD + 14 acciones de workflow | Según acción |
| **Documents** | Upload, download, versionado | INFRAESTRUCTURA, CIERRE_DE_OBRA |
| **Proposals** | Agregar, renegociar, remover | ANALISTA |
| **Payments** | Registrar pagos | FINANZAS |
| **AI** | Evaluar propuestas/expedientes | PROCURA, CIERRE_DE_OBRA |
| **Notifications** | Inbox, marcar leído, eliminar | Todos autenticados |
| **Settings** | Configuración de app | SUPERADMIN, ADMIN |
| **Users** | CRUD de usuarios | SUPERADMIN, ADMIN |
| **Contractors** | CRUD de proveedores | SUPERADMIN, ADMIN |
| **Materials** | CRUD de materiales | SUPERADMIN |
| **Currencies** | Monedas y tasas de cambio | SUPERADMIN |
| **Catalog** | Productos y categorías | Según rol |

### Rate limiting

| Endpoint | Límite | Ventana |
|----------|--------|---------|
| API general | 180 req/min | por usuario |
| Público | 10 req/min | por IP |
| Login | 5 intentos | 15 min por email+IP |
| Catálogos | 200 req/min | por usuario |

### Documentación completa de endpoints

Ver `docs-infraestructura/BACKEND/API-REFERENCE.md` para request/response de cada endpoint.

---

## 10. Roles y Permisos

### Control de acceso

El control de roles funciona en **dos capas**:

1. **Middleware `role:`** en routes/api.php — Define qué roles pueden acceder a cada endpoint
2. **`config/permissions.php`** — Define qué rutas frontend puede ver cada rol (consumido por `GET /api/auth/permissions`)

### Los 10 roles

| Rol | Descripción | Acceso |
|-----|-------------|--------|
| `SUPERADMIN` | Acceso total + configuración | Todo |
| `ADMIN` | Gestión administrativa | Todo excepto config de IA |
| `PRESIDENCIA` | Dashboard ejecutivo | Dashboard + catálogos |
| `INFRAESTRUCTURA` | Gestión de proyectos | Proyectos + documentos |
| `CIERRE_DE_OBRA` | Auditoría de expedientes | Revisión + cierre |
| `PROCURA` | Licitación y adjudicación | Procura + catálogos |
| `ANALISTA` | Evaluación de ofertas | Analistas |
| `FINANZAS` | Control financiero | Finanzas |
| `CATALOGOS` | Gestión de catálogos | Catálogos |
| `MARKETING` | Contenido público | Procura + catálogos |

### Ejemplo de uso en controller

```php
// Solo usuarios con estos roles pueden ejecutar esta acción
public function approveInvestment(ApproveInvestmentRequest $request, Project $project)
{
    // El middleware ya validó el rol. Aquí se ejecuta la lógica.
    $project->update(['approved_amount' => $request->validated()['amount']]);
    AuditLog::create([...]);
}
```

---

## 11. Ciclo de Vida del Proyecto

Un proyecto en IVOO recorre **10 estados** desde su creación hasta el pago final:

```
CREADO ──▶ REVISADO_CIERRE ──▶ CONFIRMADO_PROCURA ──▶ COMPARATIVA_ENVIADA
   │              │                      │                      │
   │              ▼                      │                      │
   │       RECHAZADO_CIERRE             │                      │
   │       (regresa a INFRA)            │                      │
   │                                    ▼                      ▼
   │                           CONTRATADO ◀────────────────────┘
   │                               │
   │                               ▼
   │                         EN_EJECUCION
   │                               │
   │                               ▼
   │                    VERIFICANDO_FINALIZACION
   │                               │
   │                               ▼
   │                       LISTO_PAGO_FINAL
   │                               │
   │                               ▼
   └─────────────────────▶ COMPLETADO_PAGADO
```

### Estados y acciones por rol

| Estado | Acciones disponibles | Rol |
|--------|---------------------|-----|
| `CREADO` | Revisar, Rechazar | CIERRE_DE_OBRA |
| `REVISADO_CIERRE` | Aprobar inversión, Rechazar | PROCURA |
| `RECHAZADO_CIERRE` | Reenviar | INFRAESTRUCTURA |
| `CONFIRMADO_PROCURA` | Agregar propuestas, Renegociar | ANALISTA |
| `COMPARATIVA_ENVIADA` | Seleccionar contratista, Rechazar propuestas | PROCURA |
| `CONTRATADO` | Reportar finalización | CIERRE_DE_OBRA |
| `EN_EJECUCION` | Verificar finalización, Pagar | CIERRE_DE_OBRA, FINANZAS |
| `VERIFICANDO_FINALIZACION` | Pagar | FINANZAS |
| `LISTO_PAGO_FINAL` | Pagar | FINANZAS |
| `COMPLETADO_PAGADO` | — (terminal) | — |

### El servicio que lo controla

`ProjectStateMachine` (`app/Services/ProjectStateMachine.php`) es la **fuente única de verdad** para estados y transiciones. Contiene:

- `STATUSES` — Array asociativo de estados válidos
- `STATUS_ORDER` — Orden numérico para ordenamiento
- `assertStatus()` — Valida que un proyecto esté en el estado correcto antes de una acción
- `assertStatusIn()` — Valida múltiples estados posibles

---

## 12. Services de Negocio

Los services encapsulan la lógica de negocio. El controller delega, el service ejecuta.

### Services principales

| Service | Qué hace | Lo usa |
|---------|----------|--------|
| `ProjectStateMachine` | Transiciones de estado, validación | ProjectController |
| `RejectionService` | Flujo de rechazo transaccional (rechazo + rollback materiales) | ProjectController |
| `PriceEstimationService` | Cálculo EST (promedio histórico 6 meses) | 3 controllers |
| `NotificationDispatcher` | Router de notificaciones (in-app, email, push) | ProjectController, AppNotificationController |
| `NotificationRuleResolver` | Resuelve destinatarios por matriz rol × acción | NotificationDispatcher |
| `AIEvaluationService` | Orquestador de evaluaciones IA con fallback | AIEvaluationController |
| `CatalogSyncService` | Sincronización de catálogo desde propuestas | 4 controllers |
| `DashboardSummaryService` | KPIs ejecutivos, funnels, proyectos estancados | DashboardSummaryController |

### Patrón Strategy (Evaluaciones IA)

```
AIEvaluationService
    ├── ProposalEvaluationStrategy  → Evalúa ofertas de proveedores
    └── DossierEvaluationStrategy   → Evalúa expedientes técnicos

Cada strategy usa un provider:
    AIProviderFactory
        ├── OpenAIProvider      (GPT-4o)
        ├── AnthropicProvider   (Claude)
        └── GeminiProvider      (Gemini)
```

Si un provider falla, el factory intenta con el siguiente automáticamente.

### Patrón Observer (Estimación de Precios)

`PriceEstimationObserver` se dispara automáticamente cuando se crea o actualiza una línea de propuesta de proveedor. Calcula el precio estimado basado en el historial y lo almacena en `product_price_history` (append-only).

---

## 13. Sistema de Notificaciones

### Cómo funciona

```
Acción (ej: project.approved)
    │
    ▼
NotificationRuleResolver
    │ Consulta notification_rules
    │ (qué roles reciben qué acción por qué canal)
    ▼
NotificationDispatcher
    ├── In-app  → AppNotification (tabla app_notifications)
    ├── Email   → ProjectActionMail (SMTP)
    └── Push    → ExpoPushService (Expo Push)
```

### La matriz de reglas

La tabla `notification_rules` define qué roles reciben notificaciones para cada acción, por cada canal. Es **configurable por admin** desde el panel de configuración, sin necesidad de modificar código.

### Exclusión del actor

El sistema excluye automáticamente al usuario que ejecutó la acción de los destinatarios. Si Procura aprueba una inversión, Procura no recibe la notificación de aprobación.

---

## 14. Evaluaciones IA

### Configuración

Los proveedores de IA se configuran desde el panel **Configuración → Modelos IA** (`GET/POST/PATCH /api/ai/config`). Cada configuración incluye:

- Provider (OpenAI, Anthropic, Gemini)
- API Key (cifrada en base de datos)
- Modelo específico
- Tokens máximos
- Temperatura

### Evaluación de propuestas

```bash
POST /api/ai/evaluate-proposals
{
  "project_id": "uuid-del-proyecto",
  "provider": "openai"  # opcional, usa el default si no se especifica
}
```

El sistema:
1. Recopila las propuestas del proyecto
2. Selecciona el provider activo
3. Envía el prompt con los datos
4. Registra tokens usados y costo estimado
5. Retorna la evaluación con recomendación y confianza

### Evaluación de expedientes

```bash
POST /api/projects/{id}/evaluate-dossier
```

Similar a la de propuestas, pero enfocada en documentos técnicos y cumplimiento.

---

## 15. Testing

### Ejecutar tests

```bash
# Todos los tests
php artisan test

# Un archivo específico
php artisan test --filter=ProjectControllerTest

# Un método específico
php artisan test --filter=test_user_can_create_project
```

### Configuración de testing

Los tests corren en **SQLite in-memory** (configurado en `phpunit.xml`). No necesitan MySQL para ejecutarse.

### Estructura

```
tests/
├── Feature/Controllers/     # Tests de endpoints API
├── Feature/Services/        # Tests de integración de services
└── Unit/Services/           # Tests unitarios puros
```

### Cobertura actual

| Métrica | Valor |
|---------|-------|
| Archivos de test | 57 |
| Tests pasando | 349/349 |
| Cobertura total | ~65% |

---

## 16. Tareas Programadas

El scheduler (`php artisan schedule:work`) ejecuta:

| Comando | Frecuencia | Qué hace |
|---------|------------|----------|
| `sync:exchange-rates` | Diario 10:00 AM (días laborales) | Sincroniza tasas BCV |
| `prune-old-notifications` | Diario | Limpia notificaciones antiguas |
| `clear-expired-tokens` | Diario | Limpia tokens Sanctum expirados |
| `alert-stale-projects-and-expiring-invitations` | Diario | Alerta de proyectos estancados e invitaciones por vencer |

---

## 17. Documentación

### Documentación del proyecto

| Documento | Ubicación | Contenido |
|-----------|-----------|-----------|
| **CLAUDE.md** | `./CLAUDE.md` | Reglas de desarrollo (leer antes de codear) |
| **ARQUITECTURA_BACKEND.md** | `./ARQUITECTURA_BACKEND.md` | Arquitectura completa (1317 líneas) |
| **API Reference** | `docs-infraestructura/BACKEND/API-REFERENCE.md` | Todos los endpoints request/response |
| **Database Schema** | `docs-infraestructura/BACKEND/DATABASE-SCHEMA.md` | Schema de todas las tablas |
| **Architecture** | `docs-infraestructura/BACKEND/ARCHITECTURE.md` | Arquitectura con diagramas Mermaid |
| **Getting Started** | `docs-infraestructura/BACKEND/GETTING-STARTED.md` | Guía de onboarding |
| **Rules** | `docs-infraestructura/BACKEND/Rules/` | 12 archivos de reglas (Laravel, SOLID, Security, etc.) |
| **Graphify** | `graphify-out/COMPASS.md` | Grafo de código y dependencias |

### Frontend

El frontend está en el repositorio hermano `infraestructura/`. La documentación completa está en `docs-infraestructura/FRONTEND/`.

---

## 18. Contribuir

### Antes de empezar

1. **Leer** `./CLAUDE.md` — Contiene todas las reglas de desarrollo
2. **Consultar** `docs-infraestructura/BACKEND/Rules/` — Reglas específicas por dominio
3. **Verificar** `graphify-out/COMPASS.md` — Si vas a modificar un service, verifica su impacto

### Formato de commits

```
<type>(<scope>): <description>

feat(projects): add project approval workflow
fix(api): handle null contractor_code in pay endpoint
docs: update API reference for all endpoints
refactor(services): extract RejectionService from ProjectController
test(projects): add integration tests for approval flow
```

### Workflow

```bash
# 1. Crear branch
git checkout -b feat/nueva-funcionalidad

# 2. Codear + testear
php artisan test

# 3. Verificar linting
./vendor/bin/pint --test

# 4. Commit
git add .
git commit -m "feat(domain): descripción"

# 5. Push y PR
git push origin feat/nueva-funcionalidad
```

---

## License

Propietario — IVOO. No es software open source.
