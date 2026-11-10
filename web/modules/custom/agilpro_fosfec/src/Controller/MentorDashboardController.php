<?php

declare(strict_types=1);

namespace Drupal\agilpro_fosfec\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controlador para el Espacio de Trabajo del Mentor (/mi-espacio-mentor).
 */
class MentorDashboardController extends ControllerBase {

  /**
   * Redirige o renderiza el dashboard de mentoría.
   */
  public function dashboard(Request $request) {
    // Redirigir por defecto a la vista de empresas asignadas
    return new RedirectResponse(Url::fromRoute('view.mentor_empresas_asignadas.page_1')->toString());
  }

}
