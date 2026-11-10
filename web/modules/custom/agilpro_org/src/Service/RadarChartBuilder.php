<?php

declare(strict_types=1);

namespace Drupal\agilpro_org\Service;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Construye los render arrays de gráficos radar usando el módulo Charts (Chart.js).
 */
class RadarChartBuilder {

  use StringTranslationTrait;

  public function __construct(
    protected readonly WebformRadarDataProvider $dataProvider,
    protected readonly RadarScoreCalculator $calculator,
    TranslationInterface $stringTranslation,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Construye el gráfico radar de 360° Capacidades Emprendedoras para un UID.
   *
   * @param int $uid
   *   ID del usuario.
   *
   * @return array<string, mixed>
   *   Render array con metadatos de caché adjuntos.
   */
  public function buildCapacidadesChart(int $uid): array {
    $cache = new CacheableMetadata();
    $cache->setCacheContexts(['user']);
    $cache->setCacheTags(['webform_submission_list', 'user:' . $uid]);

    $submission = $this->dataProvider->getCapacidadesSubmissionByUid($uid);
    $values = $this->calculator->calculateCapacidadesValues($submission);

    if ($values === []) {
      $build = [];
      $cache->applyTo($build);
      return $build;
    }

    $labels = [
      (string) $this->t('Ser'),
      (string) $this->t('Creatividad e innovación'),
      (string) $this->t('Amplitud Perceptual'),
      (string) $this->t('Pensamiento Sistématico'),
      (string) $this->t('Liderazgo'),
      (string) $this->t('Orientación al logro'),
      (string) $this->t('Orientación al mercado'),
      (string) $this->t('Promedio General Emprendedor'),
    ];

    $build = $this->createRadarRenderArray(
      chartId: 'agilpro_capacidades_radar_' . $uid,
      title: (string) $this->t('Resultado 360° Emprendedor'),
      labels: $labels,
      values: $values,
    );
    $cache->applyTo($build);

    return $build;
  }

  /**
   * Construye el gráfico radar de las 5 áreas de 360° Emprendimiento.
   *
   * @param int $uid
   *   ID del usuario.
   *
   * @return array<string, mixed>
   *   Render array con metadatos de caché adjuntos.
   */
  public function buildEmprendimientoFiveChart(int $uid): array {
    $cache = new CacheableMetadata();
    $cache->setCacheContexts(['user']);
    $cache->setCacheTags(['webform_submission_list', 'user:' . $uid]);

    $sections = $this->dataProvider->getEmprendimientoFiveByUid($uid);
    $values = $this->calculator->calculateEmprendimientoValues($sections);

    if ($values === []) {
      $build = [];
      $cache->applyTo($build);
      return $build;
    }

    $labels = [
      (string) $this->t('Direccionamiento'),
      (string) $this->t('Administración'),
      (string) $this->t('Mercadeo'),
      (string) $this->t('360 Técnico y operativo'),
      (string) $this->t('Financiero'),
      (string) $this->t('Promedio general'),
    ];

    $build = $this->createRadarRenderArray(
      chartId: 'agilpro_emprendimiento_radar_' . $uid,
      title: (string) $this->t('Resultado 360° Emprendimiento'),
      labels: $labels,
      values: $values,
    );
    $cache->applyTo($build);

    return $build;
  }

  /**
   * Construye el bloque dual (Capacidades + Emprendimiento) para un usuario.
   *
   * @param int $uid
   *   ID del usuario objetivo.
   *
   * @return array<string, mixed>
   *   Render array contenedor con ambos radares y metadatos de caché.
   */
  public function buildDualUserCharts(int $uid): array {
    $cache = new CacheableMetadata();
    $cache->setCacheContexts(['user', 'url.path']);
    $cache->setCacheTags(['webform_submission_list', 'user:' . $uid]);

    $capacidadesChart = $this->buildCapacidadesChart($uid);
    $emprendimientoChart = $this->buildEmprendimientoFiveChart($uid);

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['agilpro-dual-radar-charts', 'row']],
    ];

    if (isset($capacidadesChart['#type'])) {
      $build['capacidades'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['agilpro-radar-column', 'col-md-6']],
        'chart' => $capacidadesChart,
      ];
    }

    if (isset($emprendimientoChart['#type'])) {
      $build['emprendimiento'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['agilpro-radar-column', 'col-md-6']],
        'chart' => $emprendimientoChart,
      ];
    }

    $cache->applyTo($build);
    return $build;
  }

  /**
   * Crea el render array estándar del módulo Charts para un gráfico Radar.
   *
   * @param string $chartId
   *   ID único del gráfico.
   * @param string $title
   *   Título de la serie y del gráfico.
   * @param list<string> $labels
   *   Etiquetas de los ejes.
   * @param list<float> $values
   *   Valores porcentuales.
   *
   * @return array<string, mixed>
   *   Render array de tipo 'chart'.
   */
  private function createRadarRenderArray(
    string $chartId,
    string $title,
    array $labels,
    array $values,
  ): array {
    return [
      '#type' => 'chart',
      '#chart_id' => $chartId,
      '#chart_library' => 'chartjs',
      '#chart_type' => 'line',
      '#polar' => TRUE,
      '#title' => $title,
      '#tooltips' => TRUE,
      'series' => [
        '#type' => 'chart_data',
        '#title' => $title,
        '#data' => $values,
        '#color' => '#31708f',
      ],
      'x_axis' => [
        '#type' => 'chart_xaxis',
        '#labels' => $labels,
      ],
      'y_axis' => [
        '#type' => 'chart_yaxis',
        '#min' => 0,
        '#max' => 100,
      ],
    ];
  }

}

