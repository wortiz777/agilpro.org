<?php

declare(strict_types=1);

namespace Drupal\agilpro_fosfec\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Url;

/**
 * Controlador para la página pública de catálogo de convocatorias (/ofertas).
 */
class OfertasCatalogoController extends ControllerBase {

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    protected readonly DateFormatterInterface $dateFormatter,
  ) {
    $this->entityTypeManager = $entityTypeManager;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter')
    );
  }

  /**
   * Catálogo de convocatorias vigentes.
   */
  public function catalogoPage(Request $request): array {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'oferta')
      ->condition('status', 1)
      ->sort('created', 'DESC');

    $nids = $query->execute();
    /** @var \Drupal\node\NodeInterface[] $nodes */
    $nodes = !empty($nids) ? $this->entityTypeManager->getStorage('node')->loadMultiple($nids) : [];

    $ofertas = [];
    $today = date('Y-m-d');

    foreach ($nodes as $node) {
      $tot = $node->hasField('field_cupos_totales') && !$node->get('field_cupos_totales')->isEmpty()
        ? (int) $node->get('field_cupos_totales')->value : 0;
      $disp = $node->hasField('field_cupos_disponibles') && !$node->get('field_cupos_disponibles')->isEmpty()
        ? (int) $node->get('field_cupos_disponibles')->value : 0;

      $fechas_str = 'Abierta permanentemente';
      $in_dates = TRUE;
      if ($node->hasField('field_fechas_inscripcion') && !$node->get('field_fechas_inscripcion')->isEmpty()) {
        $f = $node->get('field_fechas_inscripcion')->first();
        if ($f) {
          $fi = substr($f->value, 0, 10);
          $ff = substr($f->end_value, 0, 10);
          $fechas_str = $this->dateFormatter->format(strtotime($fi), 'custom', 'd M Y') . ' al ' . $this->dateFormatter->format(strtotime($ff), 'custom', 'd M Y');
          $in_dates = ($today >= $fi && $today <= $ff);
        }
      }

      $img_url = NULL;
      if ($node->hasField('field_imagen_oferta') && !$node->get('field_imagen_oferta')->isEmpty()) {
        $file = $node->get('field_imagen_oferta')->entity;
        if ($file) {
          $img_url = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
        }
      }

      $modalidad = 'Híbrida / Virtual';
      if ($node->hasField('field_modalidad_participacion') && !$node->get('field_modalidad_participacion')->isEmpty()) {
        $allowed = $node->get('field_modalidad_participacion')->getFieldDefinition()->getSetting('allowed_values');
        $val = $node->get('field_modalidad_participacion')->value;
        $modalidad = $allowed[$val] ?? ucfirst($val);
      }

      $eje = $node->hasField('field_eje_default') && !$node->get('field_eje_default')->isEmpty() ? $node->get('field_eje_default')->entity : NULL;
      $linea = $node->hasField('field_linea_default') && !$node->get('field_linea_default')->isEmpty() ? $node->get('field_linea_default')->entity : NULL;

      // Extract brief description
      $desc = '';
      if ($node->hasField('field_landing_content') && !$node->get('field_landing_content')->isEmpty()) {
        $desc = strip_tags($node->get('field_landing_content')->value);
        if (mb_strlen($desc) > 220) {
          $desc = mb_substr($desc, 0, 220) . '...';
        }
      }

      $alias = \Drupal::service('path_alias.manager')->getAliasByPath('/node/' . $node->id());
      $is_open = ($disp > 0 || $tot === 0) && $in_dates;

      $ofertas[] = [
        'id' => $node->id(),
        'title' => $node->label(),
        'url' => $alias,
        'image_url' => $img_url,
        'modalidad' => $modalidad,
        'fechas' => $fechas_str,
        'cupos_disponibles' => $disp,
        'cupos_totales' => $tot,
        'is_open' => $is_open,
        'eje' => $eje ? $eje->label() : NULL,
        'linea' => $linea ? $linea->label() : NULL,
        'summary' => $desc,
      ];
    }

    return [
      '#theme' => 'ofertas_catalogo_page',
      '#ofertas' => $ofertas,
      '#attached' => [
        'library' => [
          'agilpro_fosfec/agilpro_fosfec_oferta',
          'agilpro_fosfec/agilpro_fosfec_dashboard',
        ],
      ],
    ];
  }

}
