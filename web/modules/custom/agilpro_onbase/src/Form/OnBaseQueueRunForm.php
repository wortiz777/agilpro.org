<?php

declare(strict_types=1);

namespace Drupal\agilpro_onbase\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Formulario administrativo para procesar la cola de subidas mediante Batch API.
 */
class OnBaseQueueRunForm extends FormBase {

  public const QUEUE_NAME = 'agilpro_onbase_upload_queue';

  public function __construct(
    protected readonly QueueFactory $queueFactory,
    protected readonly QueueWorkerManagerInterface $queueWorkerManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('queue'),
      $container->get('plugin.manager.queue_worker')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'agilpro_onbase_queue_run_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    $itemCount = $queue->numberOfItems();

    $form['info'] = [
      '#type' => 'item',
      '#markup' => '<p>' . $this->t('Esta herramienta permite ejecutar el procesamiento de la cola de transferencias hacia OnBase en segundo plano con una barra de progreso visual (Batch API), sin depender de la ejecución por consola ni cron.') . '</p>',
    ];

    $form['status_box'] = [
      '#type' => 'markup',
      '#markup' => '<div style="padding:15px; background:#f0f4f8; border-left:4px solid #0056b3; margin-bottom:20px;">'
        . '<strong>Documentos pendientes en cola:</strong> <span style="font-size:18px; font-weight:bold;">' . $itemCount . '</span>'
        . '</div>',
    ];

    if ($itemCount > 0) {
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Procesar Cola Ahora (@count pendientes)', ['@count' => $itemCount]),
        '#button_type' => 'primary',
      ];
    }
    else {
      $form['no_items'] = [
        '#type' => 'markup',
        '#markup' => '<p><em>' . $this->t('No hay documentos pendientes por procesar en la cola actualmente.') . '</em></p>',
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    $itemCount = $queue->numberOfItems();

    if ($itemCount === 0) {
      $this->messenger()->addWarning($this->t('No hay elementos en la cola para procesar.'));
      return;
    }

    $batch = [
      'title' => $this->t('Procesando transferencias hacia Hyland OnBase...'),
      'operations' => [
        [[self::class, 'processQueueBatch'], [$itemCount]],
      ],
      'finished' => [self::class, 'finishQueueBatch'],
      'progress_message' => $this->t('Procesando documentos: @current de @total.'),
    ];

    batch_set($batch);
  }

  /**
   * Operación de lote (Batch) para procesar los elementos de la cola.
   */
  public static function processQueueBatch(int $totalItems, array &$context): void {
    $queue = \Drupal::service('queue')->get(self::QUEUE_NAME);
    /** @var \Drupal\Core\Queue\QueueWorkerInterface $worker */
    $worker = \Drupal::service('plugin.manager.queue_worker')->createInstance(self::QUEUE_NAME);

    if (empty($context['sandbox'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['max'] = $totalItems;
      $context['results']['processed'] = 0;
    }

    // Procesar un ítem por paso del lote para no agotar tiempo de ejecución PHP.
    $item = $queue->claimItem(60);
    if ($item) {
      try {
        $worker->processItem($item->data);
        $queue->deleteItem($item);
        $context['results']['processed']++;
      }
      catch (\Throwable $e) {
        $queue->releaseItem($item);
      }
      $context['sandbox']['progress']++;
    }

    if ($context['sandbox']['progress'] < $context['sandbox']['max'] && $queue->numberOfItems() > 0) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
    else {
      $context['finished'] = 1;
    }
  }

  /**
   * Finalización del proceso por lote.
   */
  public static function finishQueueBatch(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();
    if ($success) {
      $processed = $results['processed'] ?? 0;
      $messenger->addStatus(\Drupal::translation()->formatPlural(
        $processed,
        'Se procesó exitosamente 1 documento hacia OnBase.',
        'Se procesaron exitosamente @count documentos hacia OnBase.'
      ));
    }
    else {
      $messenger->addError(\Drupal::translation()->translate('Ocurrió un error inesperado al procesar la cola de OnBase.'));
    }
  }

}

