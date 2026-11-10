<?php

declare(strict_types=1);

namespace Drupal\agilpro_org\Plugin\Block;

use Drupal\agilpro_org\Service\RadarChartBuilder;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Bloque dual de gráficos Radar (Capacidades + Emprendimiento) por usuario.
 *
 * Reemplaza 'block_user_chart' de Drupal 7, resolviendo el usuario desde la
 * ruta actual (o segmento numérico de URL) con fallback al usuario autenticado.
 */
#[Block(
  id: 'agilpro_user_dual_radar_block',
  admin_label: new TranslatableMarkup('Radar chart user'),
  category: new TranslatableMarkup('AgilPro.org'),
)]
final class UserDualRadarBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly RadarChartBuilder $chartBuilder,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly RouteMatchInterface $routeMatch,
    protected readonly RequestStack $requestStack,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('agilpro_org.radar_chart_builder'),
      $container->get('current_user'),
      $container->get('current_route_match'),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $uid = $this->resolveTargetUid();
    return $this->chartBuilder->buildDualUserCharts($uid);
  }

  /**
   * Resuelve el UID desde el parámetro de ruta 'user', el path o el usuario actual.
   */
  private function resolveTargetUid(): int {
    $routeUser = $this->routeMatch->getParameter('user');
    if ($routeUser instanceof UserInterface) {
      return (int) $routeUser->id();
    }
    if (is_numeric($routeUser)) {
      return (int) $routeUser;
    }

    $path = trim((string) $this->requestStack->getCurrentRequest()?->getPathInfo(), '/');
    $segments = $path !== '' ? explode('/', $path) : [];
    if (isset($segments[1]) && ctype_digit($segments[1])) {
      return (int) $segments[1];
    }

    return (int) $this->currentUser->id();
  }

}

