<?php

declare(strict_types=1);

namespace Drupal\agilpro_onbase\Form;

use Drupal\agilpro_onbase\Service\OnBaseQueueDispatcher;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Formulario de confirmación segura para reintentar una transferencia fallida a OnBase.
 */
class OnBaseRequeueConfirmForm extends ConfirmFormBase {

  protected ?object $record = null;

  public function __construct(
    protected readonly Connection $database,
    protected readonly OnBaseQueueDispatcher $queueDispatcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('database'),
      $container->get('agilpro_onbase.queue_dispatcher')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'agilpro_onbase_requeue_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $id = NULL): array {
    $this->record = $this->database->select('agilpro_onbase_audit_log', 'a')
      ->fields('a')
      ->condition('id', $id)
      ->execute()
      ->fetchObject();

    if (!$this->record) {
      $this->messenger()->addError($this->t('El registro de auditoría solicitado no existe.'));
      return $this->redirect('agilpro_onbase.audit');
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('¿Está seguro de que desea reencolar la transferencia hacia OnBase para la mentoría @entity (Registro #@id)?', [
      '@entity' => $this->record->entity_id ?? 'N/A',
      '@id' => $this->record->id ?? '',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Esta acción reiniciará los intentos y añadirá el documento nuevamente a la cola asíncrona de subida para ser procesado hacia Hyland OnBase.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('agilpro_onbase.audit');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->record) {
      $metadata = !empty($this->record->metadata_payload)
        ? (json_decode((string) $this->record->metadata_payload, TRUE) ?: [])
        : [];

      $this->queueDispatcher->enqueueMentoriaDocument(
        $this->record->entity_id,
        $this->record->file_id,
        $metadata
      );

      $this->messenger()->addStatus($this->t('El documento de mentoría @entity ha sido reencolado con éxito para transferencia a OnBase.', [
        '@entity' => $this->record->entity_id,
      ]));
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}

