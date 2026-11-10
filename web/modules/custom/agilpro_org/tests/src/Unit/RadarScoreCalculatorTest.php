<?php

declare(strict_types=1);

namespace Drupal\Tests\agilpro_org\Unit;

use Drupal\agilpro_org\Service\RadarScoreCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas unitarias de RadarScoreCalculator.
 *
 * @coversDefaultClass \Drupal\agilpro_org\Service\RadarScoreCalculator
 * @group agilpro_org
 */
final class RadarScoreCalculatorTest extends TestCase {

  private RadarScoreCalculator $calculator;

  protected function setUp(): void {
    parent::setUp();
    $this->calculator = new RadarScoreCalculator();
  }

  /**
   * Verifica que las 35 preguntas de capacidades estén cubiertas sin duplicar.
   */
  public function testCapacidadesQuestionsAreUniqueAndComplete(): void {
    $allFields = [];
    foreach (RadarScoreCalculator::CAPACIDADES_DIMENSIONS as $fields) {
      $this->assertCount(5, $fields);
      foreach ($fields as $field) {
        $allFields[] = $field;
      }
    }

    $this->assertCount(35, $allFields);
    $this->assertCount(35, array_unique($allFields));
  }

  /**
   * Verifica que Pensamiento Sistémico y Amplitud Perceptual sean independientes.
   */
  public function testSystemicThinkingDiffersFromPerceptualAmplitude(): void {
    $data = [];
    foreach (RadarScoreCalculator::CAPACIDADES_DIMENSIONS['perceptual_amplitude'] as $field) {
      $data[$field] = '5';
    }
    foreach (RadarScoreCalculator::CAPACIDADES_DIMENSIONS['systemic_thinking'] as $field) {
      $data[$field . '_full_suffix_above_50_chars'] = '2';
    }

    $scores = $this->calculator->calculateCapacidadesValues($data);
    $this->assertCount(8, $scores);
    // Índice 2 = Amplitud Perceptual (100%), Índice 3 = Pensamiento Sistémico (40%).
    $this->assertSame(100.0, $scores[2]);
    $this->assertSame(40.0, $scores[3]);
  }

  /**
   * Verifica el soporte de sufijos alfanuméricos ("3c", "4d") en Emprendimiento.
   */
  public function testEmprendimientoHandlesAlphanumericValues(): void {
    $sections = [
      'addressing' => array_fill_keys(
        RadarScoreCalculator::EMPRENDIMIENTO_SECTIONS['addressing']['fields'],
        '5a'
      ),
      'administration' => array_fill_keys(
        RadarScoreCalculator::EMPRENDIMIENTO_SECTIONS['administration']['fields'],
        '3b'
      ),
      'marketing' => array_fill_keys(
        RadarScoreCalculator::EMPRENDIMIENTO_SECTIONS['marketing']['fields'],
        '4c'
      ),
      'technical' => array_fill_keys(
        RadarScoreCalculator::EMPRENDIMIENTO_SECTIONS['technical']['fields'],
        '2d'
      ),
      'financial' => array_fill_keys(
        RadarScoreCalculator::EMPRENDIMIENTO_SECTIONS['financial']['fields'],
        '5e'
      ),
    ];

    $scores = $this->calculator->calculateEmprendimientoValues($sections);
    $this->assertSame([100.0, 60.0, 80.0, 40.0, 100.0, 76.0], $scores);
  }

}

