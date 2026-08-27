<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class IdempotencyConflictException extends RuntimeException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('The Idempotency-Key was already used for another request.');
    }
}
