<?php

/**
 * @file
 * Configuración estricta de seguridad y rendimiento para Producción (Compensar).
 */

declare(strict_types=1);

// NUNCA mostrar trazas de error al usuario final.
$config['system.logging']['error_level'] = 'none';

// Máximo rendimiento y compresión de assets.
$config['system.performance']['css']['preprocess'] = TRUE;
$config['system.performance']['js']['preprocess'] = TRUE;

// Cabeceras de seguridad estrictas (HSTS, NoSniff).
$settings['reverse_proxy'] = TRUE;
$settings['reverse_proxy_header'] = 'X_FORWARDED_FOR';
$settings['reverse_proxy_proto_header'] = 'X_FORWARDED_PROTO';
$settings['reverse_proxy_host_header'] = 'X_FORWARDED_HOST';
$settings['reverse_proxy_port_header'] = 'X_FORWARDED_PORT';

// Patrones estrictos de dominios corporativos autorizados.
$settings['trusted_host_patterns'] = [
  '^agilpro\.compensar\.com$',
  '^www\.agilpro\.compensar\.com$',
  '^emprendelo\.compensar\.com$',
];

// Conexión de base de datos cifrada y obligatoriamente leída de variables de entorno / Vault.
if (getenv('DB_NAME')) {
  $databases['default']['default'] = [
    'database' => getenv('DB_NAME'),
    'username' => getenv('DB_USER'),
    'password' => getenv('DB_PASSWORD'),
    'prefix' => '',
    'host' => getenv('DB_HOST'),
    'port' => getenv('DB_PORT') ?: 3306,
    'isolation_level' => 'READ COMMITTED',
    'driver' => 'mysql',
    'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
    'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
    'pdo' => [
      // Exigir cifrado SSL/TLS en conexión cliente-servidor MySQL
      \PDO::MYSQL_ATTR_SSL_CA => getenv('MYSQL_SSL_CA') ?: '',
      \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => (bool) getenv('MYSQL_SSL_VERIFY_SERVER_CERT'),
    ],
  ];
}

