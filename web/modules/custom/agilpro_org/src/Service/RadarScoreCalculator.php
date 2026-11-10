<?php

declare(strict_types=1);

namespace Drupal\agilpro_org\Service;

/**
 * Calcula los puntajes porcentuales para los gráficos radar 360°.
 */
class RadarScoreCalculator {

  /**
   * Mapa corregido de las 7 dimensiones de Capacidades Emprendedoras (35 ítems).
   *
   * Cada ítem incluye el prefijo de 50 caracteres (usado en D7 por MySQL views)
   * y la clave completa del componente en Webform.
   *
   * @var array<string, list<string>>
   */
  public const CAPACIDADES_DIMENSIONS = [
    'be' => [
      '9_desarrolla_sus_proyectos_y_labores_potencializan',
      '13_frente_a_cada_situacion_es_consciente_de_sus_em',
      '16_hace_un_plan_integral_de_desarrollo_de_vida',
      '23_posee_un_marco_claro_de_sus_principios_y_valore',
      '26_reconoce_sus_fortalezas_y_sus_debilidades_y_hac',
    ],
    'creativity' => [
      '4_busca_conocer_otras_opiniones_y_puntos_de_vista',
      '12_explora_nuevas_y_diferentes_formas_de_hacer_las',
      '14_frente_a_las_diferentes_situaciones_visualiza_d',
      '24_produce_ideas_que_lleva_a_acciones_y_resultados',
      '30_se_mantiene_atento_e_informado_de_los_cambios_e',
    ],
    'perceptual_amplitude' => [
      '10_es_para_usted_facil_salirse_de_un_marco_de_acci',
      '11_explora_informacion_de_otros_entornos_relaciona',
      '20_para_la_toma_de_decisiones_tiene_en_cuenta_el_p',
      '21_percibe_con_facilidad_factores_aspectos_o_situa',
      '35_visualiza_mas_oportunidades_que_amenazas',
    ],
    'systemic_thinking' => [
      '1_analiza_situaciones_definiendo_el_contexto_y_los',
      '8_cuando_tiene_un_problema_utiliza_uno_o_varios_me',
      '25_proyecta_y_planea_contemplando_diferentes_escen',
      '29_se_le_facilita_percibir_los_diferentes_aspectos',
      '31_su_plan_de_trabajo_habitual_contempla_un_que_un',
    ],
    'leadership' => [
      '5_con_su_personalidad_inspira_a_los_demas',
      '7_cuando_se_enfrenta_a_situaciones_complejas_contr',
      '18_le_agrada_hablar_en_un_publico',
      '27_se_le_facilita_coordinar_y_dirigir_a_otros_sin_',
      '34_transmite_al_equipo_de_trabajo_constantemente_l',
    ],
    'achievement_orientation' => [
      '2_asume_bien_los_fracasos_y_saca_el_mejor_provecho',
      '15_frente_a_situaciones_dificiles_tiene_la_capacid',
      '19_le_motivan_los_grandes_riesgos',
      '32_sus_expectativas_se_ven_colmadas_al_cumplir_sus',
      '33_tiene_la_capacidad_de_enfrentar_variados_e_inus',
    ],
    'market_orientation' => [
      '3_asume_compromisos_claros_con_sus_clientes_y_los_',
      '6_cuando_recibe_quejas_de_su_producto_o_servicio_l',
      '17_identifica_las_necesidades_antes_de_ofrecer_el_',
      '22_personas_cercanas_a_usted_lo_catalogan_como_una',
      '28_se_le_facilita_generar_contactos_y_redes_en_su_',
    ],
  ];

  /**
   * Campos de las 5 dimensiones del diagnóstico 360° Emprendimiento.
   *
   * @var array<string, array{max: float, fields: list<string>}>
   */
  public const EMPRENDIMIENTO_SECTIONS = [
    'addressing' => [
      'max' => 50.0,
      'fields' => [
        'a1_direccionamiento_a',
        'a_2_direccionamiento_a',
        'a_3_direccionamiento_a',
        'a4_direccionamiento_a',
        'a5_direccionamiento_a',
        'a6_direccionamiento_a',
        'a7_direccionamiento_a',
        'a8_direccionamiento_a',
        'a9_direccionamiento_a',
        'ab10_direccionamiento',
      ],
    ],
    'administration' => [
      'max' => 50.0,
      'fields' => [
        'b1_laempresa',
        'b2_con_el_seguimiento_y_control_',
        'b3_la_empresa_cuenta_',
        'b4_se_tiene_un_proceso',
        'b5_la_empresa_cuenta',
        'b6_la_empresa_busca',
        'b7_la_empresa_estimula',
        'b8_la_empresa_cuenta_con_un_sistema_de_gestion',
        'b9_la_empresa_cuenta_',
        'b10_se_tienen_planificadas',
      ],
    ],
    'marketing' => [
      'max' => 75.0,
      'fields' => [
        '1_mercadeo_a',
        '2_mercadeo_a',
        '3_mercadeo_a',
        '4_lmercadeo',
        '5_mercadeo',
        '6_mercadeo',
        '7_mercadeo',
        '8_mercadeo',
        '9_mercadeo',
        '10_mercadeo',
        '11_mercadeo',
        '12_mercadeo',
        '13_mercadeo',
        '14_mercadeo',
        '15_mercadeo',
      ],
    ],
    'technical' => [
      'max' => 50.0,
      'fields' => [
        '1_gestion',
        '2_gestion',
        '3_gestion',
        '4_gestion',
        '5_gestion',
        '6_gestion',
        '7_gestion',
        '8_gestion',
        '9_gestion',
        '10_gestion',
      ],
    ],
    'financial' => [
      'max' => 75.0,
      'fields' => [
        '1_financiero',
        '2_financiero',
        '33_se_cuenta_con_la_herramienta_que_indica_el_nive',
        '4_financiero',
        '5_financiero',
        '6_financiero',
        '7_financiero',
        '8_financiero',
        '9_financiero',
        '10_financiero',
        '11_financiero',
        '12_financiero',
        '13_financiero',
        '14_financiero',
        '15_financiero',
      ],
    ],
  ];

