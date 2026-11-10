<?php

namespace Drupal\agilpro_fosfec\Plugin\Action;

use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Acción VBO para aprobar inscripciones en lote.
 *
 * @Action(
 *   id = "agilpro_fosfec_aprobar_inscripcion_action",
 *   label = @Translation("FOSFEC: Aprobar Inscripciones Seleccionadas"),
 *   type = "node"
 * )
 */
class AprobarInscripcionAction extends ViewsBulkOperationsActionBase {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if (!$entity || $entity->getEntityTypeId() !== 'node') {
      return $this->t('Entidad no válida.');
    }

    if ($entity->bundle() !== 'inscripcion_oferta') {
      return $this->t('El nodo no es una inscripción.');
    }

    if ($entity->hasField('field_estado_postulacion')) {
      $entity->set('field_estado_postulacion', 'aprobada');
      $entity->save();
      return $this->t('Inscripción @label aprobada exitosamente.', ['@label' => $entity->label()]);
    }

    return $this->t('No se pudo actualizar el estado.');
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account = $account ?: \Drupal::currentUser();
    return $object->access('update', $account, $return_as_object);
  }

}
