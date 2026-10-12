<?php

declare(strict_types=1);

namespace Piston\Salsify\Exception;

use Throwable;

/**
 * Every exception this library throws implements this interface, so an
 * application can catch everything that comes from Salsify without also
 * swallowing its own SPL exceptions.
 */
interface SalsifyException extends Throwable {}
