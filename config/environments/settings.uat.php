<?php

/**
 * @file
 * Configuración específica para el entorno de UAT / Staging (Compensar).
 */

declare(strict_types=1);

// Ocultar errores detallados; registrar solo en canales de log.
$config['system.logging']['error_level'] = 'hide';

// Optimización de rendimiento activa similar a producción.
$config['system.performance']['css']['preprocess'] = TRUE;
$config['system.performance']['js']['preprocess'] = TRUE;

// Hosts de confianza para UAT.
$settings['trusted_host_patterns'] = [
  '^uat-agilpro\.compensar\.com$',
  '^.*-uat\.compensar\.com$',
];

// Conexión de base de datos leída estrictamente de variables de entorno seguras.
if (getenv('DB_NAME')) {
  $databases['default']['default'] = [
    'database' => getenv('DB_NAME'),
    'username' => getenv('DB_USER'),
    'password' => getenv('DB_PASSWORD'),
    'prefix' => '',
    'host' => getenv('DB_HOST') ?: 'localhost',
    'port' => getenv('DB_PORT') ?: 3306,
    'isolation_level' => 'READ COMMITTED',
    'driver' => 'mysql',
    'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
    'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
  ];
}

