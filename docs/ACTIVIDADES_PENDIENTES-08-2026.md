--- PRESIDENCIA ----
* Salud del Pipeline -> ¿Que proyectos estas 
* Flujo de caja mensual -> mejorar Card
* Realizar integracion para analisis de datos por TF o IA API
* Sistema de notificaciones PUSH (No en segundo plano)
* ToastAlertAction variante ( Alertas para todo lo pertinente del y para el rol )
* Mejorar sistema de AuditLogs -> Deberia tener su propia vista de consulta para el rol de presidencia dando todo los detalles relacionados sobre cada accion, Send, Modificacion, Aceptado de procesos o rechazo del mismo.

--- INFRAESTRUCTURA / MATENIMIENTO -- 
* Formulario de registro UNIFICADO
* La tabla de peticiones debe tener total ESTIMADO nada mas
* El modal de seleccion debe permitir seleccionar uno o mas materiales y adjuntar la cantidad de cada uno
* Al faltar campos lanzar un TOASTERROR
* En este paso debe adjuntar LOS PLANOS (Evaluacion de este cambio de proceso en el flujo)

---- CIERRE DE OBRA ---
* Mejorar visualizacion de los cuadros de la seccion

---- PROCURA --- 
* Descripcion Cambiante (Dejar es un placeholder para la descripcion y evitar errores)
* Flujo de RECHAZO de expedientes con NOTIFICACIONES
* Adjuntar campo de OBSERVACIONES, CORRECCIONES y MOTIVO (Para el rechazo)
* Integrar flujo de analisis de expedientes por Datos
* La evaluacion por IA debe ser mas robusta, eficiente, estructurada y con mas datos de analisis para las conclusiones

--- ANALISTAS ---
* El registro de ofertas manuales debera poseer estatus de identificacion para reconocer cuando fue 'RENEGOCIACION' / 'SEED-INSERT' / 'PORTAL-PROV'
* Cada una de las ofertas debe tener la fecha de cuando se realizo la misma
* Debe existir un maxio de anticipo configurable
* El campo anticipo NO DEBE SER UN SELECT

--- PROVEEDORES ---
* Deben adjuntar IMAGENES, ESTADO, MONEDA y OBSERVACIONES de cada PRD y del contrato en general
* Mas datos y mejor diseño para las propuestas recibidas (Tabla)
* Rating  Evaluado por datos e IA para los proveedores (Rating Inteligente)

--- CONFIGURACION --- 
* CONFIG monedas + mas tasas para conversion  via WebScrapping (Propuesta)
* CONFIG Rating usuarios + Proveedores
* CONFIG APP para evitar programar cambios de aplicacion

----> GENERALES <----
* Sistema de Rating inteligente (Basado en actividad y datos) para PROVEEDORES, ANALISTAS, AUDITORES (Cierre de Obra)
* Para todo lo que sean los pagos y movimientos contables adjuntar comprobantes de pago OBLIGATORIAMENTE
* Hint Tip para iconos de ayuda y de indicacon de campos obligatorios
* NUEVA VISTA - Inflacion y Analisis de datos de productos SEGUN el historico de productos construido en base a las peticiones de los Proveedores
* Nueva columa - Caracteristicas del Producto
* El sistema de RECHAZO debe priorizar lo VISUAL (Colores demostrativos) y tener observaciones, correcciones y motivo
* Cuando se realize un pago debera enviarse una notificacion y el comprobante de pago adjunto al proveedor con el numero fiscal de la empresa u otro (configurable)
* CHATROOM (Fase II)
* HISTORICO DE OBRAS (Nuevo!)
* Referencias del estimado de cada producto via web
* Alertas prioritarias o de flujo (IN) via ToastAlert, Push y Correo
* Mejorar el panel del proveedor (aumentos de precio de productos segun analisis de datos [metrica])
* Mostrar Precio APROB y el EST
* El aumento, Cambio de precios y etc caue como data relevante dentro de tablas y vistas pertinentes
* PRE-VISUALIZER De planos
* Toasts en todo el flujo para feedback