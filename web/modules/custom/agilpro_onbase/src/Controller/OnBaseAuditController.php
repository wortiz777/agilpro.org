<?php

declare(strict_types=1);

namespace Drupal\agilpro_onbase\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controlador administrativo de auditoría y trazabilidad de transferencias OnBase.
 */
class OnBaseAuditController extends ControllerBase {

  public function __construct(
    protected readonly Connection $database,
    protected readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('database'),
      $container->get('date.formatter')
    );
  }

  /**
   * Muestra la tabla de trazabilidad y auditoría de documentos transferidos a OnBase.
   */
  public function overview(Request $request): array {
    $build = [];

    // 1. Tarjetas de métricas y estadísticas resumidas
    $counts = [
      'TOTAL' => 0,
      'SUCCESS' => 0,
      'FAILED_RETRY' => 0,
      'FAILED' => 0,
    ];

    if ($this->database->schema()->tableExists('agilpro_onbase_audit_log')) {
      $queryCounts = $this->database->select('agilpro_onbase_audit_log', 'a')
        ->fields('a', ['status'])
        ->groupBy('status');
      $queryCounts->addExpression('COUNT(id)', 'cnt');
      $results = $queryCounts->execute()->fetchAllKeyed();

      foreach ($results as $status => $cnt) {
        $counts[$status] = (int) $cnt;
        $counts['TOTAL'] += (int) $cnt;
      }
    }

    $build['summary_cards'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['onbase-stats-cards'], 'style' => 'display:flex; gap:15px; margin-bottom:20px;'],
      'card_total' => [
        '#markup' => '<div style="flex:1; background:#f8f9fa; border:1px solid #dee2e6; border-radius:6px; padding:15px; text-align:center;">'
          . '<h4 style="margin:0; color:#6c757d; font-size:13px; text-transform:uppercase;">Total Registros</h4>'
          . '<span style="font-size:26px; font-weight:bold; color:#212529;">' . $counts['TOTAL'] . '</span>'
          . '</div>',
      ],
      'card_success' => [
        '#markup' => '<div style="flex:1; background:#e8f7ee; border:1px solid #c3e6cb; border-radius:6px; padding:15px; text-align:center;">'
          . '<h4 style="margin:0; color:#155724; font-size:13px; text-transform:uppercase;">Exitosos (En OnBase)</h4>'
          . '<span style="font-size:26px; font-weight:bold; color:#28a745;">' . $counts['SUCCESS'] . '</span>'
          . '</div>',
      ],
      'card_failed' => [
        '#markup' => '<div style="flex:1; background:#fdf0f0; border:1px solid #f5c6cb; border-radius:6px; padding:15px; text-align:center;">'
          . '<h4 style="margin:0; color:#721c24; font-size:13px; text-transform:uppercase;">Fallidos / Requieren Atención</h4>'
          . '<span style="font-size:26px; font-weight:bold; color:#dc3545;">' . ($counts['FAILED'] + ($counts['FAILED_FATAL'] ?? 0)) . '</span>'
          . '</div>',
      ],
    ];

    // 2. Formulario de filtro simple
    $statusFilter = $request->query->get('status', 'all');

    // 3. Consulta paginada con tabla de Drupal
    $header = [
      ['data' => $this->t('ID Log'), 'field' => 'id', 'sort' => 'desc'],
      ['data' => $this->t('Mentoría (Entidad)'), 'field' => 'entity_id'],
      ['data' => $this->t('FID Archivo'), 'field' => 'file_id'],
      ['data' => $this->t('Document Handle OnBase'), 'field' => 'document_handle'],
      ['data' => $this->t('Estado'), 'field' => 'status'],
      ['data' => $this->t('Reintentos'), 'field' => 'retries'],
      ['data' => $this->t('Fecha y Hora'), 'field' => 'created'],
      ['data' => $this->t('Acciones')],
    ];

    $rows = [];

    if ($this->database->schema()->tableExists('agilpro_onbase_audit_log')) {
      $query = $this->database->select('agilpro_onbase_audit_log', 'a')
        ->extend('Drupal\Core\Database\Query\PagerSelectExtender')
        ->extend('Drupal\Core\Database\Query\TableSortExtender');

      $query->fields('a', [
        'id', 'entity_id', 'file_id', 'document_handle',
        'status', 'retries', 'metadata_payload', 'response_message', 'created',
      ]);

      if ($statusFilter !== 'all' && !empty($statusFilter)) {
        $query->condition('status', $statusFilter);
      }

      $query->limit(25)->orderByHeader($header);
      $records = $query->execute()->fetchAll();

      foreach ($records as $record) {
        $badgeClass = match ($record->status) {
          'SUCCESS' => 'background:#28a745; color:#fff;',
          'FAILED' => 'background:#dc3545; color:#fff;',
          default => 'background:#ffc107; color:#212529;',
        };

        $statusBadge = '<span style="display:inline-block; padding:3px 8px; border-radius:4px; font-size:12px; font-weight:bold; ' . $badgeClass . '">'
          . Html::escape($record->status) . '</span>';

        $actions = [];
        if ($record->status !== 'SUCCESS') {
          $actions[] = Link::fromTextAndUrl(
            $this->t('Reintentar'),
            Url::fromRoute('agilpro_onbase.requeue', ['id' => $record->id])
          )->toRenderable();
        }

        $formattedDate = $this->dateFormatter->format((int) $record->created, 'short');

        $rows[] = [
          'id' => $record->id,
          'entity_id' => $record->entity_id,
          'file_id' => $record->file_id,
          'document_handle' => !empty($record->document_handle) ? '<code>' . Html::escape($record->document_handle) . '</code>' : '-',
          'status' => ['data' => ['#markup' => $statusBadge]],
          'retries' => $record->retries,
          'created' => $formattedDate,
          'actions' => [
            'data' => [
              '#type' => 'container',
              'content' => $actions,
            ],
          ],
        ];
      }
    }

    $build['audit_table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No se han registrado transferencias hacia OnBase hasta el momento.'),
      '#attributes' => ['class' => ['onbase-audit-table']],
    ];

    $build['pager'] = [
      '#type' => 'pager',
    ];

    return $build;
  }

}

