<?php

declare(strict_types=1);

namespace Drupal\agilpro_onbase\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\QueueFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Endpoint de métricas de infraestructura y observabilidad para Zabbix y Dynatrace.
 *
 * Expone en formato JSON el estado de la base de datos, sistema de archivos,
 * cola asíncrona de OnBase y memoria PHP.
 */
class OnBaseHealthCheckController extends ControllerBase {

  public const QUEUE_NAME = 'agilpro_onbase_upload_queue';

  public function __construct(
    protected readonly Connection $database,
    protected readonly QueueFactory $queueFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('database'),
      $container->get('queue')
    );
  }

  /**
   * Responde con métricas de salud en JSON.
   */
  public function check(Request $request): JsonResponse {
    $startTime = microtime(true);
    $status = 'healthy';
    $statusCode = 200;
    $checks = [];

    // 1. Verificación de Base de Datos
    try {
      $dbStart = microtime(true);
      $this->database->query('SELECT 1')->execute();
      $dbDuration = round((microtime(true) - $dbStart) * 1000, 2);
      $checks['database'] = [
        'status' => 'UP',
        'latency_ms' => $dbDuration,
      ];
    }
    catch (\Throwable $e) {
      $status = 'unhealthy';
      $statusCode = 503;
      $checks['database'] = [
        'status' => 'DOWN',
        'error' => $e->getMessage(),
      ];
    }

    // 2. Verificación de Cola Asíncrona OnBase
    try {
      $queue = $this->queueFactory->get(self::QUEUE_NAME);
      $pendingItems = $queue->numberOfItems();
      $checks['onbase_queue'] = [
        'status' => 'UP',
        'pending_items' => $pendingItems,
      ];
    }
    catch (\Throwable $e) {
      $checks['onbase_queue'] = [
        'status' => 'DEGRADED',
        'error' => $e->getMessage(),
      ];
    }

    // 3. Auditoría de fallos recientes en OnBase
    if ($this->database->schema()->tableExists('agilpro_onbase_audit_log')) {
      $failedCount = (int) $this->database->select('agilpro_onbase_audit_log', 'a')
        ->condition('status', 'FAILED')
        ->countQuery()
        ->execute()
        ->fetchField();

      $checks['onbase_audit'] = [
        'failed_records_count' => $failedCount,
      ];
    }

    // 4. Verificación de Sistema de Archivos Privado (Ley 1581)
    $realPrivate = \Drupal::service('file_system')->realpath('private://');
    $checks['filesystem'] = [
      'private_path_configured' => !empty($realPrivate),
      'private_path_writable' => !empty($realPrivate) && is_writable($realPrivate),
      'disk_free_mb' => ($realPrivate && is_dir($realPrivate)) ? round(disk_free_space($realPrivate) / (1024 * 1024), 2) : 0,
    ];

    // 5. Métricas de PHP y Memoria para APM
    $checks['runtime'] = [
      'php_version' => PHP_VERSION,
      'memory_usage_mb' => round(memory_get_usage(true) / (1024 * 1024), 2),
      'memory_peak_mb' => round(memory_get_peak_usage(true) / (1024 * 1024), 2),
      'memory_limit' => ini_get('memory_limit'),
    ];

    $totalDuration = round((microtime(true) - $startTime) * 1000, 2);

    $payload = [
      'status' => $status,
      'environment' => getenv('DRUPAL_ENV') ?: (getenv('APP_ENV') ?: 'dev'),
      'timestamp' => date('c'),
      'duration_ms' => $totalDuration,
      'checks' => $checks,
    ];

    return new JsonResponse($payload, $statusCode, [
      'Cache-Control' => 'no-store, no-cache, must-revalidate',
      'X-Content-Type-Options' => 'nosniff',
    ]);
  }

}
