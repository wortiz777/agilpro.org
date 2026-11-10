<?php

declare(strict_types=1);

namespace Drupal\agilpro_org\Plugin\Block;

use Drupal\agilpro_org\Service\RadarChartBuilder;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Bloque del gráfico Radar de Capacidades Emprendedoras (360° Emprendedor).
 *
 * Reemplaza 'block_c_emprendedoras_chart' de Drupal 7.
 */
#[Block(
  id: 'agilpro_capacidades_radar_block',
  admin_label: new TranslatableMarkup('Radar chart capacidades emprendedoras'),
  category: new TranslatableMarkup('AgilPro.org'),
)]
final class CapacidadesEmprendedorasRadarBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly RadarChartBuilder $chartBuilder,
    protected readonly AccountProxyInterface $currentUser,
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
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $uid = (int) $this->currentUser->id();
    return $this->chartBuilder->buildCapacidadesChart($uid);
  }

}

