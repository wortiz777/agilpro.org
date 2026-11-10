<?php

namespace Drupal\agilpro_fosfec\EventSubscriber;

use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Suscriptor de eventos para las transiciones del Workflow FOSFEC.
 */
class FosfecWorkflowSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events = [];
    // Guard previo a la transición 'registrar_ejecucion'
    $events['fosfec_transaction_workflow.registrar_ejecucion.pre_transition'][] = ['guardRegistrarEjecucion'];
    // Evento posterior a 'confirmar_aceptacion'
    $events['fosfec_transaction_workflow.confirmar_aceptacion.post_transition'][] = ['onConfirmarAceptacion'];
    return $events;
  }

  /**
   * Valida que exista evidencia adjunta antes de registrar ejecución.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   El evento de transición.
   */
  public function guardRegistrarEjecucion(WorkflowTransitionEvent $event) {
    /** @var \Drupal\node\NodeInterface $node */
    $node = $event->getEntity();
    if ($node->getEntityTypeId() === 'node' && $node->bundle() === 'fosfec_transaction') {
      if ($node->get('field_evidencia_documento')->isEmpty()) {
        throw new \InvalidArgumentException($this->t('No es posible marcar la intervención como "Ejecutada" sin adjuntar un archivo de evidencia válido (PDF, DOCX, XLSX).'));
      }
    }
  }

  /**
   * Acciones al confirmar aceptación por el empresario.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   El evento de transición.
   */
  public function onConfirmarAceptacion(WorkflowTransitionEvent $event) {
    /** @var \Drupal\node\NodeInterface $node */
    $node = $event->getEntity();
    if ($node->getEntityTypeId() === 'node' && $node->bundle() === 'fosfec_transaction') {
      $request = \Drupal::request();
      $ip = $request->getClientIp() ?: '127.0.0.1';
      $now = gmdate('Y-m-d\TH:i:s');

      $node->set('field_aceptacion_digital', 1);
      $node->set('field_log_ip_aceptacion', substr($ip, 0, 45));
      $node->set('field_log_timestamp_aceptacion', $now);

      // Disparar encolamiento hacia OnBase si existe el servicio/cola
      try {
        if (\Drupal::hasService('queue')) {
          $queue = \Drupal::queue('agilpro_onbase_upload_queue');
          $queue->createItem([
            'nid' => $node->id(),
            'title' => $node->label(),
            'timestamp' => time(),
            'ip' => $ip,
          ]);
        }
      }
      catch (\Exception $e) {
        \Drupal::logger('agilpro_fosfec')->error('Error al encolar documento hacia OnBase: @msg', ['@msg' => $e->getMessage()]);
      }

      $node->save();
    }
  }

}

