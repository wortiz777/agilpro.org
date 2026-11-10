# Memoria Técnica y Bitácora Histórica del Proyecto: AgilPro FOSFEC

> **Repositorio Oficial:** `git@github.com:wortiz777/agilpro.org.git`  
> **Plataforma:** Drupal 11 / PHP 8.3 / MariaDB / Lando  
> **Ámbito:** Programa de Fomento y Fortalecimiento Empresarial (Compensar / FOSFEC)  
> **Fecha de Consolidación:** Noviembre 2026  

---

## 1. Origen y Contexto del Proyecto

### 1.1. Antecedentes
El proyecto **AgilPro** nace con el objetivo de sistematizar y modernizar la gestión, acompañamiento y trazabilidad de los programas de fomento empresarial dirigidos a micro, pequeñas y medianas empresas (Mipymes) vinculadas a la caja de compensación (Compensar) mediante recursos **FOSFEC** (Fondo de Solidaridad de Fomento al Empleo y Protección al Cesante).

Originalmente, los procesos de postulación, diagnósticos de capacidades (matrices tipo Likert 360°), asignación de consultores/mentores y registro de evidencias operativas se realizaban mediante herramientas dispersas y formularios sin integración transaccional directa.

### 1.2. Misión Técnica del Desarrollo
El presente desarrollo transformó la instancia en un **ecosistema transaccional auditable**, con:
1. **Gobierno y Control de Convocatorias:** Publicación de programas (`oferta`), administración de cupos en tiempo real y reglas automáticas de apertura/cierre.
2. **Flujo de Postulación Mipyme:** Registro asistido de empresas, vinculación de colaboradores y recepción de documentación.
3. **Módulo de Evaluación y Diagnóstico:** Pipeline de calificación Mipyme, asignación de Rutas de Atención (Rutas 1 a 23) y asignación de mentores especializados.
4. **Espacio de Trabajo del Mentor:** Tablero operativo para control de beneficiarios, registro de horas/sesiones y cargue de actas transaccionales.
5. **Aceptación Digital por el Empresario:** Validación de asistencias y compromisos mediante logs criptográficos/sellos de tiempo.
6. **Módulo OnBase:** Cola asíncrona de integración documental hacia el gestor documental empresarial OnBase de Compensar.
7. **Sábana Auditada FOSFEC:** Exportación para interventoría con bloqueo de registros auditados.

---

## 2. Arquitectura del Sistema

### 2.1. Módulos Personalizados (`web/modules/custom/`)
* **`agilpro_fosfec`**:
  * Orquesta los tipos de contenido `oferta`, `inscripcion_oferta` y `fosfec_transaction`.
  * Implementa el workflow de máquina de estados (`state_machine`): `programada` ➔ `ejecutada` ➔ `aceptada_digitalmente` ➔ `auditada_fosfec`.
  * Gestiona las reglas de transición y accesos (`FosfecTransactionWorkflowGuard`, `FosfecWorkflowSubscriber`).
  * Expone el Tablero de Control FOSFEC (`/admin/agilpro/dashboard`) con acciones masivas AJAX (VBO) y la Sábana de reportes (`/admin/reports/fosfec-sabana`).
* **`agilpro_org`**:
  * Administra el portal de cara a la empresa: `/mi-empresa/dashboard`, `/mi-empresa/perfil`, `/mi-empresa/postulaciones`, `/mi-empresa/sesiones`.
  * Controla los perfiles de usuario, roles dinámicos, cálculo de capacidades emprendedoras (gráficos radiales Chart.js / radar) y validaciones en formularios Webform Likert.
* **`agilpro_onbase`**:
  * Servicio de integración API REST con OnBase.
  * Worker en cola (`OnBaseUploadQueueWorker`) con política de reintentos exponenciales, registro de logs de auditoría y descarga de sábanas.

### 2.2. Tema y Capa Visual (`web/themes/custom/subtema/`)
* Subtema basado en Bootstrap 3 con componentes enterprise:
  * Barra de navegación lateral vertical colapsable/expandible con persistencia (`localStorage`).
  * Hoja de estilos corporativa [`enterprise-ui.css`](web/themes/custom/subtema/css/enterprise-ui.css) con paleta oficial Compensar (`#002d62`, `#0052cc`, `#e0592a`).
  * Soporte de contraste y accesibilidad WCAG AA.

