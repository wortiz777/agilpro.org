# Módulo Drupal 11: Conector Desacoplado Hyland OnBase (Compensar)

Este módulo (`agilpro_onbase`) proporciona un conector desacoplado, asíncrono y tolerante a fallos para transferir automáticamente documentos PDF de hasta 20 MB hacia el sistema de gestión documental corporativo **Hyland OnBase** de Compensar vía REST Web API.

---

## 1. Arquitectura y Componentes Desarrollados

### 1.1 Gestión de Credenciales Segura (`Key` Module API)
- Utiliza `Drupal::service('key.repository')` para leer las claves configuradas:
  - `onbase_base_url`: Endpoint raíz de la API de OnBase (ej. `https://onbase.compensar.com/api/v1`).
  - `onbase_client_id`: Identificador del cliente OAuth2.
  - `onbase_client_secret`: Clave secreta del cliente OAuth2.
  - `onbase_api_key`: API Key complementaria de cabecera si el gateway lo requiere.
  - `onbase_bearer_token`: Token estático directo opcional si no se usa client credentials.
- Si no están registradas en el repositorio `key`, dispone de fallback transparente a variables de entorno (`ONBASE_BASE_URL`, etc.). **Cero credenciales en código duro o `settings.php` expuesto**.

### 1.2 Manejo de Memoria de Binarios de 20 MB (Guzzle Streams)
- [`OnBaseClientService.php`](file:///home/willy/dev/agilpro.org/web/modules/custom/agilpro_onbase/src/Service/OnBaseClientService.php):
  - Emplea `GuzzleHttp\Psr7\Utils::streamFor(fopen($localPath, 'rb'))` encapsulado en una petición `POST` con formato `multipart/form-data`.
  - El archivo se transfiere bloque a bloque a través del socket TCP hacia OnBase sin inflar la memoria heap de PHP-FPM ni agotar el `memory_limit`.
  - Configurado con timeout de 30 segundos y control estricto de recursos (cierra streams mediante `fclose`).

### 1.3 Payload de Metadatos y Keywords
El servicio valida y envía:
- Binario del documento PDF (`application/pdf`).
- Metadatos requeridos por OnBase:
  - `DocumentType`
  - `NIT_Empresa`
  - `ID_Mentoria`
  - `Fecha_Aceptacion`
  - `Hash_Trazabilidad` (SHA-256 calculado sobre el stream del PDF).

### 1.4 Procesamiento Asíncrono con Queue API + Redis / Drush Cron
- [`OnBaseQueueDispatcher.php`](file:///home/willy/dev/agilpro.org/web/modules/custom/agilpro_onbase/src/Service/OnBaseQueueDispatcher.php): Encola elementos `{entity_id, file_id, timestamp, metadata}` en `agilpro_onbase_upload_queue` inmediatamente después de la aceptación digital, sin bloquear la respuesta HTTP al usuario.
- [`OnBaseUploadQueueWorker.php`](file:///home/willy/dev/agilpro.org/web/modules/custom/agilpro_onbase/src/Plugin/QueueWorker/OnBaseUploadQueueWorker.php): Plugin QueueWorker que procesa cada item de la cola. Si Redis está configurado en Drupal, opera como backend de cola en memoria de alto rendimiento.

### 1.5 Tolerancia a Fallos y Reintentos (Exponential Backoff)
- Errores recuperables (HTTP 5xx, Timeouts > 30s, errores de conexión de red):
  - No descarta la tarea; incrementa `retry_count`.
  - Aplica fórmula de espera exponencial: $delay = 30 \times 2^{\text{retry} - 1}$ segundos (30s, 60s, 120s, 240s, 480s).
  - Admite hasta un máximo de **5 intentos**.
  - Si supera los 5 intentos, marca el registro en auditoría como `FAILED_FATAL` y genera una alerta crítica en el canal de logs.
- Errores fatales (HTTP 4xx, credenciales incorrectas, archivo inexistente):
  - Detiene inmediatamente el reintento para no saturar la API ni el worker.

### 1.6 Auditoría Inmutable y Registro de Document Handle
- [`agilpro_onbase.install`](file:///home/willy/dev/agilpro.org/web/modules/custom/agilpro_onbase/agilpro_onbase.install): Crea la tabla inmutable `agilpro_onbase_audit_log` con índices en `entity_id`, `file_id`, `document_handle`, `status` y `created`.
- Al recibir `200/201` de OnBase, almacena el `document_handle` retornado en la entidad de mentoría (campo `field_onbase_document_handle` o propiedad dinámica) y registra la transacción con payload y respuesta completa en la base de datos de auditoría y en `logger.channel.agilpro_onbase`.

---

## 2. Instrucciones de Activación y Uso

### 2.1 Habilitar el módulo
```bash
lando drush en agilpro_onbase -y
lando drush cr
```

### 2.2 Configuración de Credenciales vía `key`
En la interfaz de Drupal (`/admin/config/system/keys`) o por Drush:
```bash
# Ejemplo: Configurar Base URL
lando drush key:save onbase_base_url "https://api.compensar.com/onbase/v1" --key-type=authentication --key-provider=config

# Ejemplo: Configurar Client ID y Client Secret
lando drush key:save onbase_client_id "TU_CLIENT_ID" --key-type=authentication --key-provider=config
lando drush key:save onbase_client_secret "TU_CLIENT_SECRET" --key-type=authentication --key-provider=config
```

### 2.3 Encolar un documento desde cualquier controlador, form o hook
```php
/** @var \Drupal\agilpro_onbase\Service\OnBaseQueueDispatcher $dispatcher */
$dispatcher = \Drupal::service('agilpro_onbase.queue_dispatcher');

$dispatcher->enqueueMentoriaDocument($nodeMentoriaId, $fileId, [
  'NIT_Empresa' => '860066942-7',
  'DocumentType' => 'ACTA_MENTORIA_FINAL',
]);
```

### 2.4 Procesar la cola en segundo plano
```bash
# Ejecución manual o en crontab / systemd worker:
lando drush queue:run agilpro_onbase_upload_queue
```
