<?php

namespace Drupal\agilpro_fosfec\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controlador para el Dashboard Central de FOSFEC y Vistas de Seguimiento.
 */
class FosfecDashboardController extends ControllerBase {

  /**
   * Renderiza el Dashboard principal con KPIs, filtros e interfaces de seguimiento.
   */
  public function dashboardPage(Request $request) {
    // 1. Bloque de Scorecard KPI
    $kpi_block = \Drupal::service('plugin.manager.block')
      ->createInstance('fosfec_kpi_block', [])
      ->build();

    // 2. Parámetros de filtros activos
    $active_tab = $request->query->get('tab', 'inscripciones');
    $filter_oferta = $request->query->get('oferta', '');
    $filter_estado = $request->query->get('estado', '');
    $filter_nit = trim($request->query->get('nit', ''));
    $filter_fecha_desde = $request->query->get('desde', '');
    $filter_fecha_hasta = $request->query->get('hasta', '');

    // 3. Obtener listado de ofertas para selectores
    $ofertas = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties([
      'type' => 'oferta',
    ]);
    $ofertas_options = [];
    foreach ($ofertas as $of) {
      $ofertas_options[$of->id()] = $of->label();
    }

    // 4. Datos: Tablero 1 - Ofertas de Fomento
    $ofertas_data = [];
    foreach ($ofertas as $of) {
      $tot = $of->hasField('field_cupos_totales') && !$of->get('field_cupos_totales')->isEmpty() ? (int) $of->get('field_cupos_totales')->value : 0;
      $disp = $of->hasField('field_cupos_disponibles') && !$of->get('field_cupos_disponibles')->isEmpty() ? (int) $of->get('field_cupos_disponibles')->value : 0;
      $ocupados = max(0, $tot - $disp);
      $pct = $tot > 0 ? round(($ocupados / $tot) * 100, 1) : 0;

      $fechas_str = 'Sin definir';
      if ($of->hasField('field_fechas_inscripcion') && !$of->get('field_fechas_inscripcion')->isEmpty()) {
        $f = $of->get('field_fechas_inscripcion')->first();
        if ($f) {
          $fechas_str = substr($f->value, 0, 10) . ' al ' . substr($f->end_value, 0, 10);
        }
      }

      $alias = \Drupal::service('path_alias.manager')->getAliasByPath('/node/' . $of->id());

      $ofertas_data[] = [
        'id' => $of->id(),
        'title' => $of->label(),
        'alias' => $alias,
        'status' => $of->isPublished(),
        'cupos_totales' => $tot,
        'cupos_disponibles' => $disp,
        'cupos_ocupados' => $ocupados,
        'pct_ocupacion' => $pct,
        'fechas' => $fechas_str,
        'modalidad' => $of->hasField('field_modalidad_participacion') && !$of->get('field_modalidad_participacion')->isEmpty() ? $of->get('field_modalidad_participacion')->value : 'N/A',
        'url_view' => $alias,
        'url_edit' => Url::fromRoute('entity.node.edit_form', ['node' => $of->id()])->toString(),
      ];
    }

    // 5. Datos: Tablero 2 - Pipeline de Postulaciones (Inscripciones)
    $inscripciones_query = \Drupal::entityQuery('node')
      ->condition('type', 'inscripcion_oferta')
      ->accessCheck(FALSE)
      ->sort('created', 'DESC');

    if (!empty($filter_oferta)) {
      $inscripciones_query->condition('field_oferta_ref', $filter_oferta);
    }
    if (!empty($filter_estado)) {
      $inscripciones_query->condition('field_estado_postulacion', $filter_estado);
    }

    $inscripciones_nids = $inscripciones_query->execute();
    $inscripciones = !empty($inscripciones_nids) ? \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($inscripciones_nids) : [];

    $inscripciones_data = [];
    foreach ($inscripciones as $ins) {
      $empresa = $ins->hasField('field_empresa_ref') && !$ins->get('field_empresa_ref')->isEmpty() ? $ins->get('field_empresa_ref')->entity : NULL;
      $nit = $empresa && $empresa->hasField('field_nit') && !$empresa->get('field_nit')->isEmpty() ? (string) $empresa->get('field_nit')->value : '';

      // Filtro por NIT
      if (!empty($filter_nit) && strpos($nit, $filter_nit) === FALSE) {
        continue;
      }

      $oferta_ref = $ins->hasField('field_oferta_ref') && !$ins->get('field_oferta_ref')->isEmpty() ? $ins->get('field_oferta_ref')->entity : NULL;
      $mentor_ref = $ins->hasField('field_mentor_asignado') && !$ins->get('field_mentor_asignado')->isEmpty() ? $ins->get('field_mentor_asignado')->entity : NULL;
      $evaluador_ref = $ins->hasField('field_evaluador_asignado') && !$ins->get('field_evaluador_asignado')->isEmpty() ? $ins->get('field_evaluador_asignado')->entity : NULL;
      $ruta_ref = $ins->hasField('field_ruta_asignada') && !$ins->get('field_ruta_asignada')->isEmpty() ? $ins->get('field_ruta_asignada')->entity : NULL;

      $estado_raw = $ins->hasField('field_estado_postulacion') && !$ins->get('field_estado_postulacion')->isEmpty() ? $ins->get('field_estado_postulacion')->value : 'postulada';
      $badge_class = 'badge-secondary';
      if ($estado_raw === 'aprobada') $badge_class = 'badge-success';
      elseif ($estado_raw === 'rechazada') $badge_class = 'badge-danger';
      elseif ($estado_raw === 'en_evaluacion') $badge_class = 'badge-warning text-dark';
      elseif ($estado_raw === 'postulada') $badge_class = 'badge-info';

      $inscripciones_data[] = [
        'id' => $ins->id(),
        'title' => $ins->label(),
        'created' => date('d/m/Y H:i', (int) $ins->getCreatedTime()),
        'empresa_nombre' => $empresa ? $empresa->label() : 'Sin Empresa',
        'empresa_nit' => $nit ?: 'S/N',
        'oferta_nombre' => $oferta_ref ? $oferta_ref->label() : 'Sin Oferta',
        'oferta_id' => $oferta_ref ? $oferta_ref->id() : '',
        'estado' => $estado_raw,
        'badge_class' => $badge_class,
        'mentor_nombre' => $mentor_ref ? $mentor_ref->getDisplayName() : 'Sin asignar',
        'evaluador_nombre' => $evaluador_ref ? $evaluador_ref->getDisplayName() : 'Sin asignar',
        'ruta_nombre' => $ruta_ref ? $ruta_ref->label() : 'Sin ruta',
        'url_edit' => Url::fromRoute('entity.node.edit_form', ['node' => $ins->id()])->toString(),
      ];
    }

    // 6. Datos: Tablero 3 - Monitor de Transacciones Auditables FOSFEC
    $trx_query = \Drupal::entityQuery('node')
      ->condition('type', 'fosfec_transaction')
      ->accessCheck(FALSE)
      ->sort('created', 'DESC');

    if (!empty($filter_oferta)) {
      $trx_query->condition('field_oferta_ref', $filter_oferta);
    }
    if (!empty($filter_estado)) {
      $trx_query->condition('field_state', $filter_estado);
    }

    $trx_nids = $trx_query->execute();
    $transacciones = !empty($trx_nids) ? \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($trx_nids) : [];

    $transacciones_data = [];
    foreach ($transacciones as $tx) {
      $empresa = $tx->hasField('field_empresa_beneficiaria') && !$tx->get('field_empresa_beneficiaria')->isEmpty() ? $tx->get('field_empresa_beneficiaria')->entity : NULL;
      $nit = $empresa && $empresa->hasField('field_nit') && !$empresa->get('field_nit')->isEmpty() ? (string) $empresa->get('field_nit')->value : '';

      if (!empty($filter_nit) && strpos($nit, $filter_nit) === FALSE) {
        continue;
      }

      $state = $tx->hasField('field_state') && !$tx->get('field_state')->isEmpty() ? $tx->get('field_state')->value : 'programada';
      $state_badges = [
        'programada' => ['label' => 'Programada', 'class' => 'badge-info'],
        'ejecutada' => ['label' => 'Ejecutada', 'class' => 'badge-primary'],
        'aceptada_digitalmente' => ['label' => 'Aceptada Digitalmente', 'class' => 'badge-warning text-dark'],
        'auditada_fosfec' => ['label' => 'Auditada FOSFEC', 'class' => 'badge-success'],
      ];

      $has_pdf = ($tx->hasField('field_evidencia_documento') && !$tx->get('field_evidencia_documento')->isEmpty());
      $pdf_url = NULL;
      if ($has_pdf) {
        $file = $tx->get('field_evidencia_documento')->entity;
        if ($file) {
          $pdf_url = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
        }
      }

      $horas = $tx->hasField('field_duracion_horas') && !$tx->get('field_duracion_horas')->isEmpty() ? $tx->get('field_duracion_horas')->value : '0';
      $onbase = $tx->hasField('field_onbase_handle') && !$tx->get('field_onbase_handle')->isEmpty() ? $tx->get('field_onbase_handle')->value : NULL;
      $ip = $tx->hasField('field_log_ip_aceptacion') && !$tx->get('field_log_ip_aceptacion')->isEmpty() ? $tx->get('field_log_ip_aceptacion')->value : 'Pendiente';
      $timestamp = $tx->hasField('field_log_timestamp_aceptacion') && !$tx->get('field_log_timestamp_aceptacion')->isEmpty() ? $tx->get('field_log_timestamp_aceptacion')->value : 'Pendiente';

      $transacciones_data[] = [
        'id' => $tx->id(),
        'title' => $tx->label(),
        'empresa_nombre' => $empresa ? $empresa->label() : 'Sin Empresa',
        'empresa_nit' => $nit ?: 'S/N',
        'horas' => $horas,
        'state_label' => $state_badges[$state]['label'] ?? $state,
        'state_class' => $state_badges[$state]['class'] ?? 'badge-secondary',
        'has_pdf' => $has_pdf,
        'pdf_url' => $pdf_url,
        'ip_aceptacion' => $ip,
        'timestamp_aceptacion' => $timestamp,
        'onbase_synced' => !empty($onbase),
        'onbase_handle' => $onbase,
        'url_edit' => Url::fromRoute('entity.node.edit_form', ['node' => $tx->id()])->toString(),
      ];
    }

    // Mentores para modal o selección masiva
    $uids_mentores = \Drupal::entityQuery('user')
      ->condition('status', 1)
      ->condition('roles', ['asesor', 'mentor_consultor'], 'IN')
      ->accessCheck(FALSE)
      ->execute();
    $mentores_list = [];
    if (!empty($uids_mentores)) {
      $mentores_obj = \Drupal::entityTypeManager()->getStorage('user')->loadMultiple($uids_mentores);
      foreach ($mentores_obj as $m) {
        $mentores_list[$m->id()] = $m->getDisplayName();
      }
    }

    // Rutas de Atención (1 a 23)
    $rutas_terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
      'vid' => 'rutas_atencion',
    ]);
    $rutas_list = [];
    foreach ($rutas_terms as $rt) {
      $rutas_list[$rt->id()] = $rt->label();
    }
    // Ordenar rutas naturalmente
    asort($rutas_list, SORT_NATURAL);

    return [
      '#theme' => 'fosfec_dashboard_page',
      '#kpis' => $kpi_block,
      '#active_tab' => $active_tab,
      '#filter_oferta' => $filter_oferta,
      '#filter_estado' => $filter_estado,
      '#filter_nit' => $filter_nit,
      '#filter_desde' => $filter_fecha_desde,
      '#filter_hasta' => $filter_fecha_hasta,
      '#ofertas_options' => $ofertas_options,
      '#ofertas_data' => $ofertas_data,
      '#inscripciones_data' => $inscripciones_data,
      '#transacciones_data' => $transacciones_data,
      '#mentores_list' => $mentores_list,
      '#rutas_list' => $rutas_list,
      '#attached' => [
        'library' => [
          'agilpro_fosfec/agilpro_fosfec_dashboard',
        ],
      ],
    ];
  }

  /**
   * Endpoint AJAX para procesamiento por lotes (VBO) de inscripciones.
   */
  public function bulkProcessInscripciones(Request $request) {
    $action = $request->request->get('bulk_action');
    $ids = $request->request->all('selected_ids');
    $mentor_id = $request->request->get('mentor_id');
    $ruta_id = $request->request->get('ruta_id');
    $nuevo_estado = $request->request->get('nuevo_estado');

    if (empty($ids) || !is_array($ids)) {
      return new \Symfony\Component\HttpFoundation\JsonResponse([
        'success' => FALSE,
        'message' => $this->t('No se seleccionó ninguna inscripción.'),
      ], 400);
    }

    $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($ids);
    $count = 0;

    foreach ($nodes as $node) {
      if ($node->bundle() !== 'inscripcion_oferta') {
        continue;
      }

      if ($action === 'aprobar') {
        $node->set('field_estado_postulacion', 'aprobada');
        $node->save();
        $count++;
      }
      elseif ($action === 'cambiar_estado' && !empty($nuevo_estado)) {
        $node->set('field_estado_postulacion', $nuevo_estado);
        $node->save();
        $count++;
      }
      elseif ($action === 'asignar_mentor' && !empty($mentor_id)) {
        $node->set('field_mentor_asignado', $mentor_id);
        $node->save();
        $count++;
      }
      elseif ($action === 'asignar_ruta' && !empty($ruta_id)) {
        $node->set('field_ruta_asignada', $ruta_id);
        $node->save();
        $count++;
      }
      elseif ($action === 'rechazar') {
        $node->set('field_estado_postulacion', 'rechazada');
        $node->save();
        $count++;
      }
    }

    return new \Symfony\Component\HttpFoundation\JsonResponse([
      'success' => TRUE,
      'count' => $count,
      'message' => $this->t('Operación en lote completada con éxito sobre @count registros.', ['@count' => $count]),
    ]);
  }

}

