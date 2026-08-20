# Fase 2 — Auditoría crítica del proceso actual (Infraestructura + Procura + Analistas)

> Auditoría de código real (no de diseño) contra `PLAN-MAESTRO-90-DIAS.md` sección FASE 2 (líneas 103-169), previa al arranque de esa fase. Complementa (no reemplaza) `FASE0-AUDITORIA-TECNICA.md` — Fase 0 auditó el **modelo de datos**; este documento audita el **proceso/flujo real** que los tres roles ejecutan hoy en frontend + backend.
>
> Fecha: 2026-08-20. Repos: `infraestructura-back` (Laravel) + `infraestructura` (SPA React/TS).
> Documento interno de trabajo. No se distribuye vía git (ver `.gitignore`).

---

## Resumen ejecutivo

El proceso operativo hoy **funciona de punta a punta** (una petición sí llega de Infraestructura a Procura a Analistas y de vuelta), pero está **fragmentado entre módulos que no se hablan** y tiene **tres brechas que rompen directamente criterios de aceptación explícitos** del plan, no solo gaps de "feature nueva":

1. **No existe formulario unificado.** El registro de una solicitud en Infraestructura no incluye planos, fotos ni presupuesto detallado — esos viven en Cierre de Obra, un módulo distinto, en un paso posterior desconectado del alta.
2. **Selección de materiales sigue siendo de uno en uno en la UI**, aunque el backend ya soporta N materiales por proyecto — el plan lo da por resuelto (`FASE0-AUDITORIA-TECNICA.md:60`) pero solo a nivel de esquema, no de experiencia real del usuario.
3. **El placeholder de "Descripción" en Procura no es un placeholder** — es texto real precargado en el campo que se guarda como dato si el usuario no lo borra, exactamente el bug que el plan pide corregir explícitamente (línea 143 del plan maestro).
4. **El rechazo en Procura es todo-o-nada**, no un ciclo de corrección: al rechazar se borran todas las propuestas y el proyecto vuelve al estado anterior completo. No hay estados intermedios `Requiere corrección`/`Corregido` ni motor de rechazo transversal (confirma G6 de Fase 0).
5. **El bloqueo de anticipo máximo es un soft-warning, no un bloqueo duro** — el usuario puede confirmar y enviar igual aunque exceda el máximo configurado, contradiciendo el criterio de aceptación textual del plan ("bloquea el envío").
6. **"Identificación de ofertas" (origen + fecha editable) no tiene ni una columna en BD** — 100% pendiente, sin trabajo previo que lo adelante.

Nada de esto es un defecto oculto: cada punto tiene una causa concreta y acotada, y ninguno requiere replantear el modelo de datos (eso ya se resolvió en Fase 0). Son ajustes de flujo y UI, la mayoría de bajo-medio esfuerzo.

---

## 1. Infraestructura / Mantenimiento

**Vista:** `src/views/InfraestructuraMantenimientoPanel/index.tsx` — orquesta `RequestFormSection`, `MaterialAdderSection`, `RequestsTableSection`. Un único POST crea el `Project` completo (sin wizard de pasos).

| Requisito del plan | Estado real | Evidencia |
|---|---|---|
| Formulario unificado (solicitud + productos + fotos + documentos + planos + presupuesto) | **No existe.** Solo cubre título, ubicación, tipo, descripción y materiales. Planos/documentos se suben después, en `CierreObraPanel` — módulo distinto, paso desconectado del alta. | `RequestFormSection.tsx:88-206`, `MaterialAdderSection.tsx` |
| Selección múltiple de materiales con cantidad individual | **Solo en el backend.** El modal (`SelectModal`) es de selección única (`allowDeselect={false}`): un material → una cantidad → "Agregar" → repetir. El backend sí acepta N materiales por proyecto (loop), pero la UI no expone multi-selección en un solo paso. | `MaterialAdderSection.tsx:194-220,79-107,269-292`; backend `ProjectController.php:77-80` |
| Tabla de peticiones — solo total ESTIMADO | **Ya cumplido.** Columnas: ID, Título/Ubicación, Tipo, Estado, Fecha, Total (Est), Acciones — ya está limpia. | `RequestsTableSection.tsx:58-127` |
| ToastError por campos faltantes | **Ya cumplido**, y bien hecho: validación real con `FieldError`/`role="alert"`, foco automático en el primer campo inválido, `useToast()` para éxito/error — no hay `alert()` ni fallos silenciosos. | `index.tsx:74-91,104`; `RequestFormSection.tsx:50-58,74-77`; `MaterialAdderSection.tsx:87-90` |
| Características del producto (nuevo/usado, garantía, marca, modelo, especificaciones, observaciones) | **No existe, ni en backend ni en frontend.** `ProjectMaterial` solo tiene `name`, `quantity`, `unit`, `estimated_unit_price`, `material_catalog_id`. | `ProjectMaterial.php:17-25` |
| Planos: carga + previsualizador + versionado V1→V2→V3 + estado/revisión propio | **No existe nada de esto.** `ProjectDocument` solo distingue `document_type` (`CALC`\|`PLANO`) — sin versión, sin relación padre/hijo, sin estado de revisión propio de plano (se trata como cualquier documento genérico). Además, `FileDropZone` (el único componente de carga de archivos) **no está montado en Infraestructura** — vive en `CierreObraPanel/components/TechnicalReviewSection.tsx`. Infraestructura ni siquiera tiene UI propia para subir planos. | `ProjectDocument.php`, `StoreProjectDocumentRequest.php:46`; `FileDropZone.tsx` (uso confirmado solo en Cierre de Obra) |
| Estados / rol de Infraestructura en el flujo | Enum completo: `CREADO → REVISADO_CIERRE → CONFIRMADO_PROCURA → COMPARATIVA_ENVIADA → CONTRATADO → EN_EJECUCION → VERIFICANDO_FINALIZACION → LISTO_PAGO_FINAL → COMPLETADO_PAGADO`. Infraestructura solo dispara la transición inicial (crea en `CREADO`); no tiene ninguna acción de frontend para estados posteriores ni visibilidad de por qué una petición se estanca. | `ProjectController.php:27-37,73`; KPI "Por Revisar" solo cuenta `CREADO` (`index.tsx:62`) |

