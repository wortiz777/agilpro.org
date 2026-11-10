<?php

declare(strict_types=1);

namespace Drupal\agilpro_org\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;

/**
 * Obtiene directamente desde las tablas de Webform los datos para los radares.
 *
 * Reemplaza las vistas MySQL heredadas (webform_views_360_*) por consultas
 * directas sobre webform_submission y webform_submission_data.
 */
class WebformRadarDataProvider {

  public function __construct(
    protected readonly Connection $database,
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Obtiene el último envío de Capacidades Emprendedoras para un usuario.
   *
   * @param int $uid
   *   ID del usuario.
   *
   * @return array<string, string>
   *   Mapa nombre_campo => valor, o [] si no existe envío.
   */
  public function getCapacidadesSubmissionByUid(int $uid): array {
    $webformId = (string) ($this->configFactory
      ->get('agilpro_org.settings')
      ->get('webform_ids.capacidades_emprendedoras') ?? 'webform_462');

    return $this->getLatestSubmissionData($webformId, $uid);
  }

  /**
   * Obtiene los últimos envíos de las 5 áreas de 360° Emprendimiento.
   *
   * @param int $uid
   *   ID del usuario.
   *
   * @return array<string, array<string, string>>
   *   Mapa por sección ('addressing', 'administration', 'marketing',
   *   'technical', 'financial') con sus valores.
   */
  public function getEmprendimientoFiveByUid(int $uid): array {
    $config = $this->configFactory->get('agilpro_org.settings');
    $map = [
      'addressing' => (string) ($config->get('webform_ids.direccionamiento') ?? 'webform_121'),
      'administration' => (string) ($config->get('webform_ids.administracion') ?? 'webform_122'),
      'marketing' => (string) ($config->get('webform_ids.mercadeo') ?? 'webform_123'),
      'technical' => (string) ($config->get('webform_ids.tecnico_operativo') ?? 'webform_125'),
      'financial' => (string) ($config->get('webform_ids.financiero') ?? 'webform_127'),
    ];

    $result = [];
    foreach ($map as $section => $webformId) {
      $result[$section] = $this->getLatestSubmissionData($webformId, $uid);
    }

    return $result;
  }

  /**
   * Consulta directamente el último envío de un usuario en un webform dado.
   *
   * @param string $webformId
   *   ID de máquina del webform en Drupal 11 (p. ej. 'webform_462').
   * @param int $uid
   *   ID del usuario.
   *
   * @return array<string, string>
   *   Mapa nombre_elemento => valor.
   */
  public function getLatestSubmissionData(string $webformId, int $uid): array {
    if ($uid <= 0 || $webformId === '') {
      return [];
    }

    $schema = $this->database->schema();
    if (!$schema->tableExists('webform_submission') || !$schema->tableExists('webform_submission_data')) {
      return [];
    }

    $sidQuery = $this->database->select('webform_submission', 'ws')
      ->fields('ws', ['sid'])
      ->condition('ws.webform_id', $webformId)
      ->condition('ws.uid', $uid)
      ->orderBy('ws.sid', 'DESC')
      ->range(0, 1);

    $sid = $sidQuery->execute()?->fetchField();
    if ($sid === FALSE || $sid === NULL) {
      return [];
    }

    $dataQuery = $this->database->select('webform_submission_data', 'wsd')
      ->fields('wsd', ['name', 'property', 'value'])
      ->condition('wsd.sid', (int) $sid);

    $rows = $dataQuery->execute()?->fetchAll() ?? [];
    $result = [];
    foreach ($rows as $row) {
      $key = !empty($row->property) ? (string) $row->property : (string) $row->name;
      $result[$key] = (string) $row->value;
    }

    return $result;
  }

}

