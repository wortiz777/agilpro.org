<?php

namespace Drupal\agilpro_fosfec\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

/**
 * Servicio para generar la Sábana Auditables FOSFEC en formato Excel (.xlsx).
 */
class FosfecExporterService {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected DateFormatterInterface $dateFormatter;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, DateFormatterInterface $dateFormatter) {
    $this->entityTypeManager = $entityTypeManager;
    $this->dateFormatter = $dateFormatter;
  }

  /**
   * Genera el archivo Excel aplicando los filtros indicados.
   *
   * @param array $filters
   *   Filtros: fecha_inicio, fecha_fin, aliado_id, eje_id, state.
   *
   * @return string
   *   Ruta temporal al archivo generado.
   */
  public function generateSabana(array $filters = []): string {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'fosfec_transaction')
      ->sort('created', 'DESC');

    if (!empty($filters['aliado_id'])) {
      $query->condition('field_aliado_proveedor', $filters['aliado_id']);
    }
    if (!empty($filters['eje_id'])) {
      $query->condition('field_eje_estrategico', $filters['eje_id']);
    }
    if (!empty($filters['state'])) {
      $query->condition('field_state', $filters['state']);
    }

    $nids = $query->execute();
    /** @var \Drupal\node\NodeInterface[] $nodes */
    $nodes = $nids ? $this->entityTypeManager->getStorage('node')->loadMultiple($nids) : [];

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Sábana FOSFEC Compensar');

    // Definición de las 21 columnas auditables (V6 Master Spec)
    $headers = [
      'A1' => 'ID_Transaccion',
      'B1' => 'Oferta_Origen',
      'C1' => 'NIT_Empresa',
      'D1' => 'DV',
      'E1' => 'Razon_Social',
      'F1' => 'Cedula_Asistente',
      'G1' => 'Nombre_Asistente',
      'H1' => 'Cedula_Mentor',
      'I1' => 'Nombre_Mentor',
      'J1' => 'Aliado_Proveedor',
      'K1' => 'Eje_Estrategico',
      'L1' => 'Linea_Intervencion',
      'M1' => 'Ruta_Atencion',
      'N1' => 'Tipo_Servicio',
      'O1' => 'Fecha_Sesion',
      'P1' => 'Horas_Ejecutadas',
      'Q1' => 'Costo_Referencial',
      'R1' => 'Estado_Aceptacion',
      'S1' => 'IP_Registro',
      'T1' => 'Timestamp_Aceptacion',
      'U1' => 'OnBase_Document_Handle',
    ];

    foreach ($headers as $cell => $text) {
      $sheet->setCellValue($cell, $text);
    }

    // Estilo de encabezados
    $headerStyle = [
      'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
      'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0052CC']],
      'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
      'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D0D7DE']]],
    ];
    $sheet->getStyle('A1:U1')->applyFromArray($headerStyle);
    $sheet->getRowDimension(1)->setRowHeight(28);

    $row = 2;
    foreach ($nodes as $node) {
      // 0. Oferta Origen
      $oferta = $node->hasField('field_oferta_ref') && !$node->get('field_oferta_ref')->isEmpty() ? $node->get('field_oferta_ref')->entity : NULL;
      $ofertaOrigen = $oferta ? $oferta->label() : '';

      // 1. Empresa
      $empresa = $node->get('field_empresa_beneficiaria')->entity;
      $nit = $empresa && $empresa->hasField('field_nit') ? $empresa->get('field_nit')->value : '';
      $dv = $empresa && $empresa->hasField('field__dv') ? $empresa->get('field__dv')->value : '';
      $razonSocial = $empresa ? $empresa->label() : '';

      // 2. Asistente
      $asistente = $node->get('field_colaborador_asistente')->entity;
      $cedulaAsistente = $asistente && $asistente->hasField('field_documento_id') ? $asistente->get('field_documento_id')->value : ($asistente ? $asistente->id() : '');
      $nombreAsistente = $asistente ? $asistente->getDisplayName() : '';

      // 3. Mentor
      $mentor = $node->get('field_mentor_consultor')->entity;
      $cedulaMentor = $mentor && $mentor->hasField('field_documento_id') ? $mentor->get('field_documento_id')->value : ($mentor ? $mentor->id() : '');
      $nombreMentor = $mentor ? $mentor->getDisplayName() : '';

      // 4. Aliado
      $aliado = $node->get('field_aliado_proveedor')->entity;
      $nombreAliado = $aliado ? $aliado->label() : '';

      // 5. Taxonomías
      $eje = $node->get('field_eje_estrategico')->entity;
      $linea = $node->get('field_linea_intervencion')->entity;
      $ruta = $node->get('field_ruta_atencion')->entity;

      // 6. Fechas y Horas
      $fechaSesion = '';
      if (!$node->get('field_fecha_hora_sesion')->isEmpty()) {
        $fechaSesion = $node->get('field_fecha_hora_sesion')->value;
      }
      $horas = $node->get('field_duracion_horas')->value ?: '0.00';
      $costo = $node->get('field_costo_referencial')->value ?: '0.00';

      // 7. Aceptación y Auditoría
      $state = $node->get('field_state')->value ?: 'programada';
      $aceptacion = $node->get('field_aceptacion_digital')->value ? 'Aceptada' : 'Pendiente';
      $ip = $node->get('field_log_ip_aceptacion')->value ?: '';
      $timestamp = $node->get('field_log_timestamp_aceptacion')->value ?: '';
      $onbase = $node->get('field_onbase_handle')->value ?: '';

      $sheet->setCellValue('A' . $row, $node->id());
      $sheet->setCellValue('B' . $row, $ofertaOrigen);
      $sheet->setCellValue('C' . $row, $nit);
      $sheet->setCellValue('D' . $row, $dv);
      $sheet->setCellValue('E' . $row, $razonSocial);
      $sheet->setCellValue('F' . $row, $cedulaAsistente);
      $sheet->setCellValue('G' . $row, $nombreAsistente);
      $sheet->setCellValue('H' . $row, $cedulaMentor);
      $sheet->setCellValue('I' . $row, $nombreMentor);
      $sheet->setCellValue('J' . $row, $nombreAliado);
      $sheet->setCellValue('K' . $row, $eje ? $eje->label() : '');
      $sheet->setCellValue('L' . $row, $linea ? $linea->label() : '');
      $sheet->setCellValue('M' . $row, $ruta ? $ruta->label() : '');
      $sheet->setCellValue('N' . $row, $node->get('field_tipo_servicio')->value);
      $sheet->setCellValue('O' . $row, $fechaSesion);
      $sheet->setCellValue('P' . $row, (float) $horas);
      $sheet->setCellValue('Q' . $row, (float) $costo);
      $sheet->setCellValue('R' . $row, $aceptacion . ' (' . $state . ')');
      $sheet->setCellValue('S' . $row, $ip);
      $sheet->setCellValue('T' . $row, $timestamp);
      $sheet->setCellValue('U' . $row, $onbase);

      $row++;
    }

    // Auto-ajustar ancho de columnas
    foreach (range('A', 'U') as $col) {
      $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $tempFile = tempnam(sys_get_temp_dir(), 'fosfec_sabana_') . '.xlsx';
    $writer = new Xlsx($spreadsheet);
    $writer->save($tempFile);

    return $tempFile;
  }

}