  /**
   * Calcula los 8 valores porcentuales del radar de Capacidades Emprendedoras.
   *
   * @param array<string, scalar|null> $submissionData
   *   Datos clave => valor del envío de webform.
   *
   * @return list<float>
   *   Los 7 puntajes por dimensión más el promedio general, o [] si está vacío.
   */
  public function calculateCapacidadesValues(array $submissionData): array {
    if ($submissionData === []) {
      return [];
    }

    $normalized = $this->normalizeKeys($submissionData);
    $scores = [];
    foreach (self::CAPACIDADES_DIMENSIONS as $fields) {
      $sum = $this->sumFields($normalized, $fields);
      $scores[] = round(($sum / 25.0) * 100.0, 2, PHP_ROUND_HALF_ODD);
    }

    $average = round(array_sum($scores) / count($scores), 2, PHP_ROUND_HALF_ODD);
    $scores[] = $average;

    return $scores;
  }

  /**
   * Calcula los 6 valores porcentuales del radar 360° Emprendimiento.
   *
   * @param array<string, array<string, scalar|null>> $sectionsData
   *   Mapa indexado por clave de sección ('addressing', 'administration', etc.)
   *   o por índice numérico (0..4) con los datos de cada webform.
   *
   * @return list<float>
   *   Los 5 puntajes por área más el promedio general, o [] si no hay datos.
   */
  public function calculateEmprendimientoValues(array $sectionsData): array {
    if ($sectionsData === []) {
      return [];
    }

    $hasData = FALSE;
    foreach ($sectionsData as $section) {
      if (is_array($section) && $section !== []) {
        $hasData = TRUE;
        break;
      }
    }
    if (!$hasData) {
      return [];
    }

    $sectionKeys = array_keys(self::EMPRENDIMIENTO_SECTIONS);
    $scores = [];

    foreach ($sectionKeys as $index => $sectionKey) {
      $rawRow = $sectionsData[$sectionKey] ?? $sectionsData[$index] ?? [];
      $normalized = is_array($rawRow) ? $this->normalizeKeys($rawRow) : [];
      $config = self::EMPRENDIMIENTO_SECTIONS[$sectionKey];
      $sum = $this->sumFields($normalized, $config['fields']);
      $scores[] = round(($sum / $config['max']) * 100.0, 2, PHP_ROUND_HALF_ODD);
    }

    $average = round(array_sum($scores) / count($scores), 2, PHP_ROUND_HALF_ODD);
    $scores[] = $average;

    return $scores;
  }

  /**
   * Suma de forma segura los valores de campos que pueden incluir sufijos ("1a").
   *
   * @param array<string, scalar|null> $row
   *   Fila normalizada.
   * @param list<string> $fields
   *   Lista de nombres de campo (de hasta 50 caracteres).
   */
  public function sumFields(array $row, array $fields): float {
    if ($row === []) {
      return 0.0;
    }

    $sum = 0.0;
    foreach ($fields as $field) {
      if (!array_key_exists($field, $row) || $row[$field] === NULL) {
        continue;
      }
      $val = $row[$field];
      if (is_int($val) || is_float($val)) {
        $sum += (float) $val;
      }
      elseif (is_Numeric($val)) {
        $sum += (float) $val;
      }
      elseif (is_string($val)) {
        $trimmed = trim($val);
        if (preg_match('/^(\d+(?:\.\d+)?)/', $trimmed, $matches) === 1) {
          $sum += (float) $matches[1];
        }
      }
    }

    return $sum;
  }

  /**
   * Indexa los datos tanto por su clave original como por su prefijo de 50 chars.
   *
   * @param array<string, scalar|null> $data
   *   Datos originales del envío.
   *
   * @return array<string, scalar|null>
   *   Datos con claves normalizadas.
   */
  private function normalizeKeys(array $data): array {
    $normalized = [];
    foreach ($data as $key => $value) {
      $keyStr = (string) $key;
      $normalized[$keyStr] = $value;
      if (strlen($keyStr) > 50) {
        $normalized[substr($keyStr, 0, 50)] = $value;
      }
    }
    return $normalized;
  }

}

