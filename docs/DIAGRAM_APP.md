### Diagrama del recorrido de un proyecto
```mermaid
flowchart TD
    Start(["Proyecto creado"]) --> CREADO

    CREADO["CREADO<br/><i>Infraestructura</i>"] -->|Auditoría revisa| REVISADO["REVISADO_AUDITORIA<br/><i>Auditoría</i>"]
    REVISADO -->|Procura aprueba presupuesto| CONFIRMADO["CONFIRMADO_PROCURA<br/><i>Procura</i>"]
    CONFIRMADO -->|Analista carga y compara propuestas| COMPARATIVA["COMPARATIVA_ENVIADA<br/><i>Analista</i>"]

    COMPARATIVA -->|Procura adjudica contratista| CONTRATADO["CONTRATADO<br/><i>Procura</i>"]

    CONTRATADO -->|Finanzas libera anticipo| EJECUCION["EN_EJECUCION<br/><i>Finanzas</i>"]
    EJECUCION -->|Auditoría reporta obra terminada| VERIFICANDO["VERIFICANDO_FINALIZACION<br/><i>Auditoría</i>"]

    VERIFICANDO -->|Calidad aprobada| LISTO["LISTO_PAGO_FINAL<br/><i>Auditoría</i>"]

    LISTO -->|Finanzas libera pago final| COMPLETADO["COMPLETADO_PAGADO<br/><i>Finanzas</i>"]
    COMPLETADO --> End(["Proyecto cerrado"])
```