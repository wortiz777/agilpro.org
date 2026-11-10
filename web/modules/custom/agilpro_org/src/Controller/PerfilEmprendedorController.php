<?php

declare(strict_types=1);

namespace Drupal\agilpro_org\Controller;

use Drupal\agilpro_org\Service\RadarChartBuilder;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controlador de la ruta '/perfil-emprendedor-custom'.
 *
 * Muestra el gráfico radar de Capacidades Emprendedoras (basado en Webform)
 * exclusivamente a usuarios con el rol 'emprendedor_registrado'.
 */
final class PerfilEmprendedorController extends ControllerBase {

  public function __construct(
    protected readonly RadarChartBuilder $chartBuilder,
    AccountProxyInterface $currentUser,
  ) {
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('agilpro_org.radar_chart_builder'),
      $container->get('current_user'),
    );
  }

  /**
   * Construye la página del Autodiagnóstico 360° Emprendedor.
   *
   * @return array<string, mixed>
   *   Render array del gráfico radar o mensaje informativo si no hay envío.
   */
  public function build(): array {
    $uid = (int) $this->currentUser()->id();
    $chart = $this->chartBuilder->buildCapacidadesChart($uid);

    if (!isset($chart['#type'])) {
      return [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Aún no se registran respuestas en el diagnóstico 360° de Capacidades Emprendedoras.') . '</p>',
        '#cache' => $chart['#cache'] ?? [
          'contexts' => ['user'],
          'tags' => ['webform_submission_list', 'user:' . $uid],
        ],
      ];
    }

    return $chart;
  }

}

