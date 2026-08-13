# Plan de Trabajo Completo — Gestión IVoo


**Formato:** Por fase → tareas con estimación, criterio de aceptación y dependencias.
**Regla general:** No hay rediseños. Todo cambio se valida contra el modelo actual antes de tocar flujos en producción. Todo lo configurable (montos, límites, correos, pesos) va a CONFIG, nunca hardcodeado.

---

## Resumen ejecutivo

| Fase | Semanas | Entregable | Prioridad |
|---|---|---|---|
| 0 | 1 | Revisión técnica y preparación | — |
| 1 | 2–3 | Funciones transversales (notificaciones, rechazo, CONFIG, UX) | P0 |
| 2 | 4–6 | Infraestructura + Procura + Analistas | P0 |
| 3 | 7–8 | Proveedores + Finanzas + Presupuesto | P0 |
| 4 | 9–10 | Presidencia + Históricos + Auditoría | P1 |
| 5 | 11–13 | Datos + IA + Ratings inteligentes | P2 |
| 6 | 14 | QA integral y estabilización | — |

**Hitos:**
- Día 30: mejoras operativas principales (P0) funcionando.
- Día 60: control financiero, proveedores, históricos y Presidencia.
- Día 90: analítica, ratings e IA.
- Semana 14: cierre y estabilización.

**Fuera de este roadmap:** CHATROOM (Fase II independiente, no bloquea nada de lo anterior).

---

## FASE 0 — Preparación (Semana 1, 3–5 días)

**Objetivo:** Entender el modelo actual antes de extenderlo. Cero código nuevo esta fase.

### Tareas
- Auditar modelos actuales: solicitudes, productos/materiales, proveedores, cotizaciones/ofertas, adjudicaciones, pagos, obras, usuarios/roles, AuditLogs.
- Mapear qué tablas necesitan extensión vs. relaciones nuevas.
- Validar que puede sostenerse la cadena completa:
  `Solicitud → Productos → Cotizaciones → Proveedor → Oferta → Adjudicación → Pago → Obra → Cierre`
- Validar la cadena de trazabilidad de precios:
  `Producto → Proveedor → Precio → Fecha → Cotización → Proyecto`

### Criterio de aceptación
Documento técnico (diagrama ER o similar) que muestre el modelo actual, los gaps identificados, y la propuesta de extensión. Sin este documento no arranca la Fase 1.

### Por qué importa
Estas dos cadenas son la base de todo lo que viene después: históricos (Fase 4) e IA (Fase 5) no funcionan si la trazabilidad no quedó bien modelada desde aquí.

---

## FASE 1 — Funciones transversales (Semanas 2–3)

**Objetivo:** Construir una sola vez lo que se va a reutilizar en toda la app. Varias tareas pueden ir en paralelo si hay más de un desarrollador.

### 1.1 Sistema unificado de notificaciones (3–4 días)
Servicio central: Toast + alertas internas + correo + push (en tiempo real dentro del flujo activo, no como job en segundo plano).

Tipos: información, éxito, advertencia, error, acción requerida, prioritario.

**Aceptación:** Un solo punto de entrada (`notify()` o similar) usado por todos los módulos. Ningún módulo dispara notificaciones por su cuenta.

### 1.2 ToastAlertAction (1–2 días)
Componente reutilizable: tipo, mensaje, acción/link, prioridad, rol destino.

**Aceptación:** Un solo componente cubre todos los casos de toast de la app (reemplaza implementaciones ad-hoc existentes).

**Dependencia:** Se apoya en 1.1.

### 1.3 Sistema general de rechazo (3–4 días)
Componente transversal con: motivo, observaciones, correcciones requeridas, responsable, fecha, evidencia (cuando aplique).

Estados: 🟢 Aprobado / 🟡 En revisión / 🟠 Requiere corrección / 🔴 Rechazado.

Debe funcionar para: expedientes, solicitudes, planos, obras, cotizaciones, y procesos futuros.

**Aceptación:** Un solo motor de rechazo parametrizable por módulo (no una implementación distinta por cada uno). Dispara notificación automática vía 1.1.

### 1.4 Configuración general — CONFIG APP (3–4 días)
Debe soportar, editable desde administración sin deploy:
- Monedas y tasas de conversión.
- Anticipo máximo.
- Límites presupuestarios (umbrales de semáforo).
- Ratings mínimos y escalas de rating.
- Correos por departamento.
- Datos fiscales de la empresa.
- Parámetros de alertas de precio.
- Parámetros de inflación.

