<?php

declare(strict_types=1);

namespace Drupal\agilpro_onbase\Exception;

/**
 * Excepción lanzada cuando ocurre un error recuperable al comunicarse con OnBase.
 *
 * Estas excepciones indican que el worker debe reintentar la tarea (e.g. errores 5xx o timeout).
 */
final class OnBaseRecoverableException extends \RuntimeException {}

