<?php

declare(strict_types=1);

namespace Drupal\agilpro_onbase\Exception;

/**
 * Excepción lanzada cuando ocurre un error fatal y definitivo con OnBase.
 *
 * Indica que la tarea no debe seguir reintentándose (e.g. archivo inexistente, 4xx cliente, payload inválido).
 */
final class OnBaseFatalException extends \RuntimeException {}

