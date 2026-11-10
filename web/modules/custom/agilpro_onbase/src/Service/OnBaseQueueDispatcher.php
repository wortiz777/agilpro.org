<?php

declare(strict_types=1);

namespace Drupal\agilpro_onbase\Service;

use Drupal\Core\Queue\QueueFactory;
use Psr\Log\LoggerInterface;

/**
 * Servicio para encolar transferencias asíncronas hacia OnBase de forma desacoplada.
 */
class OnBaseQueueDispatcher {

  public const QUEUE_NAME = 'agilpro_onbase_upload_queue';

  public function __construct(
    protected readonly QueueFactory $queueFactory,
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * Encola una transferencia sin bloquear la transacción del usuario.
   *
   * @param int|string $entityId
   *   ID de la entidad (e.g. mentoría).
   * @param int|string $fileId
   *   ID de la entidad de archivo (file ID).
   * @param array<string, mixed> $metadata
   *   Metadatos requeridos (DocumentType, NIT_Empresa, ID_Mentoria, Fecha_Aceptacion, Hash_Trazabilidad).
   *
   * @return bool
   *   TRUE si se encoló exitosamente.
   */
  public function enqueueMentoriaDocument(int|string $entityId, int|string $fileId, array $metadata = []): bool {
    $queue = $this->queueFactory->get(self::QUEUE_NAME);

    $payload = [
      'entity_id' => $entityId,
      'file_id' => $fileId,
      'timestamp' => time(),
      'retry_count' => 0,
      'metadata' => array_merge([
        'DocumentType' => 'ACTA_MENTORIA',
        'ID_Mentoria' => (string) $entityId,
        'Fecha_Aceptacion' => date('Y-m-d\TH:i:s'),
      ], $metadata),
    ];

    $queued = (bool) $queue->createItem($payload);

    if ($queued) {
      $this->logger->info('Documento PDF de mentoría @entity encolado exitosamente para OnBase (Queue: @q, File: @f).', [
        '@entity' => $entityId,
        '@q' => self::QUEUE_NAME,
        '@f' => $fileId,
      ]);
    }
    else {
      $this->logger->error('No fue posible encolar documento de mentoría @entity en @q.', [
        '@entity' => $entityId,
        '@q' => self::QUEUE_NAME,
      ]);
    }

    return $queued;
  }

}

