<?php

namespace Drupal\agilpro_fosfec\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Formulario de filtros para la Sábana FOSFEC.
 */
class FosfecSabanaFilterForm extends FormBase {

  public function getFormId() {
    return 'fosfec_sabana_filter_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Filtros de Exportación FOSFEC'),
      '#open' => TRUE,
    ];

    // Cargar aliados
    $aliados = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => 'aliados']);
    $aliado_options = ['' => '- Todos los Aliados -'];
    foreach ($aliados as $term) {
      $aliado_options[$term->id()] = $term->label();
    }
    $form['filters']['aliado_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Aliado / Proveedor'),
      '#options' => $aliado_options,
    ];

    // Cargar ejes
    $ejes = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => 'ejes_estrategicos']);
    $eje_options = ['' => '- Todos los Ejes -'];
    foreach ($ejes as $term) {
      $eje_options[$term->id()] = $term->label();
    }
    $form['filters']['eje_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Eje Estratégico'),
      '#options' => $eje_options,
    ];

    $form['filters']['state'] = [
      '#type' => 'select',
      '#title' => $this->t('Estado Workflow'),
      '#options' => [
        '' => '- Todos los Estados -',
        'programada' => 'Programada',
        'ejecutada' => 'Ejecutada con Evidencia',
        'aceptada_digitalmente' => 'Aceptada Digitalmente',
        'auditada_fosfec' => 'Auditada y Congelada',
      ],
    ];

    $form['filters']['actions'] = [
      '#type' => 'actions',
    ];
    $form['filters']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('📊 Descargar Sábana en Excel (.xlsx)'),
      '#attributes' => [
        'class' => ['btn-corp', 'btn-corp-success', 'btn-excel-green'],
        'style' => 'background-color: #107c41 !important; border-color: #0e6c38 !important; color: #ffffff !important; font-weight: 600; padding: 0.6rem 1.4rem; border-radius: 50px; box-shadow: 0 4px 12px rgba(16, 124, 65, 0.35); display: inline-flex; align-items: center; gap: 0.5rem;',
      ],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $params = [
      'aliado_id' => $values['aliado_id'],
      'eje_id' => $values['eje_id'],
      'state' => $values['state'],
    ];

    $url = Url::fromRoute('agilpro_fosfec.sabana_download', [], ['query' => array_filter($params)]);
    $form_state->setRedirectUrl($url);
  }

}

