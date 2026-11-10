<?php

namespace Drupal\agilpro_fosfec\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generador de archivo .ics descargable para una Oferta.
 */
class OfertaIcalController extends ControllerBase {

  /**
   * Genera y descarga el archivo .ics para la oferta.
   */
  public function download(NodeInterface $node): Response {
    $title = $node->label();
    $description = strip_tags((string) ($node->hasField('field_landing_content') && !$node->get('field_landing_content')->isEmpty() ? $node->get('field_landing_content')->value : $title));
    $description = preg_replace('/\s+/', ' ', substr($description, 0, 300));

    $start_date = date('Ymd');
    $end_date = date('Ymd', strtotime('+30 days'));

    if ($node->hasField('field_fechas_inscripcion') && !$node->get('field_fechas_inscripcion')->isEmpty()) {
      $fechas = $node->get('field_fechas_inscripcion')->first();
      if ($fechas && $fechas->value) {
        $start_date = str_replace('-', '', substr($fechas->value, 0, 10));
      }
      if ($fechas && $fechas->end_value) {
        $end_date = str_replace('-', '', substr($fechas->end_value, 0, 10));
      }
    }

    $now = gmdate('Ymd\THis\Z');
    $uid = 'oferta-' . $node->id() . '@' . \Drupal::request()->getHost();

    $ics = "BEGIN:VCALENDAR\r\n";
    $ics .= "VERSION:2.0\r\n";
    $ics .= "PRODID:-//Compensar FOSFEC//AgilPro Convocatorias//ES\r\n";
    $ics .= "CALSCALE:GREGORIAN\r\n";
    $ics .= "METHOD:PUBLISH\r\n";
    $ics .= "BEGIN:VEVENT\r\n";
    $ics .= "UID:{$uid}\r\n";
    $ics .= "DTSTAMP:{$now}\r\n";
    $ics .= "DTSTART;VALUE=DATE:{$start_date}\r\n";
    $ics .= "DTEND;VALUE=DATE:{$end_date}\r\n";
    $ics .= "SUMMARY:" . addcslashes($title, ",;\\") . "\r\n";
    $ics .= "DESCRIPTION:" . addcslashes($description, ",;\\") . "\r\n";
    $ics .= "LOCATION:Compensar Bogotá - Modalidad Híbrida / Virtual\r\n";
    $ics .= "STATUS:CONFIRMED\r\n";
    $ics .= "END:VEVENT\r\n";
    $ics .= "END:VCALENDAR\r\n";

    $response = new Response($ics);
    $response->headers->set('Content-Type', 'text/calendar; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="oferta-' . $node->id() . '.ics"');

    return $response;
  }

}
