# Mejoras Realizadas — Bitácoras de Infraestructura (Agosto 2026)

> Consolidado de todas las bitácoras de seguimiento de NOTION (`Bitacoras de Seguimiento/Infraes-Bitacoras/Agosto 2026/`).
> Fechas cubiertas: **17, 20, 21 y 24-08-2026**.
> Contexto: cierre de FASE 1 y avance de FASE 2 del Plan Maestro 90 Días (`docs/PLAN-MAESTRO-90-DIAS.md`). El proyecto adelantó 2 semanas al completar la Fase 0 antes de lo estimado.

---

## Índice

1. [Resumen ejecutivo](#resumen-ejecutivo)
2. [17-08-2026 — Normalización de estilos y limpieza de vistas de configuración](#17-08-2026)
3. [20-08-2026 — Cierre de Fase 1: rol MARKETING + motor de rechazo](#20-08-2026)
4. [21-08-2026 — Versionado de documentos, flujo de rechazo y wizard de solicitudes](#21-08-2026)
5. [24-08-2026 — Rediseño visual INFRA/CIERRE + eliminación de adjuntos + fixes de previsualizador](#24-08-2026)
6. [Mejoras por área](#mejoras-por-área)

---

## Resumen ejecutivo

| Área | Mejoras principales |
|---|---|
| **Backend** | Versionado de documentos (V1→V2→V3 vía `document_group_id`), flujo de rechazo de solicitudes iniciales (estado `RECHAZADO_CIERRE`) con reenvío bajo el mismo ID, correcciones/observaciones al rechazar (doc tipo CORRECCION + `audit_logs.observations`), endpoint de eliminación de adjuntos con protección de correcciones, condiciones/garantía estructurada de materiales, rol MARKETING con acceso parcial a Procura, `RejectionService` transversal, soft delete en `ProjectProposal`, separación contact → email/phone |
| **Frontend** | Sistema de design tokens (`SEMANTIC_COLOR_MAP`) normalizado en toda la app, wizard de 3 pasos para solicitudes, tabs + KpiPills en INFRA/CIERRE, componentes compartidos nuevos (`TextField`, `SegmentedControl`, `Stepper`, `RequiredMark`, `HelpHint`, `TableTopBar`, `TableToolbar`, `ProjectDocumentsList`, previsualizador PDF/imagen/CSV), secciones de peticiones rechazadas con detalle y reenvío, tablas con fillViewport + affordance de fila clickeable, SkeletonLoader unificado |
| **UX/UI** | Animaciones (stagger, crossfade, spring), indicadores de campo obligatorio, tooltips, fuerza de contraseña, validación en vivo, colores semánticos únicos, accesibilidad corregida (asociación label/campo) |
| **Calidad** | Tests nuevos para vistas sin cobertura (MaterialConfigPanel, ProveedoresConfigPanel), eliminación de código muerto (virtualización sin uso, targetRole vestigial), fixes de bugs reales (fallback rating inconsistente, toast duplicado, CSP bloqueando blob:, apiDownload sin header Accept) |

---

## 17-08-2026

### Incidente inicial
- Inicio a las 8:30 por corrupción del archivo `aria000002` del motor MySQL (resuelto antes de desarrollar).

### Normalización de carta de estilos de la APP (cambio mayor, dividido en 8 PRs)
- **PR1**: Bloques de tokens, paleta de colores semántica, densidad de padding, escalas tipográficas, duraciones de transición; limpieza de estilos sin usar.
- **PR2**: `StatusBadge.tsx` migrado a tokens nivel "Badge/Pill".
- **PR3**: `Cards.tsx` y `KpiCard.tsx` normalizados.
- **PR4**: `SectionHeader.tsx` usa `COLOR_TO_SEMANTIC`.
- **PR5**: `alertStyles.tsx` resuelve success/error/warning desde `SEMANTIC_COLOR_MAP` (decisión deliberada: `info` anclado a rol `brand`/sky para no cambiar el color de "info" en toda la app).
- **PR6**: `Modal.tsx` migrado a `SEMANTIC_COLOR_MAP`.
- **PR7**: `Button.tsx` reducido y migrado a tokens; `colorTokens.ts` ampliado con gradientes; `Table.tsx` con estilos TRUE VALUE.
- **PR8**: Preparación/migración de elementos hardcodeados a variables de color.

### Componentes y vistas
- Nuevo componente `RequiredMark.tsx`: guía UX de campos obligatorios con variantes Warning (inválido) / Check (válido).
- `AuditConfigLogPanel.tsx`: animaciones mejoradas, colapso horizontal.
- `ConfigAppPanel`: limpieza de código muerto (`NotificationMatrix.tsx`), migración de `FieldError`, `SettingRow`, `ActionRuleRow`, `CurrencyCard` a TRUE VALUE, animaciones nuevas.
- `AIConfig`: normalización de paleta, eliminación de notificación duplicada al sincronizar modelo IA, unificación header/tabla de modelos.
- `SkeletonLoader.tsx`: animaciones nuevas de fluidez; adoptado por `ConfigAppPanel`, `AuditConfigLogPanel`, KPI Cards y Table; `PageFallback` reemplazado por skeleton con forma real de vista.
- `ConfigMateriales`: eliminación del sistema de virtualización completo de `Table.tsx` (~80 líneas muertas, dependencia `@tanstack/react-virtual` removida), sanitización manual de precio reemplazada por `NumericInput` (con prop `accent`), migración completa a tokens, crossfade real skeleton→contenido (`AnimatePresence mode="wait"`), filas con stagger, contador Total con efecto spring, validación nueva precio > 0, **suite de tests nueva** (6 tests: carga, filtro, alta, validaciones, toggle).
- `Select` personalizado integrado + animación de `FieldError`.
- `ProveedoresConfigPanel`: colores corregidos a convención del navbar, Card+SectionHeader reemplazan contenedores manuales, SelectModal→Select, tabla reducida de 8 a 4 columnas visibles con nuevo `ContractorDetailModal` para el resto, fix de fallback de rating inconsistente (4.0 vs 0), suite de tests nueva.
- **Separación contacto → Email/Teléfono** como campos reales e independientes en toda la app (Contractor, ContractorsSection, InviteModal, RegistrationForm público, datos semilla) + regla de negocio "email o teléfono, al menos uno" con helper `src/utils/validators.ts`. Backend: columna `contact` separada en email/phone.

---

## 20-08-2026

### Cierre de Fase 1 (prerequisitos para Fase 2)
- **Rol MARKETING** con acceso parcial a Procura: crear/consultar/adjuntar especificaciones, sin aprobar/rechazar/adjudicar/evaluar IA.
- **`RejectionService`**: motor de rechazo genérico y transversal, extraído del flujo embebido en Procura — reutilizable por futuros módulos.
- **`ProjectProposal` pasa a soft delete**: las propuestas rechazadas/retiradas ya no se pierden físicamente; queda trazabilidad para auditoría.

### Vistas de configuración
- `ProveedoresConfigPanel`: normalización y limpieza; `TableTopBar.tsx` como componente compartido (elimina lógica duplicada en tablas de configuración).
- Creación de usuarios migrada a **modal unificado crear/editar** con indicador de fuerza de contraseña y validación en vivo.
- Extracción de `TableToolbar`; `Table` gana soporte `fillViewport` (tablas ajustan su alto al viewport).
- Fix de animaciones de filas al filtrar (eliminado efecto "estela fantasma" por `popLayout` en tablas HTML) + transición de reacomodo suave.
- Auditoría de las 5 vistas de configuración (Usuarios, Proveedores, Materiales, IA, App): colores hardcodeados → `SEMANTIC_COLOR_MAP`, código muerto eliminado, estados de carga unificados bajo SkeletonLoader. Tests de integración y unitarios nuevos.
- `AuditLogConfigPanel`: mejoras al sistema de búsqueda + nuevos métodos de filtrado de logs de configuración.

---

## 21-08-2026

### Backend
- **Versionado de documentos**: cada carga genera una nueva versión (V1, V2, V3…) vinculada por `document_group_id` — los planos/documentos se corrigen sin reemplazar físicamente el archivo anterior, con trazabilidad de quién corrigió qué y cuándo (cumple la regla crítica del plan maestro).
- **Condiciones de material obligatorias**: estado NUEVO/USADO/AMBAS requerido por material; garantía pasa de texto libre a par valor-unidad estructurado (DÍAS/MESES/AÑOS), validable y consultable.
- **Flujo de rechazo de solicitud inicial**: nuevo estado `RECHAZADO_CIERRE`; Cierre de Obra puede rechazar con motivo e Infraestructura edita y reenvía el mismo proyecto (mismo ID, materiales reemplazados, vuelve a `CREADO`) en vez de duplicar. Independiente del flujo de revisión de documentos y del rechazo de cuadros comparativos de Procura.
- **Correcciones y observaciones al rechazar**: nuevo tipo de documento CORRECCION + columna `audit_logs.observations` (separada del motivo); registrada la acción en el catálogo de notificaciones (evita fallback silencioso).

### Frontend
- **Componentes compartidos de documentos**: listado y previsualización inline (PDF e imágenes se ven en la UI, no solo descarga) + historial de versiones por grupo. Implementados en "Documentos revisados" de Cierre de Obra y reutilizados en aprobación de inversiones de Procura (reemplaza lista ad-hoc sin preview).
- **Workflow de versiones/rechazo/reenvío**: `handleUploadDocumentVersion`, `handleRejectProject`, `handleResubmitProject` siguiendo el patrón existente del hook.
- **Wizard de solicitudes (Infraestructura)**: formulario plano de una página → asistente de 3 pasos (datos, materiales, adjuntos) en tarjeta única con altura de viewport. Nuevos componentes reutilizables: `TextField` (etiqueta/error/contador), `SegmentedControl`, `Stepper`, prop `fillHeight` en `Card` (también aplicado a los 3 paneles de configuración que duplicaban el patrón flex-fill).
- Estado de material obligatorio con indicador visible (sin default implícito); marca ámbar sutil en materiales no revisados; garantía como número+unidad; **adjuntos obligatorios** (al menos uno) con misma validación por paso.
- **Sección "Rechazadas" en Infraestructura**: muestra dónde y por qué se devolvió cada solicitud (motivo desde audit log) con acción "Editar y reenviar" que reabre el wizard precargado bajo el mismo ID.
- Cierre de Obra: acción de rechazar con motivo + adjuntar correcciones (CORRECCION) y observaciones opcionales en el mismo modal; botón "Ver petición" en cada card rechazada abre modal de solo lectura (motivo, observaciones, correcciones). Mejoras visuales: `RejectedWarningLabel`, animaciones, ordenamiento por fecha.

---

## 24-08-2026

### Backend
- **Endpoint de eliminación de adjuntos**: accesible a Infraestructura solo mientras la petición está rechazada; las correcciones adjuntas por Cierre de Obra están explícitamente protegidas del borrado. `review()` ya no depende de conteos reportados por el cliente — solo del historial persistido. Tests actualizados en ambos lados.
- **Fix: versionado correcto de adjuntos** según expedientes rechazados.

### Frontend — Rediseño de auditoría en Cierre de Obra
- Paso "Revisar": muestra detalle completo del expediente (tipo, ubicación, fecha, materiales con condición/marca/garantía, contador de adjuntos) en vez de solo descripción y cantidades.
- Paso "Documentación": deja de exigir subir archivos — es revisión pura (preview + descarga) de lo que Infraestructura adjuntó, reusando `ProjectDocumentsList`. Notas de revisión opcionales y sin texto precargado (cierra el bug del placeholder que se guardaba como dato).
- Botón "Rechazar" con énfasis rojo sólido visible en cualquier paso del wizard.

### Frontend — Infraestructura
- Wizard de reenvío de petición rechazada muestra los adjuntos existentes con opción de marcarlos para eliminar (reversible hasta confirmar) — antes quedaban invisibles e imposibles de quitar.
- Correcciones de Cierre de Obra protegidas como histórico (no eliminables ni por UI ni por backend).
- Fix de toast duplicado al reenviar; nombres de grupos de adjuntos unificados entre "cargar" y "ya cargados"; eliminado botón de historial de versiones sin uso en `ProjectDocumentsList`; botones de acción sobre archivos agrandados.

### Frontend — Previsualizador de documentos
- Soporte real para CSV (parser + tabla).
- Fix crítico: ningún formato (PDF, imagen, CSV) cargaba — `apiDownload` no enviaba el header `Accept` esperado por el backend, y el CSP del dev server de Vite bloqueaba las URLs `blob:` usadas para render.

### Frontend — Rediseño visual de Cierre de Obra (tabs + tabla + UI compartida)
- De 3 secciones apiladas a sistema de tabs (Revisión de Cálculos y Planos / Auditoría de Fin de Obra / Documentos ya Revisados), replicando el patrón de Infraestructura: Tabs + TabPanel, KpiPill en vez de KpiCard, sin header redundante. "Flujo de Retornos" migrado a InfoBanner colapsable (un archivo menos).
- Las 3 secciones pasan de tarjetas sueltas al componente Table global (fillViewport + useContainerRows): click en fila abre modal de detalle (wizard de revisión / modal de certificación / lista de documentos).
- Adopción de componentes existentes no usados ahí: Stepper (permite volver a paso visitado), RequiredMark/HelpHint, AlertBanner, Spinner, Tooltip en botones de icono.
- Fix de accesibilidad real: anidar HelpHint/RequiredMark dentro de `<label>` rompía la asociación accesible del campo.
- Colores Tailwind crudos (sky/emerald/rose/amber) → `SEMANTIC_COLOR_MAP` (brand/success/danger/warning) como fuente única de verdad.
- **Table gana affordance global** para filas clickeables: hover marcado, ícono "ver" con transición, soporte de teclado (Enter) — antes el único indicio era el cursor.
- Tests actualizados: mocks de motion/react ampliados, aserciones ajustadas, test de History-button eliminado junto con la feature.

### Frontend — INFRA/CIERRE (visual general)
- Nuevo sistema de tabulado para distribución de elementos.
- Nuevo sistema KPIPills para mostrar información.
- Nuevo componente de animación de tabs.
- Tablas con contenido adaptable según tamaño del elemento.
- Solventados problemas de estilos entre SkeletonLoader y PageFallback.

---

## Mejoras por área

### Backend (consolidado)
| Mejora | Detalle |
|---|---|
| Versionado de documentos | V1→V2→V3 vía `document_group_id`, sin reemplazo físico, trazable |
| Rechazo de solicitud inicial | Estado `RECHAZADO_CIERRE` + reenvío bajo mismo ID (sin duplicados) |
| Correcciones al rechazar | Doc tipo CORRECCION + `audit_logs.observations` + catálogo de notificaciones |
| Eliminación de adjuntos | Endpoint restringido a petición rechazada; correcciones protegidas |
| Materiales | Condición NUEVO/USADO/AMBAS obligatoria; garantía valor-unidad estructurada |
| Rol MARKETING | Acceso parcial a Procura (crear/consultar/adjuntar) |
| RejectionService | Motor de rechazo transversal extraído de Procura |
| Soft delete propuestas | `ProjectProposal` conserva trazabilidad de rechazadas/retiradas |
| Contacto | Separado en email/phone con regla "al menos uno" |

### Frontend (consolidado)
| Mejora | Detalle |
|---|---|
| Design system | `SEMANTIC_COLOR_MAP` + tokens en toda la app (8 PRs); fin de colores Tailwind crudos |
| Wizard de solicitudes | 3 pasos (datos/materiales/adjuntos), tarjeta única, fillHeight |
| Tabs + KpiPills | INFRA y CIERRE rediseñados con patrón compartido |
| Componentes nuevos | TextField, SegmentedControl, Stepper, RequiredMark, HelpHint, TableTopBar, TableToolbar, ContractorDetailModal, ProjectDocumentsList, previsualizador PDF/img/CSV |
| Peticiones rechazadas | Sección dedicada con motivo, correcciones, ver detalle y editar-y-reenviar |
| Tablas | fillViewport, useContainerRows, affordance de fila clickeable (hover/ícono/teclado), animaciones de filtrado corregidas |
| Carga | SkeletonLoader unificado en todas las vistas + PageFallback con forma real |
| Accesibilidad/validación | RequiredMark con variantes, fuerza de contraseña, validación en vivo, fix de asociación label/campo |

### Bugs reales corregidos
1. Placeholder de descripción precargado que se guardaba como dato (Procura/Cierre de Obra) → notas opcionales sin texto precargado.
2. Fallback de rating inconsistente al crear (4.0) vs editar (0) en proveedores.
3. Toast duplicado al reenviar petición.
4. Previsualizador sin cargar ningún formato: header `Accept` faltante en `apiDownload` + CSP de Vite bloqueando `blob:`.
5. Estela fantasma en animación de filas al filtrar (`popLayout` en tablas HTML).
6. Adjuntos invisibles/no eliminables al reenviar petición rechazada.
7. Asociación accesible rota al anidar HelpHint/RequiredMark dentro de `<label>`.
8. Corrupción `aria000002` de MySQL (incidente de arranque, resuelto).