**Lectura crítica:** el proceso de Infraestructura hoy está partido en tres módulos que no se comunican en la UI (Infraestructura crea → Cierre de Obra sube planos y revisa → Procura cotiza), sin que el usuario de Infraestructura tenga visibilidad de qué pasa después de crear la solicitud. El plan pide "formulario unificado" precisamente para resolver esta fragmentación — hoy no lo resuelve, la mantiene.

---

## 2. Procura

**Vista:** `src/views/ProcuraPanel/index.tsx` — dos subcomponentes: `InvestmentApprovalSection` (wizard de autorización de inversión) y `BidEvaluationSection` (evaluación comparativa/adjudicación). No hay pantalla de "expedientes" separada.

| Requisito del plan | Estado real | Evidencia |
|---|---|---|
| Fix Descripción (placeholder real, no texto que se guarda como dato) | **Confirmado el bug tal cual lo describe el plan.** El campo `procuraNotes` se precarga con una nota real (`"Presupuesto aprobado de $X para licitación directa..."`) al abrir el wizard — si el usuario no la borra, se guarda literal como `procura_review_notes`. El único `placeholder` HTML real (`"Ej. Proyecto urgente..."`) queda tapado por ese `value` precargado. | `InvestmentApprovalSection.tsx:135,355` |
| Flujo de expedientes: `En revisión → Aprobado` / `→ Requiere corrección → Corregido → Revisión` | **No existen esos estados.** Backend solo define `REVISADO_CIERRE → CONFIRMADO_PROCURA → COMPARATIVA_ENVIADA → CONTRATADO` para este tramo. El "rechazo" actual (`rejectProposals`) borra **todas** las propuestas y regresa el proyecto a `CONFIRMADO_PROCURA` — reinicio total, no corrección incremental. | `ProjectController.php:29-32,200-219` |
| Motor de rechazo (motivo, observaciones, corrección, responsable, fecha, evidencia) | **Solo tiene motivo.** El modal de `BidEvaluationSection.tsx:332-379` pide únicamente un `textarea` de motivo (obligatorio, máx. 500 caracteres) — sin observaciones separadas, sin campo de qué corregir, sin responsable, sin fecha límite, sin adjuntos. Se persiste como texto libre en `AuditLog`, sin catálogo ni motor reutilizable. Confirma G6 de Fase 0. | `BidEvaluationSection.tsx:332-379` |
| Cotizaciones: adjuntar PDF/Excel/imágenes trazables a producto+proveedor+fecha+precio+moneda | **No existe.** `ProjectDocument` cuelga genéricamente del proyecto (solo `PLANO`/`CALC`), sin relación a una cotización. `SupplierMaterialProposal.items` es JSON no consultable por SQL (confirma G1 de Fase 0) y no tiene relación con `ProjectDocument` — no hay forma de adjuntar un archivo a una cotización específica hoy. | `ProjectDocument.php:9-17`; `SupplierMaterialProposal.php:20-34` |
| Análisis de expedientes por IA — robustecer | **Ya razonablemente estructurado**, pero evalúa solo la comparativa de ofertas (costos, semanas, % anticipo, rating del contratista), no expedientes completos (no procesa documentos/planos adjuntos). Tiene DTOs tipados, validación estricta, failover entre proveedores, y auditoría del resultado. Punto de partida sólido para "robustecer", no hay que rehacerlo. | `AIEvaluationController.php` (+`AIEvaluationService`, líneas 129-150) |
| Marketing como departamento autorizado en Procura | **Confirmado ausente**, como se esperaba (aún no es tarea de esta fase de por sí, depende de Fase 1 / `role_permissions`). `VALID_ROLES` no incluye `MARKETING`. | `app/Support/Roles.php:16-19` |

