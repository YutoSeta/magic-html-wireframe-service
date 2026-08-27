<?php

namespace App\Exceptions;

use RuntimeException;

final class IdempotencyStoreUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The idempotency response store is unavailable.');
    }
}
