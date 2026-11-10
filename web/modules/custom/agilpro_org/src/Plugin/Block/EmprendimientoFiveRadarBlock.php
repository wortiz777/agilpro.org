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
 * Bloque del gráfico Radar de las 5 áreas de 360° Emprendimiento.
 *
 * Reemplaza 'block_five_chart' de Drupal 7.
 */
#[Block(
  id: 'agilpro_emprendimiento_five_radar_block',
  admin_label: new TranslatableMarkup('Radar chart emprendimientos'),
  category: new TranslatableMarkup('AgilPro.org'),
)]
final class EmprendimientoFiveRadarBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
    return $this->chartBuilder->buildEmprendimientoFiveChart($uid);
  }

}

