<?php

declare(strict_types=1);

namespace Drupal\agilpro_org\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Controlador para el Portal Privado y Dashboard B2B Mipyme (/mi-empresa/dashboard).
 */
class MiEmpresaDashboardController extends ControllerBase {

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    AccountProxyInterface $currentUser,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user')
    );
  }

  /**
   * Obtiene la empresa vinculada al usuario autenticado actual.
   */
  protected function getCurrentEmpresa(): ?NodeInterface {
    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      return NULL;
    }

    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user) {
      return NULL;
    }

    if ($user->hasField('field_empresa_ref') && !$user->get('field_empresa_ref')->isEmpty()) {
      $empresa = $user->get('field_empresa_ref')->entity;
      if ($empresa instanceof NodeInterface && $empresa->bundle() === 'empresa') {
        return $empresa;
      }
    }

    // Si es administrador o no tiene empresa vinculada, intentar buscar la primera empresa activa
    if ($user->hasRole('administrator') || $user->hasRole('super_admin')) {
      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'empresa')
        ->sort('created', 'DESC')
        ->range(0, 1);
      $nids = $query->execute();
      if (!empty($nids)) {
        return $this->entityTypeManager->getStorage('node')->load(reset($nids));
      }
    }

    return NULL;
  }

  /**
   * Renderiza el Dashboard central / pestañas del portal Mipyme.
   */
  public function dashboard(Request $request): array {
    $empresa = $this->getCurrentEmpresa();
    $active_tab = $request->query->get('tab', 'programas');

    if (!$empresa) {
      return [
        '#markup' => '<div class="container py-5"><div class="alert alert-warning"><h4>' . $this->t('Perfil Empresarial no Vinculado') . '</h4><p>' . $this->t('Su usuario actual no cuenta con una empresa registrada o asociada en el sistema. Si se registró recientemente con NIT, por favor contacte a soporte Compensar o verifique su perfil.') . '</p></div></div>',
      ];
    }

    $user = $this->entityTypeManager->getStorage('user')->load((int) $this->currentUser->id());
    $is_admin = $user->hasRole('admin_empresa') || $user->hasRole('administrator') || ($user->hasField('field_rol_empresa') && $user->get('field_rol_empresa')->value === 'admin_empresa');

    // 1. Datos de Convocatorias / Postulaciones
    $convocatorias_data = $this->getConvocatoriasData($empresa);

    // 2. Datos de Sesiones / Aceptación Digital
    $sesiones_data = $this->getSesionesData($empresa);

    // 3. Formulario de Perfil Empresarial
    $perfil_form = \Drupal::formBuilder()->getForm('Drupal\agilpro_org\Form\EmpresaPerfilForm', $empresa);

    // 4. Datos de Equipo de Colaboradores
    $colaboradores_data = $this->getColaboradoresData($empresa);
    $invite_form = $is_admin ? \Drupal::formBuilder()->getForm('Drupal\agilpro_org\Form\ColaboradorInviteForm', $empresa) : NULL;

    // 5. Historial FOSFEC y Certificados
    $certificados_data = $this->getCertificadosData($empresa);

    return [
      '#theme' => 'mi_empresa_dashboard',
      '#empresa' => [
        'id' => $empresa->id(),
        'nombre' => $empresa->label(),
        'nit' => $empresa->hasField('field_nit') ? $empresa->get('field_nit')->value : '',
        'dv' => $empresa->hasField('field__dv') ? $empresa->get('field__dv')->value : '',
        'ciudad' => $empresa->hasField('field_ciudad_municipio') ? $empresa->get('field_ciudad_municipio')->value : '',
        'email' => $empresa->hasField('field_e_mail_contacto') ? $empresa->get('field_e_mail_contacto')->value : '',
      ],
      '#is_admin_empresa' => $is_admin,
      '#active_tab' => $active_tab,
      '#convocatorias' => $convocatorias_data,
      '#sesiones' => $sesiones_data,
      '#perfil_form' => $perfil_form,
      '#colaboradores' => $colaboradores_data,
      '#invite_form' => $invite_form,
      '#certificados' => $certificados_data,
      '#attached' => [
        'library' => [
          'agilpro_fosfec/agilpro_fosfec_dashboard',
          'agilpro_org/mi_empresa_portal',
        ],
      ],
    ];
  }

  /**
   * Obtiene las postulaciones a ofertas de la empresa.
   */
  protected function getConvocatoriasData(NodeInterface $empresa): array {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'inscripcion_oferta')
      ->condition('field_empresa_ref', $empresa->id())
      ->sort('created', 'DESC');
    $nids = $query->execute();
    $nodes = !empty($nids) ? $this->entityTypeManager->getStorage('node')->loadMultiple($nids) : [];

    $data = [];
    foreach ($nodes as $node) {
      $oferta = $node->hasField('field_oferta_ref') && !$node->get('field_oferta_ref')->isEmpty() ? $node->get('field_oferta_ref')->entity : NULL;
      $ruta = $node->hasField('field_ruta_asignada') && !$node->get('field_ruta_asignada')->isEmpty() ? $node->get('field_ruta_asignada')->entity : NULL;
      $mentor = $node->hasField('field_mentor_asignado') && !$node->get('field_mentor_asignado')->isEmpty() ? $node->get('field_mentor_asignado')->entity : NULL;
      $estado = $node->hasField('field_estado_postulacion') && !$node->get('field_estado_postulacion')->isEmpty() ? $node->get('field_estado_postulacion')->value : 'postulada';

      $badge = match ($estado) {
        'aprobada' => ['class' => 'badge-success', 'label' => 'Aprobada'],
        'en_evaluacion' => ['class' => 'badge-info', 'label' => 'En Evaluación'],
        'rechazada' => ['class' => 'badge-danger', 'label' => 'Rechazada'],
        default => ['class' => 'badge-secondary', 'label' => 'Postulada'],
      };

      $data[] = [
        'id' => $node->id(),
        'oferta_titulo' => $oferta ? $oferta->label() : $node->label(),
        'oferta_url' => $oferta ? \Drupal::service('path_alias.manager')->getAliasByPath('/node/' . $oferta->id()) : '#',
        'ruta' => $ruta ? $ruta->label() : 'Pendiente de Asignación',
        'mentor' => $mentor ? $mentor->getDisplayName() : 'Sin asignar',
        'estado_raw' => $estado,
        'estado_label' => $badge['label'],
        'badge_class' => $badge['class'],
        'fecha' => \Drupal::service('date.formatter')->format($node->getCreatedTime(), 'custom', 'd/m/Y'),
      ];
    }

    return $data;
  }

  /**
   * Obtiene las sesiones transaccionales FOSFEC de la empresa.
   */
  protected function getSesionesData(NodeInterface $empresa): array {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'fosfec_transaction')
      ->condition('field_empresa_beneficiaria', $empresa->id())
      ->sort('created', 'DESC');
    $nids = $query->execute();
    $nodes = !empty($nids) ? $this->entityTypeManager->getStorage('node')->loadMultiple($nids) : [];

    $data = [];
    foreach ($nodes as $node) {
      $mentor = $node->hasField('field_mentor_consultor') && !$node->get('field_mentor_consultor')->isEmpty() ? $node->get('field_mentor_consultor')->entity : NULL;
      $ruta = $node->hasField('field_ruta_atencion') && !$node->get('field_ruta_atencion')->isEmpty() ? $node->get('field_ruta_atencion')->entity : NULL;
      $asistente = $node->hasField('field_colaborador_asistente') && !$node->get('field_colaborador_asistente')->isEmpty() ? $node->get('field_colaborador_asistente')->entity : NULL;
      $horas = $node->hasField('field_duracion_horas') && !$node->get('field_duracion_horas')->isEmpty() ? $node->get('field_duracion_horas')->value : '0';
      $state = $node->hasField('field_state') && !$node->get('field_state')->isEmpty() ? $node->get('field_state')->value : 'programada';

      // Evidencia PDF
      $has_pdf = ($node->hasField('field_evidencia_documento') && !$node->get('field_evidencia_documento')->isEmpty());
      $pdf_url = NULL;
      if ($has_pdf) {
        $file = $node->get('field_evidencia_documento')->entity;
        if ($file) {
          $pdf_url = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
        }
      }

      $fecha = 'Sin fecha';
      if ($node->hasField('field_fecha_hora_sesion') && !$node->get('field_fecha_hora_sesion')->isEmpty()) {
        $fecha = substr($node->get('field_fecha_hora_sesion')->value, 0, 10);
      }

      $is_aceptada = ($state === 'aceptada_digitalmente' || $state === 'auditada_fosfec' || (bool) $node->get('field_aceptacion_digital')->value);

      $data[] = [
        'id' => $node->id(),
        'titulo' => $node->label(),
        'ruta' => $ruta ? $ruta->label() : 'Mentoría General',
        'mentor' => $mentor ? $mentor->getDisplayName() : 'Consultor Asignado',
        'asistente' => $asistente ? $asistente->getDisplayName() : 'Colaborador',
        'horas' => $horas,
        'fecha' => $fecha,
        'state' => $state,
        'has_pdf' => $has_pdf,
        'pdf_url' => $pdf_url,
        'is_aceptada' => $is_aceptada,
        'ip_aceptacion' => $node->hasField('field_log_ip_aceptacion') ? $node->get('field_log_ip_aceptacion')->value : '',
        'timestamp_aceptacion' => $node->hasField('field_log_timestamp_aceptacion') ? $node->get('field_log_timestamp_aceptacion')->value : '',
      ];
    }

    return $data;
  }

  /**
   * Obtiene los colaboradores vinculados a la empresa.
   */
  protected function getColaboradoresData(NodeInterface $empresa): array {
    $uids = $this->entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_empresa_ref', $empresa->id())
      ->sort('created', 'ASC')
      ->execute();
    $users = !empty($uids) ? $this->entityTypeManager->getStorage('user')->loadMultiple($uids) : [];

    $data = [];
    foreach ($users as $u) {
      $rol = $u->hasField('field_rol_empresa') && !$u->get('field_rol_empresa')->isEmpty() ? $u->get('field_rol_empresa')->value : ($u->hasRole('admin_empresa') ? 'admin_empresa' : 'colaborador');
      $cargo = $u->hasField('field_cargo') && !$u->get('field_cargo')->isEmpty() ? $u->get('field_cargo')->value : 'Colaborador';
      $doc = $u->hasField('field_documento_id') && !$u->get('field_documento_id')->isEmpty() ? $u->get('field_documento_id')->value : '-';

      $data[] = [
        'id' => $u->id(),
        'nombre' => $u->getDisplayName(),
        'mail' => $u->getEmail(),
        'documento' => $doc,
        'cargo' => $cargo,
        'rol_empresa' => $rol === 'admin_empresa' ? 'Administrador / Rep. Legal' : 'Colaborador',
        'is_admin' => $rol === 'admin_empresa',
        'status' => $u->isActive() ? 'Activo' : 'Inactivo',
      ];
    }

    return $data;
  }

  /**
   * Obtiene el listado de certificados e intervenciones completadas.
   */
  protected function getCertificadosData(NodeInterface $empresa): array {
    // Buscar sesiones completadas y aceptadas
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'fosfec_transaction')
      ->condition('field_empresa_beneficiaria', $empresa->id())
      ->condition('field_state', ['aceptada_digitalmente', 'auditada_fosfec'], 'IN')
      ->sort('created', 'DESC');
    $nids = $query->execute();
    $nodes = !empty($nids) ? $this->entityTypeManager->getStorage('node')->loadMultiple($nids) : [];

    $data = [];
    foreach ($nodes as $node) {
      $ruta = $node->hasField('field_ruta_atencion') && !$node->get('field_ruta_atencion')->isEmpty() ? $node->get('field_ruta_atencion')->entity : NULL;
      $asistente = $node->hasField('field_colaborador_asistente') && !$node->get('field_colaborador_asistente')->isEmpty() ? $node->get('field_colaborador_asistente')->entity : NULL;
      $horas = $node->hasField('field_duracion_horas') ? $node->get('field_duracion_horas')->value : '0';

      $cod_verificacion = 'CMP-FOSFEC-' . $node->id() . '-' . strtoupper(substr(md5('cert_' . $node->id()), 0, 8));

      $data[] = [
        'id' => $node->id(),
        'programa' => $ruta ? $ruta->label() : $node->label(),
        'asistente' => $asistente ? $asistente->getDisplayName() : 'Colaborador Empresa',
        'horas' => $horas,
        'asistencia_pct' => '100%',
        'codigo_verificacion' => $cod_verificacion,
        'fecha' => \Drupal::service('date.formatter')->format($node->getCreatedTime(), 'custom', 'd/m/Y'),
        'download_url' => Url::fromRoute('agilpro_org.certificado_download', ['node' => $node->id()])->toString(),
      ];
    }

    return $data;
  }

  /**
   * Endpoint AJAX para ejecutar la Aceptación Digital de una sesión FOSFEC.
   */
  public function aceptarSesion(Request $request): JsonResponse {
    $nid = $request->request->get('transaction_id');
    if (!$nid) {
      return new JsonResponse(['success' => FALSE, 'message' => $this->t('ID de sesión no especificado.')], 400);
    }

    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->entityTypeManager->getStorage('node')->load((int) $nid);
    if (!$node || $node->bundle() !== 'fosfec_transaction') {
      return new JsonResponse(['success' => FALSE, 'message' => $this->t('Sesión transaccional inválida.')], 404);
    }

    // Validar aislamiento multi-tenant: la sesión debe pertenecer a la empresa del usuario
    $empresa = $this->getCurrentEmpresa();
    if (!$empresa || ($node->hasField('field_empresa_beneficiaria') && (int) $node->get('field_empresa_beneficiaria')->target_id !== (int) $empresa->id())) {
      return new JsonResponse(['success' => FALSE, 'message' => $this->t('No tiene permisos para aceptar sesiones de otra organización.')], 403);
    }

    $ip = $request->getClientIp() ?: '127.0.0.1';
    $now = gmdate('Y-m-d\TH:i:s');

    // Transición en state_machine
    if ($node->hasField('field_state')) {
      $node->set('field_state', 'aceptada_digitalmente');
    }
    $node->set('field_aceptacion_digital', 1);
    $node->set('field_log_ip_aceptacion', substr($ip, 0, 45));
    $node->set('field_log_timestamp_aceptacion', $now);
    $node->save();

    // Intentar encolar en agilpro_onbase_upload_queue
    try {
      if (\Drupal::hasService('queue')) {
        $queue = \Drupal::queue('agilpro_onbase_upload_queue');
        $queue->createItem([
          'nid' => $node->id(),
          'title' => $node->label(),
          'timestamp' => time(),
          'ip' => $ip,
        ]);
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('agilpro_org')->error('Error encolando transacción OnBase: @msg', ['@msg' => $e->getMessage()]);
    }

    return new JsonResponse([
      'success' => TRUE,
      'message' => $this->t('Aceptación digital confirmada con éxito. Soporte auditado y firmado con IP @ip.', ['@ip' => $ip]),
      'ip' => $ip,
      'timestamp' => $now,
    ]);
  }

  /**
   * Descarga de Certificado de Participación / Cumplimiento FOSFEC en HTML imprimible a PDF.
   */
  public function downloadCertificado(NodeInterface $node): Response {
    $empresa = $this->getCurrentEmpresa();
    if (!$empresa || ($node->hasField('field_empresa_beneficiaria') && (int) $node->get('field_empresa_beneficiaria')->target_id !== (int) $empresa->id())) {
      throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
    }

    $ruta = $node->hasField('field_ruta_atencion') && !$node->get('field_ruta_atencion')->isEmpty() ? $node->get('field_ruta_atencion')->entity : NULL;
    $asistente = $node->hasField('field_colaborador_asistente') && !$node->get('field_colaborador_asistente')->isEmpty() ? $node->get('field_colaborador_asistente')->entity : NULL;
    $mentor = $node->hasField('field_mentor_consultor') && !$node->get('field_mentor_consultor')->isEmpty() ? $node->get('field_mentor_consultor')->entity : NULL;
    $horas = $node->hasField('field_duracion_horas') ? $node->get('field_duracion_horas')->value : '0';
    $cod = 'CMP-FOSFEC-' . $node->id() . '-' . strtoupper(substr(md5('cert_' . $node->id()), 0, 8));

    $html = '
    <!DOCTYPE html>
    <html lang="es">
    <head>
      <meta charset="UTF-8">
      <title>Certificado Compensar FOSFEC - ' . $cod . '</title>
      <style>
        body { font-family: "Helvetica Neue", Arial, sans-serif; margin: 0; padding: 40px; background: #f8fafc; color: #1e293b; }
        .cert-container { max-width: 820px; margin: 0 auto; background: #ffffff; padding: 50px; border: 8px double #e0592a; box-shadow: 0 10px 30px rgba(0,0,0,0.08); text-align: center; }
        .cert-header { margin-bottom: 30px; }
        .logo-comp { font-size: 28px; font-weight: 900; color: #e0592a; letter-spacing: -1px; }
        .cert-title { font-size: 24px; font-weight: 800; color: #002d62; text-transform: uppercase; margin: 25px 0 10px; }
        .cert-subtitle { font-size: 15px; color: #64748b; margin-bottom: 35px; }
        .cert-recipient { font-size: 22px; font-weight: 700; color: #0f172a; margin: 20px 0 5px; border-bottom: 2px solid #e2e8f0; display: inline-block; padding-bottom: 5px; }
        .cert-empresa { font-size: 15px; color: #475569; margin-bottom: 25px; }
        .cert-body { font-size: 15px; line-height: 1.8; color: #334155; margin: 25px 0 40px; text-align: justify; }
        .cert-footer { display: flex; justify-content: space-around; margin-top: 50px; padding-top: 30px; border-top: 1px solid #e2e8f0; }
        .signature-line { width: 200px; border-top: 1px solid #475569; padding-top: 8px; font-size: 12px; font-weight: 600; color: #475569; }
        .cert-badge { font-family: monospace; font-size: 11px; background: #f1f5f9; padding: 6px 12px; border-radius: 4px; display: inline-block; margin-top: 25px; color: #64748b; }
        @media print {
          body { background: #fff; padding: 0; }
          .cert-container { box-shadow: none; border: 4px double #e0592a; }
          .no-print { display: none; }
        }
      </style>
    </head>
    <body>
      <div class="no-print" style="max-width:820px; margin:0 auto 20px; text-align:right;">
        <button onclick="window.print()" style="background:#e0592a; color:#fff; border:none; padding:10px 20px; border-radius:30px; cursor:pointer; font-weight:bold;">🖨 Imprimir / Guardar como PDF</button>
      </div>
      <div class="cert-container">
        <div class="cert-header">
          <div class="logo-comp">compensar</div>
          <div style="font-size:12px; color:#64748b; text-transform:uppercase; letter-spacing:1px; margin-top:4px;">Fondo de Solidaridad de Fomento al Empleo y Protección al Cesante (FOSFEC)</div>
        </div>
        <div class="cert-title">Certificado de Asistencia y Acompañamiento</div>
        <div class="cert-subtitle">Concede la presente constancia a:</div>

        <div class="cert-recipient">' . htmlspecialchars($asistente ? $asistente->getDisplayName() : $empresa->label()) . '</div>
        <div class="cert-empresa">En representación de: <strong>' . htmlspecialchars($empresa->label()) . '</strong> (NIT: ' . htmlspecialchars($empresa->hasField('field_nit') ? (string) $empresa->get('field_nit')->value : '') . ')</div>

        <div class="cert-body">
          Por haber participado y culminado satisfactoriamente la intervención en el marco de la <strong>' . htmlspecialchars($ruta ? $ruta->label() : 'Ruta de Fomento Empresarial') . '</strong>, con una intensidad horaria ejecutada de <strong>' . htmlspecialchars((string) $horas) . ' horas</strong> de mentoría técnica especializada y cumplimiento de asistencia superior al 80%.
        </div>

        <div class="cert-footer">
          <div>
            <div style="height:40px;"></div>
            <div class="signature-line">Coordinación de Fomento Empresarial<br>Compensar Caja de Compensación</div>
          </div>
          <div>
            <div style="height:40px;"></div>
            <div class="signature-line">' . htmlspecialchars($mentor ? $mentor->getDisplayName() : 'Mentor / Consultor FOSFEC') . '<br>Consultoría Técnica Especializada</div>
          </div>
        </div>

        <div class="cert-badge">Código de Verificación Digital: ' . $cod . ' | Fecha Emisión: ' . date('d/m/Y') . '</div>
      </div>
    </body>
    </html>
    ';

    return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
  }

}