**Lectura crítica:** el hallazgo del placeholder es el más barato de arreglar de todo el documento (<1 día, como ya estimaba el plan) y el de mayor relación costo/beneficio — hoy literalmente se está guardando basura en producción cada vez que un usuario no borra el texto precargado. El rechazo todo-o-nada es más estructural: corregirlo bien requiere el motor transversal de Fase 1 (1.3), no un parche local en `BidEvaluationSection`.

---

## 3. Analistas

**Vista:** `src/views/AnalistasPanel/index.tsx` — `BidRegistrationSection` (carga manual de ofertas) + `ComparativeTableSection` (comparativo, envío a Procura, import de ofertas de portal proveedor).

| Requisito del plan | Estado real | Evidencia |
|---|---|---|
| Identificación de ofertas (origen: RENEGOCIACION / SEED-INSERT / PORTAL-PROV) | **100% pendiente.** Ni el formulario de registro manual (`BidRegistrationSection.tsx:93-103`) ni el modelo `ProjectProposal` (`$fillable`, líneas 18-29) tienen campo `source`/`origen`. La tabla `supplier_material_quotes` propuesta en Fase 0 para resolver esto no tiene migración creada — sigue siendo solo diseño documentado. | `BidRegistrationSection.tsx:93-103`; `ProjectProposal.php:18-29`; `FASE0-AUDITORIA-TECNICA.md:74-88` |
| Fecha de la oferta (obligatoria) | **No existe como campo editable.** La única fecha es `created_at` (timestamp de inserción automático), no una "fecha de la oferta" que el analista pueda declarar (relevante si carga una oferta días después de recibida). | `2026_06_30_000005_create_project_proposals_table.php:22` |
| Anticipo: campo numérico/porcentaje, no `<select>` | **Ya cumplido.** Ya es `NumericInput` con `min=0 max=100`, no un dropdown — el plan describe un estado que ya fue superado. | `BidRegistrationSection.tsx:246-265` |
| Anticipo > máximo bloquea el envío (🔴 ToastAlertAction) | **Parcialmente cumplido — brecha real.** Sí valida contra CONFIG vía `useMaxAdvancePercent()` (lee `presupuesto.anticipo_maximo_porcentaje`), y sí muestra advertencia. Pero al exceder el máximo abre un `ConfirmDialog` de "¿está seguro?" que **permite continuar y enviar de todos modos** — no es el bloqueo duro que pide el criterio de aceptación textual del plan. | `BidRegistrationSection.tsx:46,259-264,312-328`; hook `useMaxAdvancePercent.ts:10-24` |

**Lectura crítica:** de los dos ítems presupuestados para Analistas en el plan, "Anticipos" está mayormente resuelto — el trabajo real que queda es small (cambiar confirm-y-continuar por bloqueo real), y "Identificación de ofertas" es la única pieza de esta fase que requiere una migración nueva desde cero (la tabla `supplier_material_quotes` de Fase 0, sección 3.2), tal como ya estaba estimado.

---

## Conclusión: ¿arranca la Fase 2 tal como está estimada?

**Sí**, con dos matices que no cambian el orden de magnitud de las estimaciones pero sí deben quedar explícitos como tareas dentro de los ítems ya presupuestados (no como trabajo nuevo aparte):

1. **El "formulario unificado" de Infraestructura no es una limpieza de UI — es una reestructuración real** que debe decidir si absorbe la carga de planos/documentos hoy en Cierre de Obra, o si se mantiene la separación de módulos pero se muestra en la misma pantalla. Esto afecta directamente el ítem de Planos (5-7 días, ya marcado en el plan como el más grande de la fase) — conviene decidir el alcance de esa fusión **antes** de tocar planos, no en paralelo.
2. **El bloqueo duro de anticipo (Analistas)** y **el fix del placeholder (Procura)** son ambos de esfuerzo trivial (<1 día cada uno) y deberían resolverse primero, al arrancar el sprint — desbloquean rápido sin arrastrar dependencias, y el placeholder en particular es una corrupción de datos activa en producción hoy mismo, no solo deuda técnica.

Ningún hallazgo de este documento contradice la validación de Fase 0 de que la cadena operativa "puede sostenerse sin romper nada existente" — todos los gaps aquí son de **proceso/UI**, no de modelo de datos, y el modelo ya fue validado como suficiente (con las extensiones aditivas ya identificadas: `supplier_material_quotes`, campos de características, versión de documento).
