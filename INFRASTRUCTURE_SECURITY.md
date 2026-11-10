# GUÍA DE ASEGURAMIENTO DE INFRAESTRUCTURA, MONITOREO Y SEGURIDAD
## Plataforma AgilPro / Compensar — Drupal 11 & PHP 8.3

---

## 1. Segregación de Ambientes (DEV, UAT, PROD)

El proyecto cuenta con carga condicional desacoplada de configuraciones en base a la variable de entorno `DRUPAL_ENV` (o `APP_ENV`):

- **Directorio de configuración unificado**: `../config/sync` (fuera del webroot público, bajo control de versiones Git).
- **Entorno de Desarrollo (`dev`)**: `config/environments/settings.dev.php`
  - Errores en pantalla (`verbose`), preprocesamiento de CSS/JS desactivado para depuración, hosts locales autorizados (`*.lndo.site`, `localhost`).
- **Entorno de Pruebas / UAT (`uat`)**: `config/environments/settings.uat.php`
  - Errores ocultos (`hide`), preprocesamiento activo, dominios restringidos a `*-uat.compensar.com`.
- **Entorno de Producción (`prod`)**: `config/environments/settings.prod.php`
  - Registro de errores en modo silencioso (`none`), proxies inversos habilitados (`reverse_proxy`), conexión MySQL con TLS/SSL obligatoria, dominios oficiales de Compensar.

### Variables de entorno a definir por el equipo de DevOps / Cloud:
```bash
# En el contenedor o servidor:
DRUPAL_ENV=prod
DB_NAME=agilpro_prod
DB_USER=drupal_app
DB_PASSWORD=secret_vault_password
DB_HOST=mysql-cluster.internal.compensar.com
```

---

## 2. Cifrado en Reposo (AES-256) y Protección de Datos (Ley 1581 de 2012)

1. **Gestión de Credenciales**:
   - Todas las credenciales de servicios externos (Hyland OnBase, Azure AD / SSO, APIs) se gestionan a través del módulo `Key` (`KeyRepositoryInterface`) evitando llaves en código fuente o base de datos sin cifrar.
2. **Archivos y Documentos Privados**:
   - La plataforma tiene configurado el esquema `private://` (`sites/default/files/private`), el cual **no es accesible directamente por URL web**. Los archivos PDF y actas solo se sirven mediante verificación de permisos de Drupal (`hook_file_download`).
3. **Cifrado de Base de Datos (Infraestructura)**:
   - Para cumplir al 100% con la Ley 1581 a nivel de almacenamiento físico, la base de datos institucional en UAT y Producción debe contar con **TDE (Transparent Data Encryption)** activo con algoritmo **AES-256** (soportado nativamente en Azure Database for MySQL, AWS RDS MySQL o MariaDB con `innodb_encrypt_tables = ON`).

---

## 3. Cifrado en Tránsito (TLS 1.3) y Seguridad Perimetral

1. **Cliente HTTP OnBase**:
   - [`OnBaseClientService.php`](file:///home/willy/dev/agilpro.org/web/modules/custom/agilpro_onbase/src/Service/OnBaseClientService.php) impone negociación segura `CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_3` (o TLS 1.2 como fallback mínimo seguro).
2. **Plantilla de endurecimiento para Reverse Proxy / NGINX**:
```nginx
# Compensar NGINX Ingress / Frontend Server
ssl_protocols TLSv1.3 TLSv1.2;
ssl_prefer_server_ciphers on;
ssl_ciphers 'ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305';
ssl_session_cache shared:SSL:10m;
ssl_session_timeout 1d;
ssl_session_tickets off;

# HSTS (HTTP Strict Transport Security)
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "SAMEORIGIN" always;
```

---

## 4. Observabilidad y Monitoreo (Dynatrace & Zabbix)

### A. Endpoint de Métricas para Zabbix (`/api/v1/healthz`)
- **Ruta**: `GET /api/v1/healthz`
- **Formato**: JSON estándar estructurado.
- **Respuesta de ejemplo (HTTP 200)**:
```json
{
  "status": "healthy",
  "environment": "dev",
  "timestamp": "2026-11-08T11:22:40-05:00",
  "duration_ms": 1.27,
  "checks": {
    "database": {
      "status": "UP",
      "latency_ms": 0.1
    },
    "onbase_queue": {
      "status": "UP",
      "pending_items": 0
    },
    "onbase_audit": {
      "failed_records_count": 0
    },
    "filesystem": {
      "private_path_configured": true,
      "private_path_writable": true,
      "disk_free_mb": 782535.16
    },
    "runtime": {
      "php_version": "8.3.14",
      "memory_usage_mb": 34.5,
      "memory_peak_mb": 34.5,
      "memory_limit": "1024M"
    }
  }
}
```
*Si la base de datos se desconecta o el sistema de archivos privado falla, el endpoint responde con **HTTP 503 Service Unavailable** y `status: "unhealthy"` para disparar alertas automáticas en Zabbix.*

### B. Trazabilidad Distribuida Dynatrace (W3C TraceContext)
- Cada transferencia enviada hacia la API de OnBase en [`OnBaseClientService.php`](file:///home/willy/dev/agilpro.org/web/modules/custom/agilpro_onbase/src/Service/OnBaseClientService.php) propaga o genera la cabecera estándar de observabilidad `traceparent` (ej. `00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01`).
- Esto permite que el agente **Dynatrace OneAgent** correlacione de forma continua la transacción del usuario en Drupal con las peticiones externas al backend de OnBase de Compensar sin puntos ciegos.

