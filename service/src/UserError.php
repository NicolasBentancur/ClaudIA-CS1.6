<?php

declare(strict_types=1);

namespace Claudia;

/**
 * Error esperado cuyo mensaje se le muestra al jugador tal cual (en castellano).
 */
final class UserError extends \RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'error')
    {
        parent::__construct($message);
    }
}
