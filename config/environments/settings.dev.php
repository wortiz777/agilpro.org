<?php

/**
 * @file
 * Configuración específica para el entorno de Desarrollo Local (Lando/Dev).
 */

declare(strict_types=1);

// Mostrar errores detallados en pantalla.
$config['system.logging']['error_level'] = 'verbose';

// Desactivar agregación de CSS y JavaScript para facilitar depuración en desarrollo.
$config['system.performance']['css']['preprocess'] = FALSE;
$config['system.performance']['js']['preprocess'] = FALSE;

// Habilitar bypass de dominios o hosts locales de desarrollo.
$settings['trusted_host_patterns'] = [
  '^localhost$',
  '^127\.0\.0\.1$',
  '^.*\.lndo\.site$',
  '^.*\.local$',
];

// Configuración de base de datos por defecto para Lando si no está definida en env.
if (!isset($databases['default']['default']['database'])) {
  $databases['default']['default'] = [
    'database' => getenv('DB_NAME') ?: 'drupal',
    'username' => getenv('DB_USER') ?: 'drupal',
    'password' => getenv('DB_PASSWORD') ?: 'drupal',
    'prefix' => '',
    'host' => getenv('DB_HOST') ?: 'database',
    'port' => getenv('DB_PORT') ?: 3306,
    'isolation_level' => 'READ COMMITTED',
    'driver' => 'mysql',
    'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
    'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
  ];
}

