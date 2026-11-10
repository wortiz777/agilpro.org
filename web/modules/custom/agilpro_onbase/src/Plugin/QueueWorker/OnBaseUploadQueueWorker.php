<?php

declare(strict_types=1);

namespace Drupal\agilpro_onbase\Plugin\QueueWorker;

use Drupal\agilpro_onbase\Exception\OnBaseFatalException;
use Drupal\agilpro_onbase\Exception\OnBaseRecoverableException;
use Drupal\agilpro_onbase\Service\OnBaseClientService;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\file\FileInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Worker asíncrono para transferir documentos PDF hacia Hyland OnBase.
 *
 * Implementa reintentos con Exponential Backoff hasta un máximo de 5 intentos
 * ante errores 5xx o timeouts, y auditoría inmutable del Document Handle retornado.
 *
 * @QueueWorker(
 *   id = "agilpro_onbase_upload_queue",
 *   title = @Translation("AgilPro OnBase PDF Upload Worker"),
 *   cron = {"time" = 60}
 * )
 */
class OnBaseUploadQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public const MAX_RETRIES = 5;
  public const QUEUE_NAME = 'agilpro_onbase_upload_queue';

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly OnBaseClientService $onBaseClient,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly LoggerInterface $logger,
    protected readonly QueueFactory $queueFactory,
    protected readonly Connection $database,
    protected readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('agilpro_onbase.client'),
      $container->get('entity_type.manager'),
      $container->get('logger.channel.agilpro_onbase'),
      $container->get('queue'),
      $container->get('database'),
      $container->get('file_url_generator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!is_array($data) || empty($data['file_id']) || empty($data['entity_id'])) {
      $this->logger->error('Payload inválido recibido en cola OnBase: @data', [
        '@data' => json_encode($data),
      ]);
      return;
    }

    $entityId = $data['entity_id'];
    $fileId = $data['file_id'];
    $retryCount = (int) ($data['retry_count'] ?? 0);
    $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];

    // Validar retraso exponencial si viene programado.
    $nextAllowedTime = (int) ($data['next_allowed_time'] ?? 0);
    if ($nextAllowedTime > time()) {
      // Aún no transcurre el tiempo de backoff exponencial; requeue para próxima pasada.
      throw new RequeueException("Esperando backoff exponencial para entidad {$entityId}. Próximo intento en " . ($nextAllowedTime - time()) . 's');
    }

    /** @var \Drupal\file\FileInterface|null $file */
    $file = $this->entityTypeManager->getStorage('file')->load($fileId);
    if (!$file instanceof FileInterface) {
      $this->recordAuditFailure((int) $entityId, (int) $fileId, $retryCount, 'Archivo no encontrado en sistema.');
      throw new OnBaseFatalException("Archivo con ID {$fileId} no existe en Drupal.");
    }

    $uri = $file->getFileUri();
    $realPath = \Drupal::service('file_system')->realpath($uri);
    if (!$realPath || !file_exists($realPath)) {
      $this->recordAuditFailure((int) $entityId, (int) $fileId, $retryCount, "Ruta física no encontrada para URI: {$uri}");
      throw new OnBaseFatalException("El archivo físico no está disponible en disco: {$uri}");
    }

    // Completar hash de trazabilidad si no viene en los metadatos.
    if (empty($metadata['Hash_Trazabilidad'])) {
      $metadata['Hash_Trazabilidad'] = hash_file('sha256', $realPath);
    }

    try {
      // Transferencia directa por streaming (hasta 20 MB sin consumo de RAM excesivo).
      $result = $this->onBaseClient->uploadDocumentStream($realPath, $metadata);
      $documentHandle = (string) ($result['document_handle'] ?? '');

      // Registro de auditoría inmutable e incorporación al registro de mentoría.
      $this->recordAuditSuccess((int) $entityId, (int) $fileId, $documentHandle, $metadata, $retryCount);
      $this->attachHandleToEntity($entityId, $documentHandle);
    }
    catch (OnBaseRecoverableException $e) {
      $retryCount++;
      if ($retryCount >= self::MAX_RETRIES) {
        $msg = "Se agotó el límite máximo de reintentos (" . self::MAX_RETRIES . ") para OnBase en entidad {$entityId}: " . $e->getMessage();
        $this->logger->critical($msg);
        $this->recordAuditFailure((int) $entityId, (int) $fileId, $retryCount, $msg);
        // Desiste de la cola para no bloquear indefinidamente.
        return;
      }

      // Exponential Backoff: base 30s * (2 ^ (intento - 1)) => 30s, 60s, 120s, 240s...
      $delaySeconds = (int) (30 * (2 ** ($retryCount - 1)));
      $nextAllowedTime = time() + $delaySeconds;

      $this->logger->warning('Error recuperable con OnBase (intento @current/@max). Reintentando en @delay s. Error: @err', [
        '@current' => $retryCount,
        '@max' => self::MAX_RETRIES,
        '@delay' => $delaySeconds,
        '@err' => $e->getMessage(),
      ]);

      // Re-encolar el ítem con el contador incrementado y timestamp de ejecución.
      $data['retry_count'] = $retryCount;
      $data['next_allowed_time'] = $nextAllowedTime;
      $this->queueFactory->get(self::QUEUE_NAME)->createItem($data);
    }
    catch (OnBaseFatalException $e) {
      $this->logger->error('Fallo fatal permanente al procesar OnBase para entidad @entity (sin reintento): @err', [
        '@entity' => $entityId,
        '@err' => $e->getMessage(),
      ]);
      $this->recordAuditFailure((int) $entityId, (int) $fileId, $retryCount, $e->getMessage());
    }
    catch (\Throwable $e) {
      $this->logger->critical('Excepción no controlada en OnBaseUploadQueueWorker para entidad @entity: @err', [
        '@entity' => $entityId,
        '@err' => $e->getMessage(),
      ]);
      $this->recordAuditFailure((int) $entityId, (int) $fileId, $retryCount, $e->getMessage());
    }
  }

  /**
   * Guarda de forma inmutable el registro de éxito en la base de datos de auditoría.
   */
  protected function recordAuditSuccess(int $entityId, int $fileId, string $documentHandle, array $metadata, int $retries): void {
    if ($this->database->schema()->tableExists('agilpro_onbase_audit_log')) {
      $this->database->insert('agilpro_onbase_audit_log')
        ->fields([
          'entity_id' => $entityId,
          'file_id' => $fileId,
          'document_handle' => $documentHandle,
          'status' => 'SUCCESS',
          'retries' => $retries,
          'metadata_payload' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
          'response_message' => 'Documento almacenado y archivado correctamente en OnBase.',
          'created' => time(),
        ])
        ->execute();
    }

    $this->logger->notice('AUDITORIA INMUTABLE: Entidad @ent archivada en OnBase con Handle [@hnd] (Intentos: @ret).', [
      '@ent' => $entityId,
      '@hnd' => $documentHandle,
      '@ret' => $retries,
    ]);
  }

  /**
   * Guarda de forma inmutable el fallo de auditoría.
   */
  protected function recordAuditFailure(int $entityId, int $fileId, int $retries, string $errorMessage): void {
    if ($this->database->schema()->tableExists('agilpro_onbase_audit_log')) {
      $this->database->insert('agilpro_onbase_audit_log')
        ->fields([
          'entity_id' => $entityId,
          'file_id' => $fileId,
          'document_handle' => 'FAILED',
          'status' => 'FAILED',
          'retries' => $retries,
          'metadata_payload' => '',
          'response_message' => mb_substr($errorMessage, 0, 1000),
          'created' => time(),
        ])
        ->execute();
    }
  }

  /**
   * Si la entidad dispone de campo de almacenamiento de Handle, lo asocia.
   */
  protected function attachHandleToEntity(int|string $entityId, string $documentHandle): void {
    try {
      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $node = $nodeStorage->load($entityId);
      if ($node !== null) {
        $updated = false;
        if ($node->hasField('field_onbase_document_handle')) {
          $node->set('field_onbase_document_handle', $documentHandle);
          $updated = true;
        }
        if ($node->hasField('field_onbase_sync_status')) {
          $node->set('field_onbase_sync_status', 'SYNCED');
          $updated = true;
        }
        if ($updated) {
          $node->save();
          $this->logger->info('Nodo @nid actualizado con OnBase Handle @handle.', [
            '@nid' => $entityId,
            '@handle' => $documentHandle,
          ]);
        }
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('No se pudo guardar OnBase Handle en nodo @id: @err', [
        '@id' => $entityId,
        '@err' => $e->getMessage(),
      ]);
    }
  }

}