**Aceptación:** Cambiar cualquiera de estos valores desde el panel de administración se refleja en la app sin tocar código.

### 1.5 UX general (2–3 días)
Aplicar transversalmente: tooltips en iconos, indicador de campos obligatorios, ToastError por datos faltantes, toast de confirmación, feedback visual post-acción, colores de estado homogéneos (mismos colores que 1.3 en toda la app).

**Aceptación:** Checklist de UX aplicado en al menos las pantallas principales de cada módulo (no solo una).

---

## FASE 2 — Infraestructura + Procura + Analistas (Semanas 4–6)

**Objetivo:** Fase operativa más pesada. Aquí se sienten los cambios en el día a día del usuario.

### Infraestructura / Mantenimiento

**Formulario unificado** — un solo registro para: solicitud, descripción, productos/materiales, cantidades, características, fotos, documentos, planos, presupuesto estimado.
*Sin estimación propia — se arma component izando lo de abajo.*

**Selección múltiple de materiales (2–3 días)**
Modal evoluciona para permitir múltiples productos + cantidad individual por solicitud.
**Aceptación:** Una solicitud puede tener N productos, cada uno con su cantidad, y queda así en base de datos (no un producto por solicitud).

**Características del producto (1–2 días)**
Agregar campo "Características" + nuevo/usado, garantía, marca, modelo, especificaciones, observaciones.
**Aceptación:** Estos campos son visibles y editables en el formulario unificado y quedan asociados al producto, no a la solicitud genérica.

**Planos (5–7 días)**
Flujo: Cargar → Visualizar → Revisar → Corregir → Aprobar/Rechazar.
Incluye: carga, previsualizador, estado, versión, observaciones, correcciones, responsable, fecha.

⚠️ **Regla crítica: no reemplazar físicamente planos corregidos.** Mantener versionado `V1 → V2 → V3` para trazabilidad completa.

**Aceptación:** Subir una corrección de plano no borra la versión anterior; ambas quedan consultables con su estado e historial de quién corrigió qué y cuándo. Usa el motor de rechazo (1.3).

**Dependencia:** 1.3 (rechazo) y 1.1 (notificaciones).

### PROCURA

**Fix Descripción (< 1 día)**
Usar placeholder real en vez de texto de ejemplo que se guarda como dato.

**Expedientes (2–3 días adicionales al motor transversal)**
Flujo: `En revisión → Aprobado` o `En revisión → Requiere corrección → Corregido → Revisión`.
Usa el motor general de rechazo (1.3): motivo, observación, corrección, notificación automática.

**Cotizaciones (3–4 días)**
Adjuntar PDF, Excel, imágenes, documentos. Cada documento relacionado con: solicitud, proveedor, fecha, producto, cantidad, precio unitario, total, moneda, condiciones, observaciones.
**Aceptación:** Cada archivo adjunto queda trazable hasta el producto y proveedor específico (no solo colgado de la solicitud genérica) — esto es lo que después alimenta el histórico de precios (Fase 5).

**Marketing dentro de Procura (1–2 días)**
Agregar Marketing como departamento autorizado: crear solicitudes, adjuntar especificaciones/referencias, consultar estatus, ver adjudicaciones/presupuesto/gasto final/histórico.
**Aceptación:** Depende de que el sistema de permisos actual soporte roles por departamento sin refactor grande; si no, este ítem crece de estimación — validar en Fase 0.

### ANALISTAS

**Identificación de ofertas (2–3 días)**
Cada oferta manual registra su origen: `RENEGOCIACION`, `SEED-INSERT`, `PORTAL-PROV`. Obligatorio: fecha de la oferta. Recomendado: creado por, precio anterior, precio nuevo, diferencia, motivo (si renegociación).
**Aceptación:** Se puede calcular cuánto dinero se ahorró vía renegociación filtrando por origen — esto habilita analítica futura (Fase 5).

**Anticipos (1–2 días)**
Cambiar de `<select>` a campo numérico/porcentaje. Validar contra `anticipo_maximo` (CONFIG).
**Aceptación:** Anticipo > máximo permitido bloquea el envío con mensaje 🔴 vía ToastAlertAction.

---

## FASE 3 — Proveedores + Finanzas + Presupuesto (Semanas 7–8)

### PROVEEDORES

