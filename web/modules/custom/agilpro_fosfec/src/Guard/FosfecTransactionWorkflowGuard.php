<?php

declare(strict_types=1);

namespace Drupal\agilpro_fosfec\Guard;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\state_machine\Guard\GuardInterface;
use Drupal\state_machine\Plugin\Workflow\WorkflowInterface;
use Drupal\state_machine\Plugin\Workflow\WorkflowTransition;

/**
 * Controla permisos de transición en el workflow transaccional FOSFEC.
 *
 * Restricciones:
 * - 'registrar_ejecucion': permitida para mentor_consultor, gestor_contenido, coordinador_fosfec y administradores.
 * - 'confirmar_aceptacion': exclusiva de admin_empresa, colaborador y administradores (NO mentor_consultor).
 * - 'congelar_auditoria': exclusiva de coordinador_fosfec, auditor_fosfec y administradores (NO mentor_consultor).
 */
class FosfecTransactionWorkflowGuard implements GuardInterface {

  public function __construct(
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function allowed(WorkflowTransition $transition, WorkflowInterface $workflow, EntityInterface $entity): bool {
    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'fosfec_transaction') {
      return TRUE;
    }

    $roles = $this->currentUser->getRoles();
    $transitionId = $transition->getId();

    // Administradores y Super Admin tienen acceso total
    if (in_array('administrator', $roles, TRUE) || in_array('super_admin', $roles, TRUE)) {
      return TRUE;
    }

    // Restricciones para el rol mentor_consultor
    if (in_array('mentor_consultor', $roles, TRUE) && !in_array('coordinador_fosfec', $roles, TRUE)) {
      // El mentor solo puede transicionar a 'ejecutada'
      if ($transitionId === 'confirmar_aceptacion' || $transitionId === 'congelar_auditoria') {
        return FALSE;
      }
    }

    // 'congelar_auditoria' solo permitida para coordinadores y auditores
    if ($transitionId === 'congelar_auditoria') {
      if (!in_array('coordinador_fosfec', $roles, TRUE) && !in_array('auditor_fosfec', $roles, TRUE)) {
        return FALSE;
      }
    }

    return TRUE;
  }

}

