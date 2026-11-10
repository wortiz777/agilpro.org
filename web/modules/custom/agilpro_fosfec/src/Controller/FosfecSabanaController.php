<?php

namespace Drupal\agilpro_fosfec\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\agilpro_fosfec\Service\FosfecExporterService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;

/**
 * Controlador administrativo para la exportación de la Sábana FOSFEC.
 */
class FosfecSabanaController extends ControllerBase {

  protected FosfecExporterService $exporter;
  protected DateFormatterInterface $dateFormatter;

  public function __construct(FosfecExporterService $exporter, DateFormatterInterface $dateFormatter) {
    $this->exporter = $exporter;
    $this->dateFormatter = $dateFormatter;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('agilpro_fosfec.exporter'),
      $container->get('date.formatter')
    );
  }

  /**
   * Vista previa y formulario de descarga.
   */
  public function reportPage(Request $request) {
    $form = \Drupal::formBuilder()->getForm('Drupal\agilpro_fosfec\Form\FosfecSabanaFilterForm');

    // Cargar últimas transacciones registradas para previsualización
    $query = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'fosfec_transaction')
      ->sort('created', 'DESC')
      ->range(0, 15);
    $nids = $query->execute();
    /** @var \Drupal\node\NodeInterface[] $nodes */
    $nodes = !empty($nids) ? \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids) : [];

    $rows = [];
    foreach ($nodes as $node) {
      $empresa = $node->hasField('field_empresa_beneficiaria') && !$node->get('field_empresa_beneficiaria')->isEmpty() ? $node->get('field_empresa_beneficiaria')->entity : NULL;
      $nit = $empresa && $empresa->hasField('field_nit') ? $empresa->get('field_nit')->value : '-';
      $razon = $empresa ? $empresa->label() : 'Sin Empresa';

      $mentor = $node->hasField('field_mentor_consultor') && !$node->get('field_mentor_consultor')->isEmpty() ? $node->get('field_mentor_consultor')->entity : NULL;
      $mentorName = $mentor ? $mentor->getDisplayName() : '-';

      $ruta = $node->hasField('field_ruta_atencion') && !$node->get('field_ruta_atencion')->isEmpty() ? $node->get('field_ruta_atencion')->entity : NULL;
      $rutaName = $ruta ? $ruta->label() : '-';

      $horas = $node->hasField('field_duracion_horas') && !$node->get('field_duracion_horas')->isEmpty() ? $node->get('field_duracion_horas')->value : '0';
      $state = $node->hasField('field_state') && !$node->get('field_state')->isEmpty() ? $node->get('field_state')->value : 'programada';

      $rows[] = [
        $node->id(),
        $nit,
        $razon,
        $mentorName,
        $rutaName,
        $horas . ' h',
        ucfirst(str_replace('_', ' ', $state)),
        $this->dateFormatter->format($node->getCreatedTime(), 'short'),
      ];
    }

    $previewTable = [
      '#type' => 'table',
      '#header' => [
        $this->t('ID'),
        $this->t('NIT'),
        $this->t('Empresa'),
        $this->t('Mentor'),
        $this->t('Ruta de Atención'),
        $this->t('Horas'),
        $this->t('Estado'),
        $this->t('Fecha Creación'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No hay transacciones FOSFEC registradas actualmente.'),
      '#attributes' => ['class' => ['table', 'table-striped', 'table-hover']],
    ];

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['fosfec-sabana-page', 'container-fluid', 'py-3']],
      'header' => [
        '#type' => 'inline_template',
        '#template' => '
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
              <h1 class="page-title">{{ title }}</h1>
              <p class="text-muted">{{ desc }}</p>
            </div>
            <div>
              <a href="/admin/agilpro/dashboard" class="btn btn-default btn-sm">⬅ Volver al Tablero de Control</a>
            </div>
          </div>
        ',
        '#context' => [
          'title' => $this->t('Sábana y Reporte de Auditables FOSFEC'),
          'desc' => $this->t('Exporte las 21 columnas auditables según la especificación técnica FOSFEC Compensar o previsualice los registros más recientes.'),
        ],
      ],
      'filters_card' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['panel', 'panel-default', 'mb-4', 'p-3']],
        'form' => $form,
      ],
      'preview_section' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['panel', 'panel-default', 'p-3']],
        'title' => [
          '#markup' => '<h3 class="panel-title mb-3 font-weight-bold">' . $this->t('Vista Previa de Transacciones Auditables') . '</h3>',
        ],
        'table' => $previewTable,
      ],
      '#attached' => [
        'library' => [
          'agilpro_fosfec/agilpro_fosfec_dashboard',
        ],
      ],
    ];
  }

  /**
   * Descarga directa del archivo Excel (.xlsx).
   */
  public function downloadExport(Request $request) {
    $filters = [
      'aliado_id' => $request->query->get('aliado_id'),
      'eje_id' => $request->query->get('eje_id'),
      'state' => $request->query->get('state'),
    ];

    $filePath = $this->exporter->generateSabana($filters);
    $filename = 'Sabana_FOSFEC_Compensar_' . date('Ymd_His') . '.xlsx';

    $response = new BinaryFileResponse($filePath);
    $response->setContentDisposition(
      ResponseHeaderBag::DISPOSITION_ATTACHMENT,
      $filename
    );
    $response->deleteFileAfterSend(true);

    return $response;
  }

}