**Propuestas por producto (3–4 días)**
Registrar por producto: imagen, estado, moneda, observaciones, características, garantía, precio, cantidad + datos generales del contrato/propuesta.

**Tabla de propuestas mejorada**
Columnas: Producto | Características | Cant. | Moneda | Precio | Histórico | Variación.
**Aceptación:** La columna "Histórico" y "Variación" se calculan automáticamente comparando contra el precio anterior del mismo producto/proveedor (requiere que 2.x de Cotizaciones ya esté trazando bien los datos).

**Histórico de proveedores (4–5 días)**
Vista consolidada: razón social, RIF, contactos, categorías, especialidades + histórico de productos, servicios, cotizaciones, precios, adjudicaciones, proyectos, pagos, documentos, ratings.
**Aceptación:** Desde la ficha de un proveedor se puede reconstruir toda su relación con la empresa sin consultar otra pantalla.

### Control presupuestario (4–5 días total)

Por proyecto/solicitud: estimado, aprobado, adjudicado, pagado, gasto final, diferencia, variación %.

**Semáforo presupuestario** (umbrales desde CONFIG, no hardcode):
🟢 hasta 80% · 🟡 81–95% · 🟠 96–100% · 🔴 >100%

**Topes mensuales por departamento** — ejemplo: presupuesto, ejecutado, disponible, % utilizado con semáforo.

**Aceptación:** Cambiar los umbrales del semáforo en CONFIG cambia el color en toda la app sin deploy.

### Finanzas (2–3 días)

Todo movimiento contable requiere comprobante. Registrar: tipo de pago, banco, fecha, referencia, monto, observación, comprobante.
Tipos: anticipo, pago parcial, pago final, finiquito.

**Notificación automática de pago (2 días, usando motor de Fase 1)**
Al confirmar pago → comprobante → notificación a: Procura, proveedor, cierre de obra, auditoría externa (destinatario configurable, no hardcode), área involucrada.
Incluye: solicitud, adjudicación, proveedor, monto, fecha, referencia, comprobante, RIF, link.

**Aceptación:** Ningún pago se puede confirmar sin comprobante adjunto. La notificación llega a todos los destinatarios configurados sin intervención manual.

---

## FASE 4 — Presidencia + Históricos + Auditoría (Semanas 9–10)

### Presidencia

**Salud del Pipeline (3–4 días)**
Vista ejecutiva: proyectos activos, en proceso, en riesgo, retrasados, pendientes de aprobación, terminados, rechazados, % de avance.
**Aceptación:** Presidencia puede responder sin pedir ayuda a IT: ¿qué proyectos tenemos? ¿cuál está retrasado? ¿cuál excede presupuesto? ¿cuál requiere mi atención?

**Flujo de caja mensual (2–3 días)**
Card: pagado, comprometido, pendiente, proyectado (mes actual) + comparación vs. mes anterior, promedio, variación.

**AuditLogs Presidencia (3–4 días)**
Vista propia con filtros (usuario, departamento, módulo, acción, fecha, registro) sobre eventos: creación, envío, modificación, aprobación, rechazo, pago, cambio de estado, eliminación.
**Aceptación:** Cada entrada muestra quién → qué hizo → cuándo → sobre qué → valor anterior → valor nuevo.

### Históricos (4–5 días)

**Histórico de productos:** fecha, producto, proveedor, cantidad, precio, moneda, proyecto + cálculo de último precio, mínimo, máximo, promedio, variación, % de cambio.

**Histórico de obras:** obra → presupuesto → solicitudes → proveedores → adjudicaciones → pagos → planos → cierre, mostrando estimado vs. aprobado vs. ejecutado.

**Aceptación:** Ambas vistas se alimentan de datos ya trazados en Fases 2 y 3 (cotizaciones, propuestas, pagos) — si esos datos no quedaron bien relacionados, este punto se retrasa. Validar antes de empezar.

**Dependencia crítica:** Toda esta fase depende de que Fase 0 haya modelado bien la cadena de trazabilidad.

---

## FASE 5 — Datos, Ratings e Inteligencia Artificial (Semanas 11–13)

**Regla:** No arranca hasta que la estructura histórica (Fase 4) esté generando datos reales, no de prueba.

### 1. Análisis de precios
Vista de inflación y comportamiento por producto: histórico, media, último precio, variación mensual/anual, proveedor, inflación de referencia.