---

## 3. Bitácora Histórica de Ajustes en Firme

A continuación se detalla cronológicamente el histórico de intervenciones técnicas implementadas:

### Fase I: Identidad Visual, Accesibilidad y Portales Base
1. **Ajuste de Tipografía y Contraste Global**:
   * Incremento en 2 puntos del tamaño de fuente en dashboards y contenido para mejorar legibilidad en pantallas operativas.
   * Corrección de contraste en encabezados azules: los textos secundarios grises oscuros fueron migrados a blancos/grises claros (`#f1f5f9`).
   * Corrección global sobre encabezados `h4` y etiquetas de formulario que se renderizaban en color blanco sobre fondos claros debido a la clase `.label` heredada de Bootstrap.
2. **Catálogo de Convocatorias Vigentes (`/ofertas`)**:
   * Creación de la página pública de ofertas con tarjetas corporativas, indicadores de cupos disponibles y fechas de postulación.
   * Configuración de badges automáticos: *"Convocatoria Abierta"* vs *"Convocatoria Cerrada"*, calculados dinámicamente con base en las fechas del nodo y la existencia de cupos disponibles (`field_cupos_disponibles`).
   * Incorporación del botón de navegación rápida *"Explorar nuevas convocatorias"* en el dashboard empresarial.
3. **Navegación Unificada en Menú Principal**:
   * Integración de accesos directos condicionados por rol a `/mi-empresa/dashboard`, `/ofertas`, y `/mi-espacio-mentor`.

### Fase II: Módulo de Evaluadores y Diagnosticadores (`AUDIT-AGILPRO-EVALUADOR-01`)
1. **Matriz de Permisos y Control de Acceso por Campo**:
   * Asignación de permisos granulares al rol `evaluador_diagnosticador`: consulta de convocatorias, lectura y edición exclusiva de campos de evaluación en `inscripcion_oferta`.
   * Protección a nivel de entidad y formulario para que los estados y asignaciones sólo puedan ser modificados por evaluadores o coordinadores.
2. **Automatización en Presave (Descuento de Cupos)**:
   * Implementación en `agilpro_fosfec_node_presave()` del descuento atómico del campo `field_cupos_disponibles` del nodo `oferta` vinculado únicamente al momento de transicionar el estado a `aprobada` (evitando duplicidad por re-guardado).
3. **Tablero de Control FOSFEC y Acciones Masivas (VBO)**:
   * Corrección de la interacción JavaScript en Drupal 11 migrando de `$(...).once()` al estándar nativo `once('id', selector, context)`.
   * Habilitación de asignación de Mentor, asignación de Rutas 1 a 23 y aprobación/rechazo masivo directo desde la tabla.

### Fase III: Espacio de Trabajo del Mentor (`REQ-AGILPRO-MENTOR-WORKSPACE-01`)
1. **Portal y Menú del Mentor**:
   * Creación de las vistas especializadas:
     * `/mi-espacio-mentor/empresas` (listado filtrado de empresas aprobadas a su cargo).
     * `/mi-espacio-mentor/sesiones` (bitácora transaccional de sesiones y horas ejecutadas).
   * Corrección en `menu--main.html.twig` y `enterprise-ui.js`: en modo colapsado (iconos), el clic navega directamente a la ruta destino en vez de quedar bloqueado en `href="#"`. En modo expandido, permite colapsar/expandir en acordeón.
   * Asignación de iconos semánticos Font Awesome: `fa-chalkboard-user`, `fa-building` y `fa-calendar-check`.
2. **Auto-fill en Creación de Sesión FOSFEC**:
   * Al hacer clic en *"Registrar Sesión"* desde la empresa asignada (`/node/add/fosfec_transaction?inscripcion={nid}`), el sistema precarga automáticamente: Empresa beneficiaria, NIT, Contacto asistente, Convocatoria, Eje estratégico, Ruta y Mentor asignado.
