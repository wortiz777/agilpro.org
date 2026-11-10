<?php

namespace Drupal\agilpro_fosfec\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\node\NodeInterface;

/**
 * Controlador para abrir el modal AJAX de postulación a oferta.
 */
class PostularOfertaModalController extends ControllerBase {

  /**
   * Abre el modal AJAX con el formulario B2B lookup.
   */
  public function openModal(NodeInterface $node) {
    $form = $this->formBuilder()->getForm('Drupal\agilpro_fosfec\Form\PostularOfertaModalForm', $node);

    $response = new AjaxResponse();
    $title = $this->t('Postular mi Empresa a la Oferta');
    $options = [
      'width' => '650px',
      'dialogClass' => 'modal-postular-oferta',
    ];

    $response->addCommand(new OpenModalDialogCommand($title, $form, $options));
    return $response;
  }

}