### 2. Estimación de precios (IA)
Analiza histórico interno, cotizaciones, proveedor, producto, cantidad, fecha, tendencia, inflación, referencias externas.
Salida: cotizado vs. promedio histórico vs. estimado vs. variación %.

### 3. Alertas de precio
🔴 Precio fuera de rango (umbral configurable desde CONFIG, ej. 25% sobre promedio).

### 4. Rating inteligente de proveedores
Puntuación basada en datos (no IA generativa aún):

| Factor | Peso |
|---|---|
| Precio | 25% |
| Cumplimiento | 25% |
| Calidad | 20% |
| Tiempo de entrega | 15% |
| Documentación | 10% |
| Incidencias | 5% |

Salida: `Proveedor ABC: 87/100 🟢`. La explicación textual vía IA generativa es un paso posterior, opcional.

### 5. Ratings internos
Extender el mismo motor de pesos (CONFIG Rating) a analistas y auditores/cierre de obra.

### 6. Análisis inteligente de expedientes
Estructura de semáforos por factor (documentación, precio vs. histórico, rating proveedor, garantía, anticipo) en vez de una conclusión genérica. La IA explica, no decide.

### 7. Recomendación de proveedor
Analiza especialidad, rating, precios, historial, cumplimiento, entregas, garantías, experiencia. Salida: **sugerencia**, nunca adjudicación automática.

### 8. Referencias web
Prioridad técnica: **API → fuente pública estructurada → scraping** (scraping como último recurso, nunca única fuente, por fragilidad ante cambios externos).

**Aceptación general de la fase:** Todos los umbrales, pesos y parámetros salen de CONFIG. Ninguna salida de IA ejecuta una acción por sí sola (adjudicar, rechazar, pagar) — todo queda como sugerencia visible para que un humano decida.

---

## FASE 6 — QA y estabilización (Semana 14)

**Objetivo:** Probar de punta a punta antes de cerrar.

### Flujo completo a validar
`Solicitud → Cotización → Negociación → Adjudicación → Pago → Obra → Cierre`

### Checklist
- Roles y permisos.
- Rechazos (todos los módulos que lo usan).
- Toasts, push, correos.
- Archivos y planos (versionado).
- Comprobantes.
- Presupuestos y semáforos.
- Históricos.
- Ratings.
- AuditLogs.
- Salidas de IA (ninguna ejecuta acciones automáticas).
- Responsive.
- Rendimiento bajo carga real de datos (no solo datos de prueba).

**Aceptación:** Ningún hallazgo crítico o bloqueante abierto al cierre de la semana.

---

## Priorización (para planificar sprints)

**P0 — primero, sin esto no hay operación mejorada:**
notificaciones, rechazo, configuración, formularios, multiproducto, cotizaciones, proveedores, pagos, comprobantes, presupuesto.

**P1 — control y trazabilidad:**
planos, históricos, AuditLogs, pipeline, flujo de caja, obras, dashboards.

**P2 — inteligencia:**
inflación, rating inteligente, análisis de expedientes, estimaciones, recomendación de proveedores.

---

## Cronograma resumido

| Semana | Entregable |
|---|---|
| 1 | Evaluación técnica |
| 2 | Notificaciones + Toasts |
| 3 | Rechazos + CONFIG + UX |
| 4 | Infraestructura unificada |
| 5 | Planos + multiproducto |
| 6 | Procura + Analistas + Marketing |
| 7 | Proveedores + cotizaciones |
| 8 | Finanzas + presupuestos |
| 9 | Presidencia + Pipeline |
| 10 | AuditLogs + históricos |
| 11 | Análisis de precios |
| 12 | Ratings + IA de precios |
| 13 | IA proveedores + expedientes |
| 14 | QA + estabilización |

---

## Riesgos a vigilar

1. **Fase 0 mal hecha rompe Fase 4 y 5.** Si la trazabilidad producto→proveedor→precio→fecha no queda bien modelada desde la semana 1, los históricos y la IA se retrasan o salen con datos incompletos.
2. **Permisos de Marketing (2.x)** puede crecer de estimación si el sistema de roles actual no es granular por departamento — validarlo en Fase 0, no en Fase 2.
3. **Planos (5–7 días)** es el ítem más grande de la Fase 2 — si se retrasa, arrastra el resto de Infraestructura. Considerar arrancarlo primero dentro de la fase.
4. **Scraping como fuente de precios externos (Fase 5)** es frágil por diseño — no comprometer fecha de entrega a la disponibilidad de un sitio de terceros.