3. **Workflow Transaccional FOSFEC y Resolución de Estado**:
   * Declaración del grupo de workflow en `agilpro_fosfec.workflow_groups.yml` (`node`).
   * Configuración en `field.field.node.fosfec_transaction.field_state` de la vinculación a `fosfec_transaction_workflow` con estado inicial por defecto `programada`.
   * Habilitación de las opciones permitidas para el mentor (`Programada` y `Ejecutada con Evidencia`).
   * Ajuste de estilos en el formulario: corrección de color y contraste en los labels del widget de fecha/hora (`field_fecha_hora_sesion`).

### Fase IV: Saneamiento de Estado y Dependencias Externas
1. **Resolución de Alertas Críticas en Informe de Estado (`admin/reports/status`)**:
   * Descarga e instalación de la librería `colorbox` en `web/libraries/colorbox` mediante `drush colorbox:plugin`.
   * Descarga e instalación de la librería `fonticonpicker` en `web/libraries/fonticonpicker--fonticonpicker` mediante `drush fa:download-iconpicker`.
   * Descarga de `dompurify` para sanitización de HTML.
   * El informe de estado del sistema quedó libre de errores rojos o bloqueantes.
2. **Control de Versiones y Publicación en GitHub**:
   * Sincronización y exportación total de configuración activa de Drupal a `config/sync/` (`drush cex -y`).
   * Creación del repositorio Git, exclusión de temporales y dependencias vendor vía `.gitignore`, y publicación de la rama principal `main` en `git@github.com:wortiz777/agilpro.org.git`.

---

## 4. Estructura de Roles y Flujo Operativo

```
[ Empresario / Mipyme ] 
        │ (Se postula a Oferta)
        ▼
[ node: inscripcion_oferta ] (Estado: postulada)
        │
        ▼
[ Evaluador / Diagnosticador ]
        │ 1. Revisa cumplimiento Mipyme
        │ 2. Asigna Ruta (1 a 23) y Mentor
        │ 3. Aprueba postulación
        ▼
   (Descuento automático de cupo en node: oferta)
   (Estado: aprobada)
        │
        ▼
[ Mentor / Consultor ] (/mi-espacio-mentor/empresas)
        │ 1. Visualiza empresa asignada
        │ 2. Registra Atención FOSFEC (/node/add/fosfec_transaction)
        │    (Estado inicial: programada)
        │ 3. Ejecuta sesión y carga acta en PDF
        │    (Transición: registrar_ejecucion ➔ ejecutada)
        ▼
[ Empresario / Colaborador ] (/mi-empresa/sesiones)
        │ Valida y firma electrónicamente
        │ (Transición: confirmar_aceptacion ➔ aceptada_digitalmente)
        ▼
[ Coordinador / Auditor FOSFEC ] (/admin/agilpro/dashboard | /admin/reports/fosfec-sabana)
        │ 1. Audita acta, soporte y log IP
        │ 2. Congela registro (Transición: congelar_auditoria ➔ auditada_fosfec)
        │ 3. Envío asíncrono a OnBase y descarga en Sábana Oficial
```

---

## 5. Hacia Dónde Va el Proyecto (Hoja de Ruta / Próximos Pasos)

Para quien asuma la continuidad de este proyecto, los siguientes frentes representan la evolución planificada:

1. **Integración con OnBase en Producción**:
   * Completar los endpoints reales de autenticación y carga de documentos de OnBase en `agilpro_onbase.settings.yml`.
   * Probar el despachador de colas en cron para lotes de transacciones auditadas.
2. **Sincronización de Agenda Externa del Mentor**:
   * Implementar la integración con calendarios (Teams / Outlook / Google Calendar) mediante URLs de tipo `.ics` o webhooks para las sesiones programadas.
3. **Notificaciones Automatizadas por Correo**:
   * Disparo de alertas cuando:
     * Una empresa resulta aprobada en una convocatoria.
     * Un mentor es asignado a una empresa.
     * Una sesión queda en estado `ejecutada` requiriendo la firma digital del empresario.
4. **Métricas y BI Avanzado**:
   * Ampliación de los dashboards con gráficos agregados por Cámara de Comercio, sector económico y cumplimiento de metas del presupuesto FOSFEC.
5. **Despliegue a Servidores de UAT / Producción**:
   * Utilizar la carpeta `config/environments/` (`settings.uat.php`, `settings.prod.php`) y ejecutar importaciones automáticas de configuración vía CI/CD (`drush cim -y`).
