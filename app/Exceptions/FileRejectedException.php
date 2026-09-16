<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Un archivo fue rechazado por FileSecurityScanner antes de tocar disco.
 * $reason es siempre un mensaje seguro para mostrar al usuario final (no
 * expone detalles internos de detección).
 */
class FileRejectedException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct($reason);
    }
}
