<?php

namespace Drupal\agilpro_fosfec\Plugin\Action;

use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;

/**
 * Acción VBO para asignar mentor en lote a inscripciones.
 *
 * @Action(
 *   id = "agilpro_fosfec_asignar_mentor_action",
 *   label = @Translation("FOSFEC: Asignar Mentor / Consultor"),
 *   type = "node"
 * )
 */
class AsignarMentorAction extends ViewsBulkOperationsActionBase implements PluginFormInterface {

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

    $mentor_uid = $this->configuration['mentor_uid'] ?? NULL;
    if ($mentor_uid && $entity->hasField('field_mentor_asignado')) {
      $entity->set('field_mentor_asignado', $mentor_uid);
      $entity->save();
      return $this->t('Mentor asignado a @label.', ['@label' => $entity->label()]);
    }

    return $this->t('Sin cambios.');
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    // Cargar usuarios con rol asesor o mentor_consultor
    $uids = \Drupal::entityQuery('user')
      ->condition('status', 1)
      ->condition('roles', ['asesor', 'mentor_consultor'], 'IN')
      ->accessCheck(FALSE)
      ->execute();

    $options = ['' => $this->t('- Seleccionar Mentor / Asesor -')];
    if (!empty($uids)) {
      $mentores = \Drupal::entityTypeManager()->getStorage('user')->loadMultiple($uids);
      foreach ($mentores as $m) {
        $options[$m->id()] = $m->getDisplayName() . ' (' . $m->getEmail() . ')';
      }
    }

    $form['mentor_uid'] = [
      '#type' => 'select',
      '#title' => $this->t('Seleccione el Mentor / Consultor'),
      '#options' => $options,
      '#required' => TRUE,
      '#default_value' => $this->configuration['mentor_uid'] ?? '',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['mentor_uid'] = $form_state->getValue('mentor_uid');
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account = $account ?: \Drupal::currentUser();
    return $object->access('update', $account, $return_as_object);
  }

}
