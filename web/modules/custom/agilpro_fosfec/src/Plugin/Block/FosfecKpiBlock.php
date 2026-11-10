<?php

namespace Drupal\agilpro_fosfec\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Provee el bloque de Scorecard de KPIs de FOSFEC.
 *
 * @Block(
 *   id = "fosfec_kpi_block",
 *   admin_label = @Translation("FOSFEC KPI Scorecard"),
 *   category = @Translation("Fomento Empresarial")
 * )
 */
class FosfecKpiBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new FosfecKpiBlock.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $node_storage = $this->entityTypeManager->getStorage('node');

    // 1. Ofertas Activas: Publicadas de tipo oferta
    $ofertas_activas = (int) $node_storage->getQuery()
      ->condition('type', 'oferta')
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->count()
      ->execute();

    // 2. Total Mipymes Postuladas: Conteo de nodos inscripcion_oferta
    $total_postuladas = (int) $node_storage->getQuery()
      ->condition('type', 'inscripcion_oferta')
      ->accessCheck(FALSE)
      ->count()
      ->execute();

    // 3. % Tasa de Conversión / Ocupación promedio de cupos
    $ofertas = $node_storage->loadByProperties(['type' => 'oferta']);
    $total_cupos = 0;
    $cupos_ocupados = 0;
    foreach ($ofertas as $oferta) {
      $tot = $oferta->hasField('field_cupos_totales') && !$oferta->get('field_cupos_totales')->isEmpty() ? (int) $oferta->get('field_cupos_totales')->value : 0;
      $disp = $oferta->hasField('field_cupos_disponibles') && !$oferta->get('field_cupos_disponibles')->isEmpty() ? (int) $oferta->get('field_cupos_disponibles')->value : 0;
      if ($tot > 0) {
        $total_cupos += $tot;
        $cupos_ocupados += max(0, $tot - $disp);
      }
    }
    $tasa_conversion = $total_cupos > 0 ? round(($cupos_ocupados / $total_cupos) * 100, 1) : 0;

    // 4. Horas FOSFEC Ejecutadas: Sumatoria de field_duracion_horas en transacciones
    $db = \Drupal::database();
    $query = $db->select('node__field_duracion_horas', 'h');
    $query->join('node_field_data', 'n', 'n.nid = h.entity_id');
    $query->condition('n.type', 'fosfec_transaction');
    $query->addExpression('SUM(h.field_duracion_horas_value)', 'total_horas');
    $horas_ejecutadas = (float) $query->execute()->fetchField();

    return [
      '#theme' => 'fosfec_kpi_scorecard',
      '#kpis' => [
        'ofertas_activas' => $ofertas_activas,
        'total_postuladas' => $total_postuladas,
        'tasa_conversion' => $tasa_conversion,
        'horas_ejecutadas' => number_format($horas_ejecutadas, 1),
      ],
      '#cache' => [
        'tags' => ['node_list:oferta', 'node_list:inscripcion_oferta', 'node_list:fosfec_transaction'],
        'max-age' => 60,
      ],
    ];
  }

}
