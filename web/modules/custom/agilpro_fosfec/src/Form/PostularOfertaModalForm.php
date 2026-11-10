<?php

namespace Drupal\agilpro_fosfec\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\node\Entity\Node;

/**
 * Formulario modal AJAX de postulación B2B a una Oferta.
 */
class PostularOfertaModalForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'agilpro_fosfec_postular_oferta_modal_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $node = NULL) {
    $oferta_id = is_object($node) ? $node->id() : (int) $node;
    $oferta = is_object($node) ? $node : Node::load($oferta_id);

    $form['#prefix'] = '<div id="postular-oferta-modal-form-wrapper" class="p-3">';
    $form['#suffix'] = '</div>';

    if (!$oferta || $oferta->bundle() !== 'oferta') {
      $form['error'] = [
        '#markup' => '<div class="alert alert-danger">' . $this->t('Oferta no válida.') . '</div>',
      ];
      return $form;
    }

    $form['oferta_id'] = [
      '#type' => 'hidden',
      '#value' => $oferta->id(),
    ];

    $form['intro'] = [
      '#markup' => '<div class="mb-3"><h4 class="text-primary font-weight-bold">' .
        htmlspecialchars($oferta->label(), ENT_QUOTES, 'UTF-8') .
        '</h4><p class="text-muted small">' .
        $this->t('Ingrese el NIT de su empresa para verificar o realizar la postulación en línea.') .
        '</p></div>',
    ];

    $form['search_nit'] = [
      '#type' => 'number',
      '#title' => $this->t('NIT de la Empresa (sin dígito de verificación)'),
      '#attributes' => [
        'placeholder' => 'Ej: 900123456',
        'class' => ['form-control'],
      ],
      '#required' => TRUE,
    ];

    $form['search_dv'] = [
      '#type' => 'number',
      '#title' => $this->t('Dígito de Verificación (DV)'),
      '#attributes' => [
        'placeholder' => 'Ej: 7',
        'class' => ['form-control'],
        'style' => 'max-width: 120px;',
      ],
      '#required' => FALSE,
    ];

    $form['lookup_btn'] = [
      '#type' => 'button',
      '#name' => 'lookup_nit_btn',
      '#value' => $this->t('Consultar Empresa'),
      '#attributes' => [
        'class' => ['btn', 'btn-outline-primary', 'my-2'],
      ],
      '#ajax' => [
        'callback' => '::ajaxLookupNit',
        'wrapper' => 'modal-nit-lookup-result',
      ],
    ];

    $form['lookup_result'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'modal-nit-lookup-result', 'class' => ['my-2']],
    ];

    $form['status_messages'] = [
      '#type' => 'status_messages',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Confirmar Postulación'),
      '#attributes' => [
        'class' => ['btn', 'btn-success', 'btn-lg', 'font-weight-bold'],
      ],
      '#ajax' => [
        'callback' => '::ajaxSubmitForm',
        'wrapper' => 'postular-oferta-modal-form-wrapper',
      ],
    ];

    return $form;
  }

  /**
   * Callback AJAX para la verificación de NIT.
   */
  public function ajaxLookupNit(array &$form, FormStateInterface $form_state) {
    $nit = $form_state->getValue('search_nit');
    $response = new AjaxResponse();

    if (empty($nit)) {
      $markup = '<div class="alert alert-warning py-2 mb-0">' . $this->t('Por favor ingrese un NIT válido.') . '</div>';
      $response->addCommand(new HtmlCommand('#modal-nit-lookup-result', $markup));
      return $response;
    }

    $companies = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties([
      'type' => 'empresa',
      'field_nit' => $nit,
    ]);

    if (!empty($companies)) {
      /** @var \Drupal\node\NodeInterface $company */
      $company = reset($companies);
      $markup = '<div class="alert alert-success py-2 mb-0">' .
        '<strong>' . $this->t('Empresa Encontrada:') . '</strong> ' .
        htmlspecialchars($company->label(), ENT_QUOTES, 'UTF-8') .
        ' (NIT: ' . htmlspecialchars((string) $nit, ENT_QUOTES, 'UTF-8') . ')<br>' .
        '<small class="text-success font-weight-bold">' . $this->t('¡Lista para postularse a la convocatoria!') . '</small>' .
        '</div>';
    }
    else {
      $markup = '<div class="alert alert-info py-2 mb-0">' .
        '<strong>' . $this->t('Empresa no registrada:') . '</strong> ' .
        $this->t('El NIT @nit no está creado. Se creará automáticamente la ficha de empresa con la postulación.', ['@nit' => $nit]) .
        '</div>';
    }

    $response->addCommand(new HtmlCommand('#modal-nit-lookup-result', $markup));
    return $response;
  }

  /**
   * Callback AJAX al enviar la postulación.
   */
  public function ajaxSubmitForm(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();

    if ($form_state->hasAnyErrors()) {
      $response->addCommand(new HtmlCommand('#postular-oferta-modal-form-wrapper', $form));
      return $response;
    }

    $oferta_id = $form_state->getValue('oferta_id');
    $nit = $form_state->getValue('search_nit');
    $dv = $form_state->getValue('search_dv');

    $oferta = Node::load($oferta_id);
    if (!$oferta) {
      $markup = '<div class="alert alert-danger">' . $this->t('Oferta no encontrada.') . '</div>';
      $response->addCommand(new HtmlCommand('#postular-oferta-modal-form-wrapper', $markup));
      return $response;
    }

    // 1. Obtener o crear Empresa
    $companies = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties([
      'type' => 'empresa',
      'field_nit' => $nit,
    ]);

    if (!empty($companies)) {
      $company = reset($companies);
    }
    else {
      $company = Node::create([
        'type' => 'empresa',
        'title' => 'Empresa NIT ' . $nit,
        'field_nit' => (int) $nit,
        'field__dv' => $dv ? (int) $dv : 0,
        'status' => 1,
      ]);
      $company->save();
    }

    // 2. Verificar si ya existe postulación para esta empresa y oferta
    $inscripciones = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties([
      'type' => 'inscripcion_oferta',
      'field_oferta_ref' => $oferta->id(),
      'field_empresa_ref' => $company->id(),
    ]);

    if (!empty($inscripciones)) {
      $markup = '<div class="alert alert-warning text-center p-3">' .
        '<h5 class="font-weight-bold">' . $this->t('Ya se encuentra postulado') . '</h5>' .
        '<p>' . $this->t('La empresa <strong>@empresa</strong> ya cuenta con una inscripción activa en la convocatoria <em>@oferta</em>.', [
          '@empresa' => $company->label(),
          '@oferta' => $oferta->label(),
        ]) . '</p>' .
        '</div>';
      $response->addCommand(new HtmlCommand('#postular-oferta-modal-form-wrapper', $markup));
      return $response;
    }

    // 3. Crear nodo inscripcion_oferta
    $current_user = \Drupal::currentUser();
    $inscripcion = Node::create([
      'type' => 'inscripcion_oferta',
      'field_oferta_ref' => $oferta->id(),
      'field_empresa_ref' => $company->id(),
      'field_estado_postulacion' => 'recibida',
      'status' => 1,
      'uid' => $current_user->id() ?: 1,
    ]);

    if ($current_user->isAuthenticated() && $inscripcion->hasField('field_colaborador_ref')) {
      $inscripcion->set('field_colaborador_ref', $current_user->id());
    }

    $inscripcion->save();

    // 4. Reducir cupos disponibles si aplica
    if ($oferta->hasField('field_cupos_disponibles') && !$oferta->get('field_cupos_disponibles')->isEmpty()) {
      $cupos = (int) $oferta->get('field_cupos_disponibles')->value;
      if ($cupos > 0) {
        $oferta->set('field_cupos_disponibles', $cupos - 1);
        $oferta->save();
      }
    }

    $markup = '<div class="alert alert-success text-center p-4">' .
      '<h4 class="font-weight-bold mb-2">🎉 ' . $this->t('¡Postulación Registrada con Éxito!') . '</h4>' .
      '<p class="mb-2">' . $this->t('Se ha creado el registro formal <strong>@title</strong> para la empresa <strong>@empresa</strong>.', [
        '@title' => $inscripcion->label(),
        '@empresa' => $company->label(),
      ]) . '</p>' .
      '<p class="text-muted small mb-0">' . $this->t('Nuestro equipo de asesores revisará los documentos y se pondrá en contacto.') . '</p>' .
      '</div>';

    $response->addCommand(new HtmlCommand('#postular-oferta-modal-form-wrapper', $markup));
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // La lógica se gestiona de forma interactiva en ajaxSubmitForm.
  }

}

